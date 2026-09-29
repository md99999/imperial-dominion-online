<?php
if (!defined('ABSPATH')) exit;

/**
 * The public face of league play: the REST routes other member sites call.
 *
 * **Nothing here is registered unless this site is in a league and the game
 * master has opened the endpoint.** A site playing locally, or opted in but not
 * yet enrolled, exposes no route at all. That is the mitigation with the best
 * ratio in the whole threat model: the surface that does not exist cannot be
 * attacked.
 *
 * Every handler assumes the caller is hostile, because one day one of them will
 * be. Three rules run through all of them:
 *
 * 1. **Answer in generalities.** A handler that explains *why* it refused is an
 *    oracle for whoever is probing it. The detail goes to the local admin log;
 *    the caller gets a bare status and at most a fixed phrase.
 * 2. **Write as little as possible, as late as possible.** Nothing is stored
 *    before the caller has proved something, because storing unverified input is
 *    how an anonymous request becomes a disk-filling write primitive.
 * 3. **Rate limit first.** Before any work, and by address as well as by route.
 *
 * `permission_callback` returns true throughout, and that deserves the comment
 * it has: the caller is another server, not a logged-in user, so there is no
 * cookie and no nonce to check. Proof of identity is the enrolment token or the
 * HMAC, never a WordPress capability. Leaning on WordPress authentication here
 * would mean every peer needed an account on every other site.
 */
class IDO_League_Endpoint {

    const NAMESPACE_V1 = 'ido/v1';

    /** How many calls one address may make to an enrolment route in an hour. */
    const RATE_LIMIT = 30;

    /** Enrolment bodies are tiny. */
    const MAX_BODY = 8192;

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register']);
    }

    /**
     * Registers the routes, or does not.
     *
     * The gate is checked here rather than inside the handlers so that a closed
     * endpoint is a 404 from WordPress itself: there is no handler to reach, no
     * code of ours to run, and nothing that behaves differently from a site that
     * has never heard of this plugin.
     */
    public static function register(): void {
        if (!IDO_League::endpoint_enabled()) return;

        register_rest_route(self::NAMESPACE_V1, '/hello', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'hello'],
            'permission_callback' => '__return_true',   // see the class comment
        ]);
        register_rest_route(self::NAMESPACE_V1, '/join', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'join'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE_V1, '/enrol-status', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'enrol_status'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NAMESPACE_V1, '/packet', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'packet'],
            'permission_callback' => '__return_true',   // the HMAC is the gate
        ]);
    }

    // -- packet ------------------------------------------------------------

    /**
     * Where signed packets arrive.
     *
     * The order of operations is the security property, and it is the whole of
     * this method:
     *
     *   length, peer lookup, signature, parse, schema, stage.
     *
     * Nothing is written before the signature verifies. Logging an unverified
     * body would hand an anonymous caller a write primitive and a way to fill a
     * disk; only counters move before that point.
     *
     * Nothing is *applied* at all. The packet is staged and the cron worker acts
     * on it when its wait is over, which keeps this request to a single row and
     * means a battle cannot resolve half way through an HTTP timeout.
     *
     * A duplicate is answered as success. A sender told "error" retries forever;
     * a sender told "accepted" stops. A replay and a network retry are
     * indistinguishable from here and both deserve the same answer.
     */
    public static function packet(WP_REST_Request $request) {
        if (!self::within_rate_limit('packet')) return self::refuse(429);

        $league = IDO_League::league();
        if (!$league || !IDO_League::active()) return self::refuse(404);

        // Length first, from the header and then from the bytes.
        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($declared > IDO_League_Crypto::MAX_BYTES) return self::refuse(413);

        $wire = trim((string) $request->get_body());
        if ($wire === '' || strlen($wire) > IDO_League_Crypto::MAX_BYTES) return self::refuse(413);

        // Split the envelope to find out which peer claims to have sent this.
        // The claim is worth nothing until the signature agrees with it.
        $parts = IDO_League_Crypto::split($wire);
        if ($parts === null) return self::refuse(400);

        $peer = IDO_League_Queue::peer_by_uuid($parts['from']);
        if (!$peer || (string) $peer->secret === '') {
            // An unknown peer and a bad signature get the same answer, so this
            // cannot be used to discover who a site's peers are.
            return self::refuse(401);
        }

        if (!IDO_League_Crypto::verify($wire, (string) $peer->secret)) return self::refuse(401);

        // Verified. Only now does a parser see these bytes.
        $opened = IDO_League_Crypto::open($wire, (string) $peer->secret);
        if ($opened === null) return self::refuse(400);

        try {
            $envelope = IDO_League_Packet::read($opened, (string) $peer->site_uuid, (string) $league->site_uuid);
        } catch (IDO_Game_Exception $e) {
            self::log(sprintf('Refused a packet from %s: %s', (string) $peer->site_name, $e->getMessage()));
            return self::refuse(422);
        }

        if (!hash_equals((string) $league->league_uuid, (string) $envelope['league'])) {
            return self::refuse(422);
        }

        $result = IDO_League_Queue::stage($peer, $envelope, $wire);
        if ($result === 'error') return self::refuse(500);

        return self::answer(202, ['status' => 'accepted']);
    }

    // -- hello -------------------------------------------------------------

    /**
     * Proves to the hub that whoever is enrolling controls this site.
     *
     * The hub sends a nonce; this answers with an HMAC of that nonce keyed by the
     * enrolment token. Two facts are proved at once, and both are needed:
     *
     * - **Control of the domain**, because the answer comes from the site at the
     *   address being enrolled, reached by the hub's own outbound call.
     * - **Possession of the invitation**, because the proof is keyed by the token.
     *
     * A plain echo would prove only the first, and would make this route a
     * general-purpose reflector for anybody who wanted one. Keying it by the
     * token means a site that holds no invitation cannot answer at all, and a
     * thief who has the token but does not run the site cannot be called back.
     *
     * It also means an attacker cannot enrol somebody else's WordPress site by
     * naming its address: that site has no pending enrolment and no token, so it
     * answers nothing.
     */
    public static function hello(WP_REST_Request $request) {
        if (!self::within_rate_limit('hello')) return self::refuse(429);

        $body = self::read_body($request);
        if ($body === null) return self::refuse(400);

        $league_uuid = self::uuid($body['league'] ?? null);
        $nonce       = self::hex($body['nonce'] ?? null, 64);
        if ($league_uuid === null || $nonce === null) return self::refuse(400);

        $league = IDO_League::league();
        if (!$league
            || !hash_equals((string) $league->league_uuid, $league_uuid)
            || (string) $league->enrol_token === '') {
            // No enrolment in progress for that league, so there is nothing to
            // prove and nothing to say about why.
            return self::refuse(404);
        }

        // The proof and nothing else. This route answers before any trust is
        // established, so it gives away as little as it can: the hub learns this
        // site's name and identity from the enrolment request itself, and
        // repeating them here would mean an unauthenticated caller who guessed a
        // league id could read them too.
        return self::answer(200, [
            'proof' => hash_hmac('sha256', $nonce, (string) $league->enrol_token),
        ]);
    }

    // -- join --------------------------------------------------------------

    /**
     * A site presenting an invitation, on the hub.
     *
     * The order of work matters more than any single check in it. The token is
     * looked up before anything is fetched, the address is validated before it is
     * called, and the member row is written only after the callback has proved
     * the enrolling site is what it claims. An unauthenticated caller can make
     * this site do exactly one outbound request, to an address that has already
     * passed the SSRF rules, and only by presenting a token this site issued.
     */
    public static function join(WP_REST_Request $request) {
        global $wpdb;

        if (!self::within_rate_limit('join')) return self::refuse(429);

        $league = IDO_League::league();
        if (!$league || !IDO_League::is_originator() || IDO_League::paused()) return self::refuse(404);

        $body = self::read_body($request);
        if ($body === null) return self::refuse(400);

        $token     = self::hex($body['token'] ?? null, 64);
        $site_uuid = self::uuid($body['site'] ?? null);
        $site_url  = is_string($body['url'] ?? null) ? IDO_League_URL::normalize($body['url']) : null;
        // is_string first, not a cast. Casting an array to string yields the word
        // "Array" and a PHP warning, and "Array" would then pass the name rules
        // and be stored as this member's name. Type confusion dressed as a
        // convenience.
        $site_name = is_string($body['name'] ?? null)
            ? IDO_League_Packet::clean_name($body['name'])
            : null;

        if ($token === null || $site_uuid === null || $site_url === null || $site_name === null) {
            return self::refuse(400);
        }

        // The token is the only thing that makes this caller worth answering, so
        // it is checked before the address is touched.
        $invite = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('invites')
            . ' WHERE league_id = %d AND token_hash = %s AND used_at IS NULL AND expires_at > %s',
            (int) $league->id, IDO_League_Crypto::token_hash($token), IDO_League::now()
        ));
        if (!$invite) {
            self::log('An enrolment presented a token that is unknown, spent or expired.');
            return self::refuse(403);
        }

        if (IDO_League_Setup::member_count($league) >= (int) $league->max_sites) {
            self::log('An enrolment arrived for a league that is full.');
            return self::refuse(409);
        }
        if (!IDO_League_URL::is_callable_url($site_url)) {
            self::log('An enrolment named an address this site will not call: ' . $site_url);
            return self::refuse(400);
        }
        if (self::is_own_url($site_url)) {
            self::log('An enrolment named this site\'s own address.');
            return self::refuse(400);
        }

        // One address, one member. Without this a second enrolment could claim
        // the address of a site already in the league under a new identity, and
        // the league would hold two rows that disagree about who lives where.
        $taken = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('sites')
            . ' WHERE league_id = %d AND site_url = %s AND site_uuid <> %s AND status <> %s',
            (int) $league->id, $site_url, $site_uuid, 'declined'
        ));
        if ($taken > 0) {
            self::log('An enrolment named an address already in this league: ' . $site_url);
            return self::refuse(409);
        }

        // Prove the caller controls that address and holds that invitation.
        $nonce = bin2hex(random_bytes(32));
        $call  = IDO_League_HTTP::post_json($site_url, 'hello', [
            'league' => (string) $league->league_uuid,
            'nonce'  => $nonce,
        ]);
        $expected = hash_hmac('sha256', $nonce, $token);
        $proof    = is_string($call['data']['proof'] ?? null) ? $call['data']['proof'] : '';

        if (!$call['ok'] || !hash_equals($expected, $proof)) {
            self::log(sprintf('An enrolment from %s failed the callback: %s', $site_url,
                $call['ok'] ? 'the proof did not match' : $call['error']));
            return self::refuse(403);
        }

        // Only now is anything stored, and the row is pending: two administrators
        // agreeing is the point, and an invitation alone is not enough.
        $existing = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . IDO_DB::t('sites') . ' WHERE league_id = %d AND site_uuid = %s',
            (int) $league->id, $site_uuid
        ));
        if (!$existing) {
            $wpdb->insert(IDO_DB::t('sites'), [
                'league_id'  => (int) $league->id,
                'site_uuid'  => $site_uuid,
                'site_name'  => $site_name,
                'site_url'   => $site_url,
                'status'     => 'pending',
                'claimed_at' => IDO_League::now(),
                'created_at' => IDO_League::now(),
            ]);
        }
        $wpdb->update(IDO_DB::t('invites'),
            ['used_by' => $site_url], ['id' => (int) $invite->id], ['%s'], ['%d']);

        self::log(sprintf('%s enrolled from %s and is awaiting approval.', $site_name, $site_url));
        return self::answer(200, ['status' => 'pending']);
    }

    // -- enrol-status ------------------------------------------------------

    /**
     * Where a joining site collects its secret, once a human has approved it.
     *
     * The secret is issued here rather than sent anywhere, and that is the whole
     * reason this route exists. It travels inside the TLS response to a request
     * the joining site made itself, so it never goes through a mailbox, never
     * appears in a link, and is never copied by a person. What a human handles is
     * the one-time token; what the software handles is the long-term key.
     *
     * Issued once. The invitation is spent in the same statement, so a replayed
     * request after approval gets nothing, and a token captured afterwards is
     * worth nothing.
     */
    public static function enrol_status(WP_REST_Request $request) {
        global $wpdb;

        if (!self::within_rate_limit('enrol-status')) return self::refuse(429);

        $league = IDO_League::league();
        if (!$league || !IDO_League::is_originator()) return self::refuse(404);

        $body = self::read_body($request);
        if ($body === null) return self::refuse(400);

        $token     = self::hex($body['token'] ?? null, 64);
        $site_uuid = self::uuid($body['site'] ?? null);
        if ($token === null || $site_uuid === null) return self::refuse(400);

        $hash   = IDO_League_Crypto::token_hash($token);
        $invite = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('invites') . ' WHERE league_id = %d AND token_hash = %s',
            (int) $league->id, $hash
        ));
        $member = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('sites') . ' WHERE league_id = %d AND site_uuid = %s',
            (int) $league->id, $site_uuid
        ));
        if (!$invite || !$member) return self::refuse(403);

        if ((string) $member->status === 'declined') {
            return self::answer(200, ['status' => 'declined']);
        }
        if ((string) $member->status !== 'active') {
            return self::answer(200, ['status' => 'pending']);
        }
        if ($invite->used_at !== null) {
            // Approved, but this secret has already been collected. Saying so
            // plainly is right: the joining site needs to know the difference
            // between "wait" and "you have had it".
            return self::answer(200, ['status' => 'spent']);
        }

        $secret = IDO_League_Crypto::secret();

        // Spend the invitation in a guarded write, and only hand the secret back
        // if this request is the one that spent it. Two simultaneous requests
        // cannot both be issued a secret, and the loser is told to wait.
        $spent = $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('invites') . ' SET used_at = %s WHERE id = %d AND used_at IS NULL',
            IDO_League::now(), (int) $invite->id
        ));
        if ($spent !== 1) return self::answer(200, ['status' => 'spent']);

        $wpdb->update(IDO_DB::t('sites'), [
            'secret'           => $secret,
            'secret_issued_at' => IDO_League::now(),
            'last_contact_at'  => IDO_League::now(),
        ], ['id' => (int) $member->id]);

        self::log(sprintf('Issued a shared secret to %s.', (string) $member->site_name));

        return self::answer(200, [
            'status'      => 'approved',
            'secret'      => $secret,
            'hub'         => (string) $league->site_uuid,
            'league_name' => (string) $league->league_name,
            'ruleset'     => json_decode((string) $league->ruleset, true) ?: [],
            'fingerprint' => (string) $league->fingerprint,
            'calendar'    => [
                'round_starts_at' => (string) $league->round_starts_at,
                'round_days'      => (int) $league->round_days,
                'muster_days'     => (int) $league->muster_days,
                'delay_min_days'  => (int) $league->delay_min_days,
                'delay_max_days'  => (int) $league->delay_max_days,
            ],
        ]);
    }

    // -- shared ------------------------------------------------------------

    /**
     * The request body, or null.
     *
     * Length is checked before anything parses, and the raw body is read rather
     * than WordPress's parsed parameters, so nothing has worked on this input
     * before we decide whether to accept it.
     */
    private static function read_body(WP_REST_Request $request): ?array {
        // The declared length first, before the body is touched. It is only a
        // claim, and the real length is checked immediately after, but refusing
        // on the header means an oversized request is turned away without this
        // code handling its contents at all.
        $declared = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($declared > self::MAX_BODY) return null;

        $raw = (string) $request->get_body();
        if ($raw === '' || strlen($raw) > self::MAX_BODY) return null;

        try {
            $data = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    private static function uuid($value): ?string {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value)
            ? $value : null;
    }

    private static function hex($value, int $length): ?string {
        return is_string($value) && preg_match('/^[0-9a-f]{' . $length . '}$/', $value) ? $value : null;
    }

    private static function is_own_url(string $url): bool {
        $own = IDO_League::own_url();
        return $own !== '' && strcasecmp($own, $url) === 0;
    }

    /**
     * A per-address, per-route budget held in a transient.
     *
     * Counters only: nothing about the request is stored, because writing content
     * from an unauthenticated caller is the thing this is partly here to prevent.
     * A transient is not a precise limiter and does not need to be. It exists so
     * that grinding at these routes costs the attacker something.
     */
    private static function within_rate_limit(string $route): bool {
        $address = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'ido_rl_' . substr(md5($route . '|' . $address), 0, 24);

        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) return false;

        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }

    /** A refusal says nothing a prober can learn from. */
    private static function refuse(int $status) {
        return new WP_REST_Response(['status' => 'refused'], $status);
    }

    private static function answer(int $status, array $data) {
        return new WP_REST_Response($data, $status);
    }

    /** Detail goes here, where only this site's administrator sees it. */
    private static function log(string $message): void {
        IDO_Log::admin('league', $message);
    }
}
