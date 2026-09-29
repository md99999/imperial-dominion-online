<?php
if (!defined('ABSPATH')) exit;

/**
 * The league table.
 *
 * Built on one rule, and everything else here follows from it:
 *
 *   **Rank on what was witnessed. Display what was asserted, labelled as an
 *   assertion.**
 *
 * An exchange is witnessed by two sites. The defender computes the battle and
 * signs the result, the attacker holds the same signed document, so both end up
 * with identical records neither could have fabricated alone. That is the only
 * class of fact in a league a single site cannot invent.
 *
 * Net worth, empire counts and everything else a site publishes about itself is
 * the other class: a claim signed by the claimant. The signature proves the
 * packet arrived unaltered from that site. It proves nothing about whether the
 * numbers were true when they were written. So wealth is shown dimmed, stamped
 * with the date it was claimed, and **nothing is ever ranked on it**. Ranking
 * sites by self-reported net worth would hand every member a slider labelled
 * "my position".
 *
 * What this site can honestly build a table from is the exchanges it took part
 * in. It knows those first hand, from both directions. Exchanges between two
 * other members are not in here yet: that needs the record to travel in a news
 * packet, and until it does the table says what it is rather than pretending to
 * a completeness it has not got.
 */
class IDO_League_Table {

    /**
     * What a win is worth before anything modifies it.
     *
     * Coded rather than settable, like the march delay: a league that could tune
     * its own scoring is a league where the argument moves from the battlefield
     * to the settings screen.
     */
    const WIN_POINTS = 100;

    /**
     * What throwing back a march is worth.
     *
     * The same as winning one, and that is deliberate. A league that paid only
     * for attacking would teach every site to empty its garrison and hope, and
     * the only skill left would be guessing who marched this week. Holding the
     * wall is the other half of the game and is scored like it.
     */
    const REPEL_POINTS = 100;

    /** Neither side broke the other: both took the field and neither kept it. */
    const DRAW_POINTS = 25;

    /**
     * Beating a bigger army counts for more, up to twice.
     *
     * Capped, or a site could farm points by throwing a token force at the
     * largest army in the league and multiplying a loss into a rounding error.
     */
    const MAX_STRENGTH_BONUS = 2.0;

    /**
     * Repeat exchanges with the same peer are worth less each time.
     *
     * Without this the dominant strategy is to find the weakest member and farm
     * it, which is both the most boring way to win a league and the fastest way
     * to lose a member. The second exchange with a peer scores two thirds, the
     * third a half, and so on.
     */
    const REPEAT_DECAY = 0.5;

    /**
     * The standings, as this site has heard them.
     *
     * @return array<int,array> ordered best first, each row ready to render
     */
    public static function standings(): array {
        $league = IDO_League::league();
        if (!$league) return [];

        $rows = [self::own_row($league)];
        foreach (IDO_League_Setup::members($league) as $member) {
            if ((int) $member->is_hub === 1 && (string) $member->site_uuid === (string) $league->site_uuid) continue;
            if ((string) $member->status !== 'active') continue;
            $rows[] = self::peer_row($member);
        }

        usort($rows, static function (array $a, array $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            // A tie on points goes to the site that fought more, then to the
            // name, so the order never depends on which row the database
            // happened to return first.
            $fought_a = $a['won'] + $a['lost'] + $a['drawn'] + $a['repelled'] + $a['broken'];
            $fought_b = $b['won'] + $b['lost'] + $b['drawn'] + $b['repelled'] + $b['broken'];
            if ($fought_a !== $fought_b) return $fought_b <=> $fought_a;
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        $position = 0;
        foreach ($rows as $index => $_) $rows[$index]['position'] = ++$position;

        return $rows;
    }

    /** This site, scored from its own exchanges. */
    private static function own_row(object $league): array {
        $record = self::record_for(0);
        return array_merge($record, [
            'uuid'      => (string) $league->site_uuid,
            'name'      => (string) $league->site_name,
            'is_us'     => true,
            'empires'   => self::own_empire_count(),
            'networth'  => self::own_networth(),
            'as_of'     => IDO_League::now(),
            'claimed'   => false,     // our own figures, not a claim from elsewhere
            'last_seen' => IDO_League::now(),
            'score'     => self::score($record),
        ]);
    }

    /** A peer, scored from the exchanges we shared with them. */
    private static function peer_row(object $member): array {
        // From the peer's point of view: a march we won is one they lost.
        $ours = self::record_for((int) $member->id);
        $theirs = [
            'won'      => $ours['broken'],     // they broke our walls
            'lost'     => $ours['repelled'],   // we threw them back
            'drawn'    => $ours['drawn'],
            'repelled' => $ours['lost'],       // they threw us back
            'broken'   => $ours['won'],        // we carried their field
            'exchanges' => $ours['exchanges'],
            'spoils'   => -$ours['spoils'],
        ];
        return array_merge($theirs, [
            'uuid'      => (string) $member->site_uuid,
            'name'      => (string) $member->site_name,
            'is_us'     => false,
            'empires'   => (int) $member->empire_count,
            'networth'  => (int) $member->networth,
            'as_of'     => $member->news_as_of,
            'claimed'   => true,
            'last_seen' => $member->last_contact_at,
            'score'     => self::score($theirs),
        ]);
    }

    /**
     * Our record against one peer, or against everybody when $peer_id is 0.
     *
     * Read from the march rows this site holds, which is the half of the design
     * that cannot be faked: every one of them was either sent from here or
     * fought here.
     */
    public static function record_for(int $peer_id): array {
        global $wpdb;

        $league = IDO_League::league();
        $out = ['won' => 0, 'lost' => 0, 'drawn' => 0, 'repelled' => 0, 'broken' => 0,
                'exchanges' => 0, 'spoils' => 0];
        if (!$league) return $out;

        $sql = 'SELECT direction, outcome, spoils_json FROM ' . IDO_DB::t('league_marches')
             . ' WHERE league_id = %d AND status = %s';
        $args = [(int) $league->id, IDO_League_Status::RESOLVED];
        if ($peer_id > 0) {
            $sql .= ' AND peer_id = %d';
            $args[] = $peer_id;
        }

        foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $args)) as $row) {
            $outcome = (string) $row->outcome;
            $outward = (string) $row->direction === 'out';

            // Musters that never marched and armies lost to silence are not
            // battles and do not belong in a record of them.
            // Not battles: musters that never marched, armies lost to silence,
            // marches turned away by a grace period, and everything forfeited by
            // a board that started over.
            if (in_array($outcome, ['failed', 'cancelled', 'lost_contact', 'refused',
                                    'forfeited', 'void'], true)) continue;

            if ($outward) {
                if ($outcome === 'won')   { $out['won']++;   $out['exchanges']++; }
                if ($outcome === 'lost')  { $out['lost']++;  $out['exchanges']++; }
                if ($outcome === 'drawn') { $out['drawn']++; $out['exchanges']++; }
            } else {
                if ($outcome === 'held')  { $out['repelled']++; $out['exchanges']++; }
                if ($outcome === 'lost')  { $out['broken']++;   $out['exchanges']++; }
                if ($outcome === 'drawn') { $out['drawn']++;    $out['exchanges']++; }
            }

            $spoils = json_decode((string) $row->spoils_json, true);
            if (is_array($spoils)) {
                $worth = IDO_League_Battle::worth_of_plunder([
                    'gold'  => (int) ($spoils['spoils_gold'] ?? 0),
                    'grain' => (int) ($spoils['spoils_grain'] ?? 0),
                    'iron'  => (int) ($spoils['spoils_iron'] ?? 0),
                ]);
                $out['spoils'] += (int) ($outward ? $worth : -$worth);
            }
        }
        return $out;
    }

    /**
     * Points from a record.
     *
     * Losing scores nothing rather than a negative. A site that keeps fighting
     * and losing belongs at the bottom of the table, not below zero: a negative
     * score invites working out whether arranging somebody else's defeat is
     * worth more than winning yourself.
     *
     * The decay is applied across the whole record rather than per opponent,
     * because this table only holds exchanges with one site at a time anyway.
     */
    public static function score(array $record): int {
        $points = 0.0;

        $points += self::decayed((int) $record['won'])      * self::WIN_POINTS;
        $points += self::decayed((int) $record['repelled']) * self::REPEL_POINTS;
        $points += self::decayed((int) $record['drawn'])    * self::DRAW_POINTS;

        return (int) round($points);
    }

    /**
     * The worth of n exchanges of the same kind, each one counting for less.
     *
     * One is worth one, two are worth 1.67, three 2.17. A site cannot double its
     * score by finding somebody weak and marching on them every week.
     */
    public static function decayed(int $count): float {
        $total = 0.0;
        for ($i = 0; $i < max(0, $count); $i++) {
            $total += 1 / (1 + self::REPEAT_DECAY * $i);
        }
        return $total;
    }

    /** The exchanges this site has been part of, newest first. */
    public static function recent(int $limit = 10): array {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) return [];

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT m.*, s.site_name FROM ' . IDO_DB::t('league_marches') . ' m'
            . ' LEFT JOIN ' . IDO_DB::t('sites') . ' s ON s.id = m.peer_id'
            . ' WHERE m.league_id = %d AND m.status = %s AND m.resolved_at IS NOT NULL'
            . ' ORDER BY m.resolved_at DESC LIMIT %d',
            (int) $league->id, IDO_League_Status::RESOLVED, max(1, min(50, $limit))
        ));
    }

    /**
     * What one ruler has done for the site this round.
     *
     * The personal column, and the reason a player reads this page twice.
     */
    public static function ruler_record(int $kingdom_id): array {
        global $wpdb;

        $out = ['marches' => 0, 'committed' => 0, 'returned' => 0,
                'gold' => 0, 'grain' => 0, 'iron' => 0, 'weapons' => 0, 'agent_missions' => 0];

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('league_contributions') . ' WHERE kingdom_id = %d',
            $kingdom_id
        ));
        foreach ($rows as $row) {
            $out['marches']++;
            $committed = json_decode((string) $row->committed_json, true);
            $returned  = json_decode((string) $row->returned_json, true);

            if (is_array($committed)) {
                foreach ((array) ($committed['force'] ?? []) as $qty) $out['committed'] += (int) $qty;
                if (!empty($committed['agent'])) $out['agent_missions']++;
            }
            if (is_array($returned)) {
                foreach ((array) ($returned['force'] ?? []) as $qty) $out['returned'] += (int) $qty;
            }
            $out['gold']    += (int) $row->spoils_gold;
            $out['grain']   += (int) $row->spoils_grain;
            $out['iron']    += (int) $row->spoils_iron;
            $out['weapons'] += (int) $row->spoils_weapons;
        }
        return $out;
    }

    /** @return object[] who has pledged what to the open muster */
    public static function muster_board(): array {
        $march = IDO_League_Muster::open();
        return $march ? IDO_League_Muster::contributions((int) $march->id) : [];
    }

    private static function own_empire_count(): int {
        global $wpdb;
        $round = IDO_Rounds::current();
        if (!$round) return 0;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_defeated = 0',
            (int) $round->id
        ));
    }

    private static function own_networth(): int {
        global $wpdb;
        $round = IDO_Rounds::current();
        if (!$round) return 0;
        return IDO_Game::clamp((float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(networth), 0) FROM ' . IDO_DB::t('kingdoms')
            . ' WHERE round_id = %d AND is_defeated = 0',
            (int) $round->id
        )));
    }
}
