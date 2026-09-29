<?php
if (!defined('ABSPATH')) exit;

/**
 * The packet queues, in both directions.
 *
 * Nothing in league play happens inside the request that asks for it. A packet
 * is queued and sent by cron; an arriving packet is verified, staged and
 * processed by cron. That is not a performance decision, it is three decisions
 * at once:
 *
 * - **The delay is the mechanic.** A march takes days to land, and the clock
 *   that matters belongs to the receiver, so `process_after` is set here on
 *   arrival and a sender cannot shorten its own attack by lying about when it
 *   sent.
 * - **The endpoint stays cheap.** A request that writes one row and returns is
 *   hard to abuse and cannot time out half way through a battle.
 * - **A peer being slow or down loses nothing.** The outbound table is a retry
 *   queue with backoff, so a packet waits rather than vanishing, and nobody
 *   notices a retry when the delay is measured in days anyway.
 *
 * A future maintainer looking at a multi-day sleep in a queue will reasonably
 * assume it is a bug and try to fix it. It is the feature.
 */
class IDO_League_Queue {

    /** Give up on a peer after this many attempts, and say so in the admin. */
    const MAX_ATTEMPTS = 8;

    /** How many packets one cron tick will move, so a backlog cannot hog a request. */
    const BATCH = 20;

    // -- Outbound ----------------------------------------------------------

    /**
     * Queues a packet for one peer.
     *
     * The envelope is built and signed here, at the moment of queueing, which
     * fixes the sequence number and the timestamp to when the packet was
     * authored rather than when it happened to be sent. A retry therefore sends
     * the identical bytes: the signature stays valid and the receiver recognises
     * a duplicate by its UUID instead of applying it twice.
     */
    public static function enqueue(object $peer, string $type, array $body, int $delay_days = 0): bool {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league || !IDO_League::active()) return false;
        if ((string) $peer->secret === '' || (string) $peer->status !== 'active') return false;

        $seq  = self::next_sequence($peer);
        $uuid = IDO_League_Crypto::uuid();

        $envelope = [
            'v'      => IDO_League_Packet::VERSION,
            'type'   => $type,
            'league' => (string) $league->league_uuid,
            'from'   => (string) $league->site_uuid,
            'to'     => (string) $peer->site_uuid,
            'uuid'   => $uuid,
            'seq'    => $seq,
            'ts'     => time(),
            'fp'     => (string) $league->fingerprint,
            'body'   => $body,
        ];

        try {
            $wire = IDO_League_Crypto::pack($envelope, (string) $league->site_uuid, (string) $peer->secret);
        } catch (IDO_Game_Exception $e) {
            return false;
        }
        if (strlen($wire) > IDO_League_Crypto::MAX_BYTES) return false;

        return (bool) $wpdb->insert(IDO_DB::t('packets_out'), [
            'league_id'   => (int) $league->id,
            'peer_id'     => (int) $peer->id,
            'uuid'        => $uuid,
            'packet_type' => $type,
            'created_at'  => IDO_League::now(),
            'send_after'  => gmdate('Y-m-d H:i:s', time() + max(0, $delay_days) * DAY_IN_SECONDS),
            'status'      => 'queued',
            'payload'     => $wire,
        ]);
    }

    /**
     * Sends what is due, oldest first.
     *
     * Backoff is deliberately coarse: an hour times the number of attempts. A
     * peer that is down for a day should be tried a handful of times, not
     * hammered, and there is nothing in this game that needs to arrive sooner
     * than the next tick.
     */
    public static function flush(): array {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league || !IDO_League::active()) return ['sent' => 0, 'failed' => 0, 'skipped' => 'league inactive'];

        $due = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('packets_out')
            . " WHERE league_id = %d AND status = 'queued' AND send_after <= %s"
            . ' ORDER BY id ASC LIMIT %d',
            (int) $league->id, IDO_League::now(), self::BATCH
        ));

        $sent = 0;
        $failed = 0;
        foreach ($due as $row) {
            $peer = self::peer((int) $row->peer_id);
            if (!$peer || (string) $peer->status !== 'active') {
                self::mark_out($row, 'abandoned', 'That peer is no longer an active member.');
                $failed++;
                continue;
            }

            // Backoff: wait attempts * an hour between tries.
            if ((int) $row->attempts > 0 && $row->last_attempt_at) {
                $wait = (int) $row->attempts * HOUR_IN_SECONDS;
                if (strtotime((string) $row->last_attempt_at . ' UTC') + $wait > time()) continue;
            }

            $call = IDO_League_HTTP::post_text((string) $peer->site_url, 'packet', (string) $row->payload);
            $wpdb->update(IDO_DB::t('packets_out'), [
                'attempts'        => (int) $row->attempts + 1,
                'last_attempt_at' => IDO_League::now(),
            ], ['id' => (int) $row->id]);

            if ($call['ok']) {
                self::mark_out($row, 'sent', '');
                $wpdb->update(IDO_DB::t('sites'), ['last_contact_at' => IDO_League::now()], ['id' => (int) $peer->id]);
                $sent++;
                continue;
            }

            $failed++;
            if ((int) $row->attempts + 1 >= self::MAX_ATTEMPTS) {
                self::mark_out($row, 'failed', $call['error']);
                IDO_Log::admin('league', sprintf(
                    'Gave up sending a %s packet to %s after %d attempts: %s',
                    (string) $row->packet_type, (string) $peer->site_name, self::MAX_ATTEMPTS, $call['error']
                ));
            } else {
                $wpdb->update(IDO_DB::t('packets_out'),
                    ['last_error' => substr($call['error'], 0, 190)], ['id' => (int) $row->id]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => ''];
    }

    // -- Inbound -----------------------------------------------------------

    /**
     * Stages a packet that has already verified.
     *
     * Two properties matter here and both are in the one statement below.
     *
     * The unique index on (peer_id, uuid) is what makes replay impossible rather
     * than unlikely: a duplicate fails the insert, and the caller is told the
     * packet was accepted, because a sender that is told "error" retries forever
     * while a sender told "accepted" stops. A replay attempt and a network retry
     * look identical from the outside, and both deserve the same answer.
     *
     * `process_after` is drawn here, by the receiver. A sender cannot choose when
     * its own packet resolves, and therefore cannot choose what the defender will
     * have standing when it lands.
     *
     * @return string 'staged', 'duplicate' or 'error'
     */
    public static function stage(object $peer, array $envelope, string $wire): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) return 'error';

        $delay = self::delay_days_for((string) $envelope['type']);

        // Suppress the duplicate-key warning: a duplicate is an expected
        // outcome here, not an error worth logging as one.
        $previous = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert(IDO_DB::t('packets_in'), [
            'league_id'     => (int) $league->id,
            'peer_id'       => (int) $peer->id,
            'uuid'          => (string) $envelope['uuid'],
            'packet_type'   => (string) $envelope['type'],
            'sequence'      => (int) $envelope['seq'],
            'received_at'   => IDO_League::now(),
            'process_after' => gmdate('Y-m-d H:i:s', time() + $delay * DAY_IN_SECONDS),
            'status'        => 'staged',
            'payload'       => $wire,
        ]);
        $wpdb->suppress_errors($previous);

        if (!$ok) {
            // Either a duplicate, which is the common case, or a genuine write
            // failure. Both are told apart by looking, not by guessing.
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . IDO_DB::t('packets_in') . ' WHERE peer_id = %d AND uuid = %s',
                (int) $peer->id, (string) $envelope['uuid']
            ));
            return $exists > 0 ? 'duplicate' : 'error';
        }

        $wpdb->update(IDO_DB::t('sites'), [
            'last_contact_at' => IDO_League::now(),
            'fingerprint'     => (string) $envelope['fp'],
        ], ['id' => (int) $peer->id]);

        return 'staged';
    }

    /**
     * How long a packet of this type waits before it is acted on.
     *
     * News waits for nothing: it describes a site's own past, it is the same
     * information the league table shows everybody, and delaying it would only
     * make every site's view of the league staler than it needs to be.
     *
     * War and results are the opposite, and their range is the league's setting
     * rather than a number written here, drawn per packet so an attacker learns
     * nothing from watching.
     */
    private static function delay_days_for(string $type): int {
        // News is public and waits for nothing. A result waits one day, which is
        // the courier ride home and the day a ruler spends knowing the battle is
        // being fought. A march waits the long draw, which is the suspense.
        if ($type === 'news')   return 0;
        if ($type === 'result') return IDO_League::RESULT_DELAY_DAYS;
        return IDO_League::delay_days();
    }

    /**
     * Applies everything whose wait is over.
     *
     * A packet is re-opened from its stored bytes and re-validated rather than
     * trusted because it was verified days ago: the ruleset may have changed, and
     * re-checking costs nothing next to the alternative of applying something
     * this site no longer considers valid.
     */
    public static function process(bool $daily_tick = false): array {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league || !IDO_League::active()) return ['processed' => 0, 'rejected' => 0];

        // A march and a result land on the daily tick and nothing else, which is
        // the whole ritual: you log in, and the dispatches from days ago are
        // waiting. Spreading them across the hourly run would make them arrive
        // whenever, which is more responsive and less of an event.
        //
        // News is exempt. It is a site describing its own past, nobody is waiting
        // on it, and holding it back would only make every member's view of the
        // league staler than it needs to be.
        $types = $daily_tick ? [] : ['news'];

        $sql = 'SELECT * FROM ' . IDO_DB::t('packets_in')
             . " WHERE league_id = %d AND status = 'staged' AND process_after <= %s";
        $args = [(int) $league->id, IDO_League::now()];
        if ($types !== []) {
            $sql .= ' AND packet_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
            $args = array_merge($args, $types);
        }
        $sql .= ' ORDER BY id ASC LIMIT %d';
        $args[] = self::BATCH;

        $due = (array) $wpdb->get_results($wpdb->prepare($sql, $args));

        $processed = 0;
        $rejected = 0;
        foreach ($due as $row) {
            $peer = self::peer((int) $row->peer_id);
            if (!$peer || (string) $peer->secret === '') {
                self::mark_in($row, 'rejected', 'That peer is no longer paired with this site.');
                $rejected++;
                continue;
            }

            $opened = IDO_League_Crypto::open((string) $row->payload, (string) $peer->secret);
            if ($opened === null) {
                // The secret has been rotated, or the row was tampered with in
                // the database. Either way this is not something to apply.
                self::mark_in($row, 'rejected', 'This packet no longer verifies.');
                $rejected++;
                continue;
            }

            try {
                $envelope = IDO_League_Packet::read($opened, (string) $peer->site_uuid, (string) $league->site_uuid);
            } catch (IDO_Game_Exception $e) {
                self::mark_in($row, 'rejected', $e->getMessage());
                $rejected++;
                continue;
            }

            $note = self::apply($peer, $envelope);
            if ($note === '') {
                self::mark_in($row, 'processed', 'Applied.');
                $processed++;
            } else {
                self::mark_in($row, 'rejected', $note);
                $rejected++;
            }
        }

        return ['processed' => $processed, 'rejected' => $rejected];
    }

    /** @return string empty when applied, otherwise why it was not */
    private static function apply(object $peer, array $envelope): string {
        switch ((string) $envelope['type']) {
            case 'news':
                return IDO_League_News::apply($peer, (array) $envelope['body']);
        }
        return sprintf('This build cannot apply a "%s" packet.', (string) $envelope['type']);
    }

    // -- Housekeeping ------------------------------------------------------

    /**
     * The next sequence number for a peer, taken in a guarded write.
     *
     * Incremented in the database rather than read, added to and written back, so
     * two packets queued at the same moment cannot take the same number.
     */
    private static function next_sequence(object $peer): int {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('sites') . ' SET seq_out = seq_out + 1 WHERE id = %d', (int) $peer->id
        ));
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT seq_out FROM ' . IDO_DB::t('sites') . ' WHERE id = %d', (int) $peer->id
        ));
    }

    public static function peer(int $peer_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('sites') . ' WHERE id = %d', $peer_id
        ));
        return $row ?: null;
    }

    /** A peer identified by the uuid on the wire, which is a claim until the signature verifies. */
    public static function peer_by_uuid(string $site_uuid): ?object {
        global $wpdb;
        $league = IDO_League::league();
        if (!$league) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('sites')
            . ' WHERE league_id = %d AND site_uuid = %s AND status = %s',
            (int) $league->id, $site_uuid, 'active'
        ));
        return $row ?: null;
    }

    /** @return object[] every peer this site can send to */
    public static function active_peers(): array {
        global $wpdb;
        $league = IDO_League::league();
        if (!$league) return [];
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('sites')
            . ' WHERE league_id = %d AND status = %s AND secret <> %s',
            (int) $league->id, 'active', ''
        ));
    }

    /**
     * Counts for the admin screen, with the inbound side censored.
     *
     * A staged war packet is not counted and not shown, and this is not
     * over-caution: a defender who can see that something is waiting will
     * reinforce, recall an army or empty the treasury, and the blind exchange
     * that makes a league march worth anything becomes a scheduling exercise.
     * It would not even be cheating, since the number would be sitting on their
     * own dashboard.
     *
     * On most sites the administrator is also a player, so there is no version of
     * this that shows it to one and not the other. Nothing about an unresolved
     * march reaches a person until the daily tick has fought it.
     */
    public static function summary(): array {
        global $wpdb;
        $league = IDO_League::league();
        if (!$league) return [];

        $out = 'SELECT status, COUNT(*) AS n FROM ' . IDO_DB::t('packets_out') . ' WHERE league_id = %d GROUP BY status';
        $in  = 'SELECT status, COUNT(*) AS n FROM ' . IDO_DB::t('packets_in')
             . " WHERE league_id = %d AND NOT (status = 'staged' AND packet_type IN ('war', 'result'))"
             . ' GROUP BY status';

        $tally = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) $out[(string) $row->status] = (int) $row->n;
            return $out;
        };
        return [
            'out' => $tally((array) $wpdb->get_results($wpdb->prepare($out, (int) $league->id))),
            'in'  => $tally((array) $wpdb->get_results($wpdb->prepare($in, (int) $league->id))),
        ];
    }

    private static function mark_out(object $row, string $status, string $error): void {
        global $wpdb;
        $wpdb->update(IDO_DB::t('packets_out'),
            ['status' => $status, 'last_error' => substr($error, 0, 190)], ['id' => (int) $row->id]);
    }

    private static function mark_in(object $row, string $status, string $note): void {
        global $wpdb;
        $wpdb->update(IDO_DB::t('packets_in'), [
            'status'       => $status,
            'processed_at' => IDO_League::now(),
            'result_note'  => substr($note, 0, 500),
        ], ['id' => (int) $row->id]);
    }
}
