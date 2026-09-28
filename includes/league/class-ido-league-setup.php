<?php
if (!defined('ABSPATH')) exit;

/**
 * Founding a league, inviting sites into it, joining one, and leaving.
 *
 * Every method here is reached only from the League screen, which checks
 * manage_options and a nonce first. Nothing in this class is callable by a
 * player, and nothing in it trusts a remote party: an invitation is a claim
 * until the handshake proves otherwise.
 */
class IDO_League_Setup {

    /** An invitation is short-lived on purpose. A stolen one should be worth little. */
    const INVITE_DAYS = 7;

    /**
     * Turns league play on and creates the tables.
     *
     * Separate from founding or joining, because opting in is a decision about
     * machinery and joining is a decision about people. A site can be opted in,
     * see what the screen offers, and never join anything.
     */
    public static function opt_in(): string {
        IDO_Settings::update(['league_enabled' => 1]);
        IDO_League::install_tables();
        IDO_League::forget();
        // Recorded with the acknowledgement, because the admin log already
        // carries who did it and when: the one place a site owner can later
        // see that this was a deliberate act rather than something that
        // arrived with an update.
        IDO_Log::admin('league', 'League play enabled, and the risk acknowledgement accepted.');
        return 'League play is enabled. Found a league, or join one with an invitation.';
    }

    /**
     * Turns league play off.
     *
     * The tables are kept unless the game master asks for them to go, so
     * turning it off and on again does not destroy the record of who this site
     * played. Nothing is sent or accepted while it is off.
     */
    public static function opt_out(bool $drop_tables = false): string {
        IDO_Settings::update(['league_enabled' => 0]);
        if ($drop_tables) {
            IDO_League::drop_tables();
            IDO_Log::admin('league', 'League play disabled and league data removed.');
            return 'League play is off and the league tables have been removed.';
        }
        IDO_League::forget();
        IDO_Log::admin('league', 'League play disabled.');
        return 'League play is off. Nothing will be sent or accepted. The league records are kept.';
    }

    /**
     * Founds a league with this site as originator and hub.
     *
     * The originator owns the ruleset and the calendar, and everything about
     * fairness follows from that: members adopt them rather than each site
     * running its own numbers.
     */
    public static function found(array $in): object {
        global $wpdb;

        $name = sanitize_text_field((string) ($in['league_name'] ?? ''));
        if ($name === '') throw new IDO_Game_Exception('A league needs a name.');

        $own_url = IDO_League::own_url();
        if ($own_url === '') {
            throw new IDO_Game_Exception(
                'This site\'s own address is not one a league can use. League play needs the site '
                . 'to be reachable over HTTPS at a public address.'
            );
        }
        if (IDO_League::league()) throw new IDO_Game_Exception('This site is already in a league.');

        $max_sites = (int) ($in['max_sites'] ?? 12);
        $max_sites = max(2, min(IDO_League::MAX_SITES, $max_sites));

        $muster    = max(1, min(30, (int) ($in['muster_days'] ?? 5)));
        $delay_min = max(0, min(30, (int) ($in['delay_min_days'] ?? 3)));
        $delay_max = max($delay_min, min(30, (int) ($in['delay_max_days'] ?? 8)));
        $round     = max(1, min(365, (int) ($in['round_days'] ?? 90)));

        // The round has to be long enough for an exchange to finish inside it.
        // A league whose round is shorter than the worst case from calling a
        // muster to the army coming home is a league where nobody can fight.
        $round_trip = $muster + 2 * $delay_max;
        if ($round <= $round_trip) {
            throw new IDO_Game_Exception(sprintf(
                'A round of %d days is too short for these delays: calling a muster and getting the '
                . 'army home again takes up to %d days. Lengthen the round or shorten the delays.',
                $round, $round_trip
            ));
        }

        $ruleset = IDO_League::ruleset();
        $now     = IDO_League::now();

        $ok = $wpdb->insert(IDO_DB::t('leagues'), [
            'league_uuid'     => IDO_League_Crypto::uuid(),
            'league_name'     => $name,
            'hub_url'         => $own_url,
            'site_uuid'       => IDO_League_Crypto::uuid(),
            'site_name'       => IDO_League::own_name(),
            'site_url'        => $own_url,
            'is_originator'   => 1,
            'ruleset_version' => 1,
            'ruleset'         => wp_json_encode($ruleset),
            'fingerprint'     => IDO_League::fingerprint($ruleset),
            'round_starts_at' => $now,
            'round_days'      => $round,
            'muster_days'     => $muster,
            'delay_min_days'  => $delay_min,
            'delay_max_days'  => $delay_max,
            'max_sites'       => $max_sites,
            'status'          => 'active',
            'paused'          => 0,
            'created_at'      => $now,
        ]);
        if (!$ok) throw new IDO_Game_Exception('The league could not be created.');

        IDO_League::forget();
        $league = IDO_League::league();
        if (!$league) throw new IDO_Game_Exception('The league was created but could not be read back.');

        IDO_Log::admin('league', sprintf('Founded the league "%s".', $name));
        return $league;
    }

    /**
     * Creates a one-time invitation, and returns the blob to hand to another
     * administrator.
     *
     * **What travels is a token, never the shared secret.** Email is plaintext
     * in transit, gets forwarded, and sits in archives and backups for years; a
     * long-term secret that has been through a mailbox should be considered
     * public. The secret is generated at the end of the handshake and returned
     * over TLS to the joining site's own request, so no human ever copies it.
     *
     * The token is stored hashed. A database read yields no usable invitation.
     */
    public static function invite(string $note = ''): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) throw new IDO_Game_Exception('This site is not in a league.');
        if (!IDO_League::is_originator()) {
            throw new IDO_Game_Exception('Only the site that founded the league can invite others.');
        }
        if (self::member_count($league) >= (int) $league->max_sites) {
            throw new IDO_Game_Exception(sprintf(
                'This league is full at %d sites. Raise the cap before inviting another.',
                (int) $league->max_sites
            ));
        }

        $token = IDO_League_Crypto::token();
        $ok = $wpdb->insert(IDO_DB::t('invites'), [
            'league_id'  => (int) $league->id,
            'token_hash' => IDO_League_Crypto::token_hash($token),
            'note'       => sanitize_text_field($note),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::INVITE_DAYS * DAY_IN_SECONDS),  // UTC, like every league column
            'created_at' => IDO_League::now(),
        ]);
        if (!$ok) throw new IDO_Game_Exception('The invitation could not be created.');

        IDO_Log::admin('league', 'Created an invitation.' . ($note !== '' ? ' For: ' . $note : ''));

        return IDO_League_Crypto::b64_encode((string) wp_json_encode([
            'v'     => 1,
            'lid'   => $league->league_uuid,
            'name'  => $league->league_name,
            'hub'   => $league->hub_url,
            'token' => $token,
        ]));
    }

    /**
     * Reads an invitation blob without trusting a word of it.
     *
     * Everything in here was written by somebody else, so the hub URL is put
     * through the same SSRF checks as any other peer URL, and the names are
     * treated as text to be escaped rather than markup to be rendered.
     *
     * @return array{lid:string,name:string,hub:string,token:string}
     */
    public static function read_invitation(string $blob): array {
        $raw = IDO_League_Crypto::b64_decode(trim($blob));
        if ($raw === null || strlen($raw) > 4096) {
            throw new IDO_Game_Exception('That does not look like an invitation.');
        }

        try {
            $data = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new IDO_Game_Exception('That invitation could not be read.');
        }
        if (!is_array($data)) throw new IDO_Game_Exception('That invitation could not be read.');

        $lid   = (string) ($data['lid'] ?? '');
        $hub   = (string) ($data['hub'] ?? '');
        $token = (string) ($data['token'] ?? '');
        // The same rule a name in a packet faces, rather than a second, looser
        // one here. A league name that is not name-shaped is replaced with a
        // placeholder rather than rejecting the whole invitation: the name is
        // decoration, the league id and the hub are what matter.
        $name  = (string) IDO_League_Packet::clean_name((string) ($data['name'] ?? ''));

        if (!preg_match('/^[0-9a-f-]{36}$/', $lid))   throw new IDO_Game_Exception('That invitation is not for a league this version understands.');
        if (!preg_match('/^[0-9a-f]{64}$/', $token))  throw new IDO_Game_Exception('That invitation carries no usable token.');

        $hub = IDO_League_URL::normalize($hub);
        if ($hub === null) {
            throw new IDO_Game_Exception('That invitation points somewhere this site will not call. A league hub must be an HTTPS address.');
        }
        if (!IDO_League_URL::is_callable_url($hub)) {
            throw new IDO_Game_Exception('That invitation points at an address on this network rather than the public internet, and has been refused.');
        }

        return ['lid' => $lid, 'name' => $name === '' ? 'an unnamed league' : $name, 'hub' => $hub, 'token' => $token];
    }

    /**
     * Records the intention to join, ready for the handshake.
     *
     * Deliberately two steps. This writes down what an invitation claims; it
     * does not make this site a member, because membership is settled by the
     * hub calling back to prove this site controls its own address and by the
     * originator approving the enrolment. Until that has happened the league
     * row sits as `pending` and nothing is sent or accepted.
     */
    public static function join(string $blob): object {
        global $wpdb;

        if (IDO_League::league()) throw new IDO_Game_Exception('This site is already in a league.');

        $own_url = IDO_League::own_url();
        if ($own_url === '') {
            throw new IDO_Game_Exception(
                'This site\'s own address is not one a league can use. A member has to be reachable '
                . 'over HTTPS at a public address, because the hub calls back to prove you control it.'
            );
        }

        $invitation = self::read_invitation($blob);
        $now = IDO_League::now();

        $ok = $wpdb->insert(IDO_DB::t('leagues'), [
            'league_uuid'   => $invitation['lid'],
            'league_name'   => $invitation['name'],
            'hub_url'       => $invitation['hub'],
            'site_uuid'     => IDO_League_Crypto::uuid(),
            'site_name'     => IDO_League::own_name(),
            'site_url'      => $own_url,
            'is_originator' => 0,
            'status'        => 'pending',
            'paused'        => 1,
            'created_at'    => $now,
        ]);
        if (!$ok) throw new IDO_Game_Exception('The enrolment could not be recorded.');

        $league_id = (int) $wpdb->insert_id;
        $wpdb->insert(IDO_DB::t('sites'), [
            'league_id'  => $league_id,
            'site_uuid'  => '',
            'site_name'  => $invitation['name'],
            'site_url'   => $invitation['hub'],
            'is_hub'     => 1,
            'status'     => 'pending',
            'created_at' => $now,
        ]);

        IDO_League::forget();
        IDO_Log::admin('league', sprintf('Recorded an enrolment in "%s", awaiting the handshake.', $invitation['name']));

        return (object) ['league_id' => $league_id] ;
    }

    /**
     * Leaves the league.
     *
     * Records are kept. An army in escrow is not this method's business yet and
     * must not be silently destroyed when the march code exists: in-flight
     * results are honoured, or the escrow times out and the troops come home.
     */
    public static function leave(): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) return 'This site is not in a league.';

        $wpdb->update(IDO_DB::t('leagues'), ['status' => 'left', 'paused' => 1], ['id' => (int) $league->id]);
        IDO_League::forget();
        IDO_Log::admin('league', sprintf('Left the league "%s".', $league->league_name));

        return 'This site has left the league. Local war returns at the next round boundary.';
    }

    /** The kill switch: stops sending and accepting without deactivating anything. */
    public static function set_paused(bool $paused): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) return 'This site is not in a league.';

        $wpdb->update(IDO_DB::t('leagues'), ['paused' => $paused ? 1 : 0], ['id' => (int) $league->id]);
        IDO_League::forget();
        IDO_Log::admin('league', $paused ? 'League traffic paused.' : 'League traffic resumed.');

        return $paused
            ? 'League traffic is paused. Nothing is sent or accepted until you resume.'
            : 'League traffic has resumed.';
    }

    public static function member_count(object $league): int {
        global $wpdb;
        return 1 + (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('sites') . ' WHERE league_id = %d AND is_hub = 0 AND status = %s',
            (int) $league->id, 'active'
        ));
    }

    /** @return object[] */
    public static function members(object $league): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('sites') . ' WHERE league_id = %d ORDER BY is_hub DESC, site_name ASC',
            (int) $league->id
        ));
    }

    /** @return object[] invitations that can still be used */
    public static function open_invites(object $league): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id, note, expires_at, created_at FROM ' . IDO_DB::t('invites')
            . ' WHERE league_id = %d AND used_at IS NULL AND expires_at > %s ORDER BY id DESC',
            (int) $league->id, IDO_League::now()
        ));
    }

    public static function revoke_invite(int $invite_id): string {
        global $wpdb;
        $league = IDO_League::league();
        if (!$league || !IDO_League::is_originator()) return 'Only the originator can revoke an invitation.';

        $wpdb->delete(IDO_DB::t('invites'), ['id' => $invite_id, 'league_id' => (int) $league->id]);
        return 'That invitation has been revoked and can no longer be used.';
    }
}
