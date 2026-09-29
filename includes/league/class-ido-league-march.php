<?php
if (!defined('ABSPATH')) exit;

/**
 * A march: sending one, receiving one, and settling what comes home.
 *
 * Four moments, on two sites, days apart:
 *
 *   1. The muster closes here and the army leaves. A war packet goes out.
 *   2. It arrives there, waits three to six days, and is fought. The defender
 *      applies every consequence to itself and sends a result back.
 *   3. The result arrives here and waits a day. That day is the one where a
 *      ruler sees the army in battle: the dispatch is in hand and not yet read.
 *   4. The daily tick opens it. Survivors and spoils go back to the rulers who
 *      risked something, in proportion to what each of them risked.
 *
 * Nothing in a war packet is trusted for its effect. It says what left; the
 * defender decides what it achieves. Nothing in a result packet is trusted
 * either: everything it asserts is clamped to what this site actually escrowed,
 * so a hostile defender reporting that the attacker lost nine times what they
 * sent cannot create troops out of a lie, and one reporting spoils of a billion
 * cannot mint gold.
 */
class IDO_League_March {

    /**
     * How long an army waits for a result before it is assumed lost and returned.
     *
     * Derived from the delays rather than written down: the longest march out,
     * the day the dispatches take to ride home, and a margin for a peer that is
     * down for a while. Too short and a slow peer causes the same army to be
     * both returned and settled; too long and an army is hostage to a dead site.
     *
     * Releasing the escrow is idempotent and keyed on the march, so a result
     * arriving after the timeout finds the army already home and does nothing.
     */
    public static function escrow_timeout_days(): int {
        return IDO_League::DELAY_MAX_DAYS + IDO_League::RESULT_DELAY_DAYS + 7;
    }

    // -- Sending -----------------------------------------------------------

    /** The muster has closed and met its minimum: the army leaves. */
    public static function send(object $march, array $assembled): string {
        global $wpdb;

        $peer = IDO_League_Queue::peer((int) $march->peer_id);
        if (!$peer) {
            IDO_League_Muster::return_all((int) $march->id);
            return 'That peer is no longer in the league. The army stood down.';
        }

        $agent_id = (int) $march->agent_kingdom_id;
        $queued = IDO_League_Queue::enqueue($peer, 'war', [
            'march'   => (string) ($march->packet_uuid ?: IDO_League_Crypto::uuid()),
            'force'   => (object) $assembled['force'],
            'weapons' => (object) $assembled['weapons'],
            'agent'   => $agent_id > 0,
        ]);

        if (!$queued) {
            IDO_League_Muster::return_all((int) $march->id);
            $wpdb->update(IDO_DB::t('league_marches'),
                ['status' => IDO_League_Status::RESOLVED, 'outcome' => 'failed',
                 'resolved_at' => IDO_League::now(),
                 'report' => 'The packet could not be sent and the army stood down.'],
                ['id' => (int) $march->id]);
            return 'The war packet could not be queued. Every pledge was returned.';
        }

        // The uuid of the packet just queued is what the result will quote, so
        // the two can be matched when it comes home days later.
        $uuid = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT uuid FROM ' . IDO_DB::t('packets_out')
            . ' WHERE league_id = %d AND peer_id = %d ORDER BY id DESC LIMIT 1',
            (int) $march->league_id, (int) $peer->id
        ));

        $wpdb->update(IDO_DB::t('league_marches'), [
            'status'      => IDO_League_Status::MARCHING,
            'packet_uuid' => $uuid,
            'sent_at'     => IDO_League::now(),
            'force_json'  => wp_json_encode($assembled),
        ], ['id' => (int) $march->id]);

        IDO_Log::news('league', sprintf(
            'The army has marched on %s: %s. They will be gone some days.',
            $peer->site_name,
            IDO_League_Muster::describe_force($assembled['force'], $assembled['weapons'])
        ));

        return sprintf('The army has marched on %s.', $peer->site_name);
    }

    // -- Receiving a march -------------------------------------------------

    /**
     * A war packet whose wait is over. Fight it, and send the result back.
     *
     * This is the first moment anybody on this site learns that a march was
     * coming, which is the point: the defence is whatever happened to be standing,
     * not what somebody reinforced after reading a warning.
     *
     * @return string empty when applied, otherwise why not
     */
    public static function receive_war(object $peer, array $body): string {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) return 'This site is not in a league.';

        // A site under a grace period is not marched on. The force goes home
        // intact: the attacker committed days before the reset happened and
        // should lose the turns and the time, but not the army.
        if (IDO_Board::under_grace()) {
            IDO_League_Queue::enqueue($peer, 'result', self::refusal_result((string) $body['march']));
            return '';
        }

        $force   = self::whole_numbers((array) $body['force']);
        $weapons = self::whole_numbers((array) $body['weapons']);
        $result  = IDO_League_Battle::resolve($peer, $force, $weapons, !empty($body['agent']));

        $round = IDO_Rounds::current();
        $wpdb->insert(IDO_DB::t('league_marches'), [
            'league_id'   => (int) $league->id,
            'peer_id'     => (int) $peer->id,
            'round_id'    => $round ? (int) $round->id : 0,
            'direction'   => 'in',
            'status'      => IDO_League_Status::RESOLVED,
            'packet_uuid' => (string) $body['march'],
            'sent_at'     => IDO_League::now(),
            'joined_at'   => IDO_League::now(),
            'resolved_at' => IDO_League::now(),
            // Recorded from the defender's point of view: they held, they did
            // not, or neither side could break the other.
            'outcome'     => $result['outcome'] === 'drawn' ? 'drawn'
                             : ($result['outcome'] === 'won' ? 'lost' : 'held'),
            'force_json'  => wp_json_encode(['force' => $force, 'weapons' => $weapons]),
            'spoils_json' => wp_json_encode($result),
            'created_at'  => IDO_League::now(),
        ]);

        IDO_League_Queue::enqueue($peer, 'result', array_merge($result, [
            'march'       => (string) $body['march'],
            'resolved_at' => IDO_League::now(),
        ]));

        self::announce_defence($peer, $result);
        return '';
    }

    // -- Receiving a result ------------------------------------------------

    /**
     * The dispatches, opened. Survivors and spoils go back to their owners.
     *
     * Every number here came from another site, so every number is clamped to
     * what this site actually escrowed before it is applied. Survivors cannot
     * exceed what was sent, and spoils are capped at what the ruleset makes
     * possible, because "apply the smaller of what they claim and what we believe"
     * is the only safe way to read a stranger's arithmetic.
     *
     * @return string empty when applied, otherwise why not
     */
    public static function receive_result(object $peer, array $body): string {
        global $wpdb;

        $march = self::by_packet_uuid((string) $body['march'], (int) $peer->id);
        if (!$march) return 'No march here matches that dispatch.';

        if ((string) $march->status === IDO_League_Status::RESOLVED) {
            return '';   // already settled, or already timed out and returned
        }

        $contributions = IDO_League_Muster::contributions((int) $march->id);
        if (!$contributions) {
            self::finish($march, (string) $body['outcome'], 'Nobody was left to settle with.');
            return '';
        }

        $committed = json_decode((string) $march->force_json, true);
        $sent_force   = is_array($committed) ? (array) ($committed['force'] ?? []) : [];
        $sent_weapons = is_array($committed) ? (array) ($committed['weapons'] ?? []) : [];

        // Clamp: a survivor that never marched does not come home.
        $survivors = self::clamp_to((array) $body['survivors'], $sent_force);
        $train     = self::clamp_to((array) $body['weapons_home'], $sent_weapons);

        $spoils = [
            'gold'  => self::clamp_spoil((int) $body['spoils_gold']),
            'grain' => self::clamp_spoil((int) $body['spoils_grain']),
            'iron'  => self::clamp_spoil((int) $body['spoils_iron']),
        ];
        $weapons_won = max(0, min(100000, (int) $body['spoils_weapons']));

        // Two different weightings, and conflating them was a real bug the test
        // caught: a ruler who sent centurions was drawing a share of somebody
        // else's surviving legionnaires and coming home with more of them than
        // they had ever owned.
        //
        // **Survivors come back to whoever sent them**, unit by unit. A ruler who
        // pledged nine hundred legionnaires gets back their own legionnaires less
        // the casualty rate, and has no claim at all on a type they never sent.
        //
        // **Spoils are shared by what each ruler risked**, measured the way the
        // game measures an army, because plunder belongs to the effort rather
        // than to any particular soldier.
        $risk = [];
        $per_unit = [];
        $per_weapon = [];
        foreach ($contributions as $row) {
            $kingdom_id = (int) $row->kingdom_id;
            $c = json_decode((string) $row->committed_json, true);
            $their_force   = is_array($c) ? (array) ($c['force'] ?? []) : [];
            $their_weapons = is_array($c) ? (array) ($c['weapons'] ?? []) : [];

            $risk[$kingdom_id] = IDO_Units::offence_power($their_force);
            foreach ($their_force as $key => $qty)   $per_unit[$key][$kingdom_id] = (int) $qty;
            foreach ($their_weapons as $key => $qty) $per_weapon[$key][$kingdom_id] = (int) $qty;
        }

        $survivor_shares = [];
        foreach ($survivors as $key => $total) {
            foreach (IDO_League_Share::split((int) $total, $per_unit[$key] ?? []) as $kid => $share) {
                $survivor_shares[$kid][$key] = $share;
            }
        }
        $train_shares = [];
        foreach ($train as $key => $total) {
            foreach (IDO_League_Share::split((int) $total, $per_weapon[$key] ?? []) as $kid => $share) {
                $train_shares[$kid][$key] = $share;
            }
        }
        $spoil_shares  = IDO_League_Share::split_map($spoils, $risk);
        $weapon_shares = IDO_League_Share::split($weapons_won, $risk);

        foreach ($contributions as $row) {
            $kingdom_id = (int) $row->kingdom_id;
            $kingdom = IDO_Kingdom::find($kingdom_id);
            if (!$kingdom) continue;   // deleted mid-march: their share is forfeit

            $deltas = [];
            foreach ((array) ($survivor_shares[$kingdom_id] ?? []) as $key => $qty) {
                if (IDO_Units::exists($key) && $qty > 0) $deltas[IDO_Units::column($key)] = (int) $qty;
            }
            foreach ((array) ($train_shares[$kingdom_id] ?? []) as $key => $qty) {
                if (IDO_Weapons::exists($key) && $qty > 0) $deltas[IDO_Weapons::column($key)] = (int) $qty;
            }
            foreach (['gold', 'grain', 'iron'] as $resource) {
                $amount = (int) ($spoil_shares[$kingdom_id][$resource] ?? 0);
                if ($amount > 0) $deltas[$resource] = ($deltas[$resource] ?? 0) + $amount;
            }
            $engines = (int) ($weapon_shares[$kingdom_id] ?? 0);
            if ($engines > 0) {
                $first = IDO_Weapons::keys()[0] ?? '';
                if ($first !== '') $deltas[IDO_Weapons::column($first)] = $engines;
            }

            if ($deltas) IDO_Kingdom::pay($kingdom, $deltas);

            $wpdb->update(IDO_DB::t('league_contributions'), [
                'returned_json' => wp_json_encode([
                    'force'   => $survivor_shares[$kingdom_id] ?? [],
                    'weapons' => $train_shares[$kingdom_id] ?? [],
                ]),
                'spoils_gold'    => (int) ($spoil_shares[$kingdom_id]['gold'] ?? 0),
                'spoils_grain'   => (int) ($spoil_shares[$kingdom_id]['grain'] ?? 0),
                'spoils_iron'    => (int) ($spoil_shares[$kingdom_id]['iron'] ?? 0),
                'spoils_weapons' => $engines,
                'settled_at'     => IDO_League::now(),
            ], ['id' => (int) $row->id]);

            IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));
        }

        self::settle_agent($march, (string) $body['agent'], $peer);
        self::finish($march, (string) $body['outcome'], self::report($body, $peer));
        self::announce_return($peer, (string) $body['outcome'], $spoils, $weapons_won);

        return '';
    }

    // -- The army that never came home -------------------------------------

    /**
     * Returns an army whose result never arrived.
     *
     * Losing an army to a network failure is worse than the small chance of
     * settling one twice, and settling twice is prevented anyway: the escrow is
     * released against the march row, and a result arriving afterwards finds it
     * resolved and does nothing.
     */
    public static function release_timed_out(): int {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league) return 0;

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::escrow_timeout_days() * DAY_IN_SECONDS);
        $stale = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('league_marches')
            . ' WHERE league_id = %d AND direction = %s AND status IN (%s, %s) AND sent_at IS NOT NULL'
            . ' AND sent_at < %s',
            (int) $league->id, 'out', IDO_League_Status::MARCHING, IDO_League_Status::IN_BATTLE, $cutoff
        ));

        foreach ($stale as $march) {
            $returned = IDO_League_Muster::return_all((int) $march->id);
            self::finish($march, 'lost_contact', sprintf(
                'No dispatches came. After %d days the army was assumed lost and what remained of it '
                . 'came home. %d force(s) returned.', self::escrow_timeout_days(), $returned
            ));
            IDO_Log::news('league', 'An army given up for lost has straggled home. No word of the battle ever came.');
        }
        return count($stale);
    }

    // -- Shared ------------------------------------------------------------

    public static function by_packet_uuid(string $uuid, int $peer_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('league_marches')
            . ' WHERE packet_uuid = %s AND peer_id = %d AND direction = %s LIMIT 1',
            $uuid, $peer_id, 'out'
        ));
        return $row ?: null;
    }

    /** Marks a march finished, whatever finished means for it. */
    private static function finish(object $march, string $outcome, string $report): void {
        global $wpdb;
        $wpdb->update(IDO_DB::t('league_marches'), [
            'status'      => IDO_League_Status::RESOLVED,
            'outcome'     => substr($outcome, 0, 16),
            'joined_at'   => $march->joined_at ?: IDO_League::now(),
            'resolved_at' => IDO_League::now(),
            'report'      => substr($report, 0, 500),
        ], ['id' => (int) $march->id]);
    }

    /** The agent who rode ahead, and what became of him. */
    private static function settle_agent(object $march, string $outcome, object $peer): void {
        $kingdom_id = (int) $march->agent_kingdom_id;
        if ($kingdom_id < 1 || $outcome === 'none') return;

        global $wpdb;
        $wpdb->update(IDO_DB::t('league_marches'),
            ['agent_outcome' => substr($outcome, 0, 16)], ['id' => (int) $march->id]);

        $success = $outcome === 'success';
        $hanged  = $outcome === 'hanged';

        IDO_League_Covert::record((int) $march->round_id, $kingdom_id, (string) $peer->site_name, $success, $hanged);

        // An agent who was not hanged walks home with the army. One who was is
        // gone: he left the empire when he was pledged and never comes back.
        if (!$hanged) {
            $kingdom = IDO_Kingdom::find($kingdom_id);
            if ($kingdom) IDO_Kingdom::pay($kingdom, ['agents' => 1]);
        }
    }

    /** A result that fights nothing, for a march that arrived during a grace period. */
    private static function refusal_result(string $march_uuid): array {
        return [
            'march'          => $march_uuid,
            'outcome'        => 'refused',
            'survivors'      => (object) [],
            'weapons_home'   => (object) [],
            'spoils_gold'    => 0,
            'spoils_grain'   => 0,
            'spoils_iron'    => 0,
            'spoils_weapons' => 0,
            'defender_dead'  => 0,
            'agent'          => 'none',
            'resolved_at'    => IDO_League::now(),
        ];
    }

    /** Only whole, positive counts of things this game has. */
    private static function whole_numbers(array $map): array {
        $out = [];
        foreach ($map as $key => $qty) {
            if (!is_string($key) || !is_int($qty) || $qty < 1) continue;
            $out[$key] = min($qty, (int) IDO_Game::MAX_VALUE);
        }
        return $out;
    }

    /** Nothing comes home that did not march. */
    private static function clamp_to(array $claimed, array $sent): array {
        $out = [];
        foreach ($sent as $key => $qty) {
            $home = (int) ($claimed[$key] ?? 0);
            $out[$key] = max(0, min($home, (int) $qty));
        }
        return $out;
    }

    /** A spoil is a number from a stranger, so it is bounded before it is believed. */
    private static function clamp_spoil(int $amount): int {
        return max(0, min($amount, (int) IDO_Game::MAX_VALUE));
    }

    /** The report a ruler reads, composed here from numbers rather than sent as text. */
    private static function report(array $body, object $peer): string {
        if ((string) $body['outcome'] === 'refused') {
            return sprintf('%s was rebuilding under a grace period and would not give battle. '
                . 'The army turned around and came home intact.', $peer->site_name);
        }
        if ((string) $body['outcome'] === 'drawn') {
            return sprintf(
                'Neither side could break the other at %s. The field was held until dark and the army '
                . 'withdrew in order, leaving %s of the enemy dead and taking nothing.',
                $peer->site_name, IDO_Game::fmt((int) $body['defender_dead'])
            );
        }
        $won = (string) $body['outcome'] === 'won';
        return sprintf(
            '%s %s. %s dead among their ranks. Carried off: %s gold, %s grain, %s iron and %s siege weapon(s).',
            $won ? 'The army carried the field at' : 'The army was thrown back from',
            $peer->site_name,
            IDO_Game::fmt((int) $body['defender_dead']),
            IDO_Game::fmt((int) $body['spoils_gold']),
            IDO_Game::fmt((int) $body['spoils_grain']),
            IDO_Game::fmt((int) $body['spoils_iron']),
            IDO_Game::fmt((int) $body['spoils_weapons'])
        );
    }

    private static function announce_defence(object $peer, array $result): void {
        if ($result['outcome'] === 'drawn') {
            IDO_Log::news('league', sprintf(
                '%s marched on us and neither side could break the other. They withdrew at dark, '
                . 'and we buried our dead.', $peer->site_name));
            return;
        }
        IDO_Log::news('league', $result['outcome'] === 'won'
            ? sprintf('%s marched on us and carried the field. The granaries and treasuries are lighter.',
                $peer->site_name)
            : sprintf('%s marched on us and was thrown back from the walls.', $peer->site_name));
    }

    private static function announce_return(object $peer, string $outcome, array $spoils, int $weapons): void {
        if ($outcome === 'refused') return;

        if ($outcome === 'drawn') {
            IDO_Log::news('league', sprintf(
                'The army is home from %s with nothing but its dead. Neither side could break the other.',
                $peer->site_name));
            return;
        }

        IDO_Log::news('league', $outcome === 'won'
            ? sprintf('The army is home from %s in triumph, with %s gold, %s grain, %s iron and %s engines.',
                $peer->site_name, IDO_Game::fmt($spoils['gold']), IDO_Game::fmt($spoils['grain']),
                IDO_Game::fmt($spoils['iron']), IDO_Game::fmt($weapons))
            : sprintf('What is left of the army is home from %s. The field was not ours.', $peer->site_name));
    }
}
