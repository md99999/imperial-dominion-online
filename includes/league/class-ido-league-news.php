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
    public static function compose(): array {
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

        $body = self::compose();
        $queued = 0;
        foreach ($peers as $peer) {
            if (IDO_League_Queue::enqueue($peer, 'news', $body)) $queued++;
        }
        if ($queued > 0) {
            IDO_Log::admin('league', sprintf('Queued news for %d peer site(s).', $queued));
        }
        return ['queued' => $queued];
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
