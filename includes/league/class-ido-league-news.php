<?php
if (!defined('ABSPATH')) exit;

/**
 * News packets: a site describing its own past.
 *
 * This is the smallest useful thing two paired sites can exchange, and it is
 * deliberately the first one built. It proves the queue, the delay, the
 * signature, the schema and the cron worker all fit together, and it does it
 * with information that cannot hurt anybody if it goes astray.
 *
 * **What a news packet carries:** how many empires are playing, what the site is
 * worth in total, what its largest empire is worth, whether it is accepting
 * marches, and the moment it read its own database.
 *
 * **What it never carries:** player names, per-empire figures, and anything at
 * all about musters, marches in flight or intentions. A league table built from
 * these must not become an early-warning system, or the blind commitment that
 * makes a march interesting stops existing. A site describes its past here, never
 * its present and never its plans.
 *
 * **What it is worth:** these numbers are a claim signed by the claimant. The
 * signature proves the packet came from that site unaltered and proves nothing
 * whatever about whether the numbers are true. They are stored with the date they
 * were claimed and shown as claims, and no ranking is ever built on them.
 */
class IDO_League_News {

    /**
     * This site's own figures, for sending.
     *
     * Summed as floats and clamped, like every other total in this game, so a
     * board with absurd numbers on it reports a large number rather than
     * overflowing into a negative one.
     */
    public static function compose(?object $peer = null): array {
        global $wpdb;

        $league = IDO_League::league();
        $round  = IDO_Rounds::current();

        $count = 0;
        $total = 0.0;
        $largest = 0.0;
        $round_day = 0;

        if ($round) {
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                'SELECT networth FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_defeated = 0',
                (int) $round->id
            ));
            foreach ($rows as $row) {
                $worth = max(0.0, (float) $row->networth);
                $count++;
                $total += $worth;
                if ($worth > $largest) $largest = $worth;
            }
            if ($round->starts_at) {
                $started = strtotime((string) $round->starts_at);
                if ($started) $round_day = max(0, (int) floor((time() - $started) / DAY_IN_SECONDS));
            }
        }

        $body = [
            'site_name'        => IDO_League::own_name(),
            'round_id'         => $round ? (int) $round->id : 0,
            'round_day'        => min(100000, $round_day),
            'empire_count'     => min(100000, $count),
            'networth'         => IDO_Game::clamp($total),
            'largest_networth' => IDO_Game::clamp($largest),
            'accepting'        => $league !== null && IDO_League::active() && !IDO_Board::under_grace(),
            'as_of'            => IDO_League::now(),
        ];

        // What this site believes about the pairing's counters. The peer compares
        // them with its own view, and a disagreement is how either side finds out
        // it has been restored from a backup: nothing inside a database can notice
        // that the database went backwards, because the noticing would go back
        // with it. Only the other end remembers.
        if ($peer !== null) {
            $body['seq_seen'] = (int) $peer->seq_in;    // the highest we have had from them
            $body['seq_sent'] = (int) $peer->seq_out;   // the highest we have sent them
        }

        // A site rebuilding says so, so peers can see not to march on it rather
        // than finding out days later when their army is turned away at the gate.
        $grace = IDO_Board::grace_until();
        if ($grace !== null && strtotime($grace . ' UTC') > time()) {
            $body['grace_until'] = $grace;
        }

        return $body;
    }

    /** Queues this site's figures to every paired peer. */
    public static function broadcast(): array {
        $peers = IDO_League_Queue::active_peers();
        if (!$peers) return ['queued' => 0];

        $queued = 0;
        foreach ($peers as $peer) {
            // Composed per peer rather than once, because the counters describe a
            // pairing rather than this site.
            if (IDO_League_Queue::enqueue($peer, 'news', self::compose($peer))) $queued++;
        }
        if ($queued > 0) {
            IDO_Log::admin('league', sprintf('Queued news for %d peer site(s).', $queued));
        }
        return ['queued' => $queued];
    }

    /**
     * Compares a peer's view of the pairing with ours, and raises the alarm.
     *
     * Two disagreements matter, and they are different faults:
     *
     * **They have seen more from us than we have sent.** Our counter went
     * backwards, which only happens when our database did. This is the dangerous
     * one, because if the counter rolled back so did the record of what we have
     * already applied, and the next retry from any peer will be applied twice.
     *
     * **They have sent more than we have received.** Either packets were lost in
     * transit, which the retry queue will fix on its own, or our record of what we
     * received went backwards, which is the same restore seen from the other side.
     * Treated as a warning rather than a halt: a genuine gap is ordinary and the
     * queue heals it.
     *
     * Neither claim is trusted for anything except becoming more careful. A peer
     * that lies here can make us stop and ask a human, which is a denial of service
     * worth having over the alternative of a game quietly counting things twice.
     */
    private static function check_counters(object $peer, array $body): void {
        if (isset($body['seq_seen'])) {
            $seen_by_them = (int) $body['seq_seen'];
            if ($seen_by_them > (int) $peer->seq_out) {
                IDO_League::catch_up_sequence($peer, $seen_by_them);
                IDO_League::flag_resync(sprintf(
                    '%s has seen packet %d from this site, but this site has only issued %d. '
                    . 'That means this database is older than the one the league has been talking to, '
                    . 'which usually means a backup was restored.',
                    (string) $peer->site_name, $seen_by_them, (int) $peer->seq_out
                ));
            }
        }

        if (isset($body['seq_sent'])) {
            $sent_by_them = (int) $body['seq_sent'];
            $gap = $sent_by_them - (int) $peer->seq_in;
            // A small gap is packets still in flight or waiting on a retry. A large
            // one is a hole in the record.
            if ($gap > 5) {
                IDO_Log::admin('league', sprintf(
                    '%s reports sending %d packets; this site has recorded %d. %d may be missing.',
                    (string) $peer->site_name, $sent_by_them, (int) $peer->seq_in, $gap
                ));
            }
        }
    }

    /**
     * Records a peer's figures.
     *
     * The body has already been through the schema, so every field is the right
     * type and inside its range. What is left to decide here is whether to
     * believe this packet over what is already on file, and the answer is
     * whichever was written later.
     *
     * That matters because packets can arrive out of order. The queue retries
     * with backoff and a peer can be offline for a day, so yesterday's news can
     * easily land after today's. Applying it anyway would quietly move a peer's
     * numbers backwards, and the league table would show a site shrinking for
     * reasons nobody could explain.
     *
     * @return string empty when applied, otherwise why not
     */
    public static function apply(object $peer, array $body): string {
        global $wpdb;

        // Before anything else, and deliberately before the staleness gate below.
        // The counters describe the conversation rather than the news, so they are
        // worth reading off a packet whose figures are too old to use: a peer
        // saying it once saw packet 41 proves we once issued 41, however long ago
        // it said so. Putting this after the gate would have meant a restored site
        // ignoring the very packets most likely to carry the evidence, because a
        // site catching up floods us with retries and most of them are stale.
        self::check_counters($peer, $body);

        $as_of = strtotime((string) $body['as_of'] . ' UTC');
        if ($as_of === false) return 'That news packet carried no usable date.';

        if ($peer->news_as_of) {
            $held = strtotime((string) $peer->news_as_of . ' UTC');
            if ($held !== false && $as_of <= $held) {
                return '';   // older than what is on file: applied as a no-op, not an error
            }
        }

        $wpdb->update(IDO_DB::t('sites'), [
            'site_name'        => (string) $body['site_name'],
            'empire_count'     => (int) $body['empire_count'],
            'networth'         => (int) $body['networth'],
            'largest_networth' => (int) $body['largest_networth'],
            'grace_until'      => isset($body['grace_until']) ? (string) $body['grace_until'] : null,
            'news_as_of'       => gmdate('Y-m-d H:i:s', $as_of),
            'last_contact_at'  => IDO_League::now(),
        ], ['id' => (int) $peer->id]);

        return '';
    }
}
