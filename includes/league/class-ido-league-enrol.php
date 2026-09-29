<?php
if (!defined('ABSPATH')) exit;

/**
 * The joining side of the handshake, and the originator's approval of it.
 *
 * Enrolment is four steps on purpose, and the shape is the security design
 * rather than ceremony:
 *
 *   1. An administrator pastes an invitation. Nothing leaves the site.
 *   2. This site presents the token to the hub. The hub calls back to prove this
 *      site controls the address it claims and holds the invitation.
 *   3. A human on the hub approves the member. An invitation alone is not
 *      enough: two administrators agreeing is the point.
 *   4. This site collects the shared secret over TLS, inside the response to a
 *      request it made itself.
 *
 * What a person handles is a one-time token that expires. What the software
 * handles is the long-term key. The two are never the same thing, and the key
 * never goes through a mailbox, a link or a copy and paste.
 */
class IDO_League_Enrol {

    /**
     * Step 2: present the invitation to the hub and let it call us back.
     *
     * The callback is the part worth understanding. The hub asks this site to
     * prove itself, and the proof travels over a connection the *hub* opened to
     * the address being enrolled, so nobody can enrol a site they do not run.
     */
    public static function present(): string {
        $league = IDO_League::league();
        if (!$league) throw new IDO_Game_Exception('This site is not enrolling in a league.');
        if ((string) $league->status !== 'pending') throw new IDO_Game_Exception('This site has already joined.');
        if ((string) $league->enrol_token === '') {
            throw new IDO_Game_Exception('This enrolment has no invitation left to present. Start again with a fresh invitation.');
        }

        // The hub is about to call back, so the endpoint has to be open. Saying
        // this now is far kinder than letting the callback fail and reporting
        // that the hub refused us.
        if (!IDO_League::endpoint_enabled()) {
            throw new IDO_Game_Exception(
                'The hub has to be able to call this site back, and incoming packets are switched off. '
                . 'Turn them on under Settings, then present the invitation again.'
            );
        }

        $own_url = IDO_League::own_url();
        if ($own_url === '') {
            throw new IDO_Game_Exception('This site\'s own address is not one a league can use.');
        }

        $call = IDO_League_HTTP::post_json((string) $league->hub_url, 'join', [
            'token' => (string) $league->enrol_token,
            'site'  => (string) $league->site_uuid,
            'url'   => $own_url,
            'name'  => (string) $league->site_name,
        ]);

        if (!$call['ok']) {
            IDO_Log::admin('league', 'Presenting the invitation failed: ' . $call['error']);
            throw new IDO_Game_Exception(self::explain($call));
        }

        IDO_Log::admin('league', 'Presented the invitation to the hub; awaiting approval.');
        return 'The hub has accepted the invitation and called this site back successfully. '
             . 'It is now waiting for the league\'s originator to approve this site.';
    }

    /**
     * Step 4: ask the hub whether approval has happened, and collect the secret.
     *
     * Safe to call repeatedly: the hub issues the secret once and says `spent`
     * afterwards, so a second press cannot quietly replace a working key with a
     * new one that the hub no longer has.
     */
    public static function collect(): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) throw new IDO_Game_Exception('This site is not enrolling in a league.');
        if ((string) $league->status === 'active') return 'This site has already joined the league.';
        if ((string) $league->enrol_token === '') {
            throw new IDO_Game_Exception('This enrolment has no invitation left to present.');
        }

        $call = IDO_League_HTTP::post_json((string) $league->hub_url, 'enrol-status', [
            'token' => (string) $league->enrol_token,
            'site'  => (string) $league->site_uuid,
        ]);
        if (!$call['ok']) throw new IDO_Game_Exception(self::explain($call));

        $status = is_string($call['data']['status'] ?? null) ? $call['data']['status'] : '';

        if ($status === 'pending') {
            return 'The originator has not approved this site yet. Try again later.';
        }
        if ($status === 'declined') {
            throw new IDO_Game_Exception('The originator has declined this enrolment.');
        }
        if ($status === 'spent') {
            throw new IDO_Game_Exception(
                'The hub has already issued the secret for this invitation and cannot issue it again. '
                . 'Ask the originator for a fresh invitation.'
            );
        }
        if ($status !== 'approved') {
            throw new IDO_Game_Exception('The hub gave an answer this site does not understand.');
        }

        // Everything below came from the hub, which is a remote party. A hub can
        // set the rules; it cannot be trusted to send well-formed anything.
        $secret = $call['data']['secret'] ?? null;
        if (!is_string($secret) || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
            throw new IDO_Game_Exception('The hub did not send a usable shared secret.');
        }
        $hub_uuid = $call['data']['hub'] ?? null;
        if (!is_string($hub_uuid)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $hub_uuid)) {
            throw new IDO_Game_Exception('The hub did not identify itself properly.');
        }

        $calendar    = is_array($call['data']['calendar'] ?? null) ? $call['data']['calendar'] : [];
        $ruleset     = is_array($call['data']['ruleset'] ?? null) ? $call['data']['ruleset'] : [];
        $fingerprint = $call['data']['fingerprint'] ?? '';
        $fingerprint = is_string($fingerprint) && preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? $fingerprint : '';

        // A hub that pushes absurd numbers is refused by the member rather than
        // obeyed: range-checked here, the same way a ruleset arriving in a packet
        // would be.
        $days   = self::bounded($calendar['round_days'] ?? 0, 1, 365, 90);
        $muster = self::bounded($calendar['muster_days'] ?? 0, 1, 30, 5);

        $wpdb->update(IDO_DB::t('sites'), [
            'site_uuid'        => $hub_uuid,
            'secret'           => $secret,
            'secret_issued_at' => IDO_League::now(),
            'status'           => 'active',
            'last_contact_at'  => IDO_League::now(),
        ], ['league_id' => (int) $league->id, 'is_hub' => 1]);

        // The ruleset is stored, not applied. League settings take effect at the
        // next round boundary, because changing turns a day or training costs
        // under players who planned around them is unfair in a way that has
        // nothing to do with cheating.
        $wpdb->update(IDO_DB::t('leagues'), [
            'status'         => 'active',
            'paused'         => 0,
            'enrol_token'    => '',
            'ruleset'        => wp_json_encode($ruleset),
            'fingerprint'    => $fingerprint,
            'round_days'     => $days,
            'muster_days'    => $muster,
            'delay_min_days' => IDO_League::DELAY_MIN_DAYS,
            'delay_max_days' => IDO_League::DELAY_MAX_DAYS,
        ], ['id' => (int) $league->id]);

        IDO_League::forget();
        IDO_Log::admin('league', 'Enrolment complete; the shared secret has been stored.');

        return 'This site has joined the league. The shared secret is stored and the league\'s '
             . 'ruleset will take effect at the next round boundary.';
    }

    /** The originator admitting a member that has proved itself. */
    public static function approve(int $member_id): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league || !IDO_League::is_originator()) {
            throw new IDO_Game_Exception('Only the site that founded the league can approve a member.');
        }
        $member = self::member($league, $member_id);
        if ((string) $member->status === 'active') return 'That site is already a member.';

        if (IDO_League_Setup::member_count($league) >= (int) $league->max_sites) {
            throw new IDO_Game_Exception('This league is full. Raise the cap before approving another site.');
        }

        $wpdb->update(IDO_DB::t('sites'), ['status' => 'active'], ['id' => (int) $member->id]);
        IDO_Log::admin('league', sprintf('Approved %s as a league member.', (string) $member->site_name));

        return sprintf(
            '%s is approved. It will collect its shared secret the next time it checks, and nothing '
            . 'is sent to it until it has.', (string) $member->site_name
        );
    }

    /** The originator refusing one. */
    public static function decline(int $member_id): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league || !IDO_League::is_originator()) {
            throw new IDO_Game_Exception('Only the site that founded the league can decline a member.');
        }
        $member = self::member($league, $member_id);

        // Declining destroys the secret rather than leaving it usable, which is
        // the same rule eviction follows: nothing it sends will verify again.
        $wpdb->update(IDO_DB::t('sites'),
            ['status' => 'declined', 'secret' => '', 'secret_issued_at' => null],
            ['id' => (int) $member->id]
        );
        IDO_Log::admin('league', sprintf('Declined the enrolment from %s.', (string) $member->site_name));

        return sprintf('%s has been declined and its secret destroyed.', (string) $member->site_name);
    }

    private static function member(object $league, int $member_id): object {
        global $wpdb;
        $member = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('sites') . ' WHERE id = %d AND league_id = %d AND is_hub = 0',
            $member_id, (int) $league->id
        ));
        if (!$member) throw new IDO_Game_Exception('No such member site.');
        return $member;
    }

    private static function bounded($value, int $min, int $max, int $fallback): int {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) return $fallback;
        $value = (int) $value;
        return $value < $min || $value > $max ? $fallback : $value;
    }

    /**
     * A peer's failure, in words an administrator can act on.
     *
     * The hub answers in generalities on purpose, so this fills in the likely
     * cause rather than passing along a bare status nobody can do anything with.
     */
    private static function explain(array $call): string {
        switch ((int) $call['status']) {
            case 403: return 'The hub refused the invitation. It may be spent or expired, or the '
                           . 'callback to this site failed: the hub has to be able to reach '
                           . IDO_League::own_url() . ' over HTTPS.';
            case 409: return 'The league is full.';
            case 429: return 'The hub is refusing repeated attempts. Wait a while before trying again.';
            case 404: return 'The hub is not accepting enrolments. It may have league play paused, or '
                           . 'may not be the league\'s originator.';
            case 0:   return 'This site could not reach the hub: ' . $call['error'];
        }
        return 'The hub refused: ' . $call['error'];
    }
}
