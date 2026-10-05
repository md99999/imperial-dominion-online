<?php
if (!defined('ABSPATH')) exit;

/**
 * The economy runs on turns, not on the clock: a turn spent is a day of your
 * empire's life, and it pays out the moment you spend it. Sitting on turns earns
 * a ruler nothing, which is what keeps everyone playing rather than hoarding.
 */
class IDO_Economy {

    // PEASANTS_PER_HOMESTEAD and TAX_PER_PEASANT used to live here. They are
    // settings now (homestead_capacity, tax_per_100_peasants) and leaving the
    // constants behind would have left two numbers claiming to be the same one.
    const GRAIN_PER_PEASANT      = 0.35;
    const GOLD_UPKEEP_PER_BUILDING = 6;
    const GROWTH_RATE            = 0.015;
    const DECLINE_RATE           = 0.01;
    const STARVATION_PEASANTS    = 0.02;
    const STARVATION_TROOPS      = 0.01;

    public static function peasant_capacity(object $kingdom): int {
        return (int) (IDO_Buildings::yields($kingdom)['peasant_capacity'] ?? 0);
    }

    /**
     * What one turn would produce right now, before it is spent. Used for the
     * projection shown on the empire screen and by advance().
     */
    public static function per_turn(object $kingdom): array {
        $buildings = IDO_Buildings::total($kingdom);
        $peasants  = (int) $kingdom->peasants;

        // Asked of the buildings rather than restated here. These numbers used
        // to sit in three places and only one of them was the game.
        $yield = IDO_Buildings::yields($kingdom);
        $tax   = IDO_Settings::int('tax_per_100_peasants') / 100;

        $gold_in    = $peasants * $tax + (int) ($yield['gold'] ?? 0);
        $gold_out   = $buildings * self::GOLD_UPKEEP_PER_BUILDING;
        $grain_in   = (int) ($yield['grain'] ?? 0);
        $grain_out  = $peasants * self::GRAIN_PER_PEASANT + IDO_Units::upkeep($kingdom)
            + IDO_Weapons::upkeep($kingdom);

        $capacity = self::peasant_capacity($kingdom);
        if ($peasants < $capacity) {
            $growth = (int) min($capacity - $peasants, max(3, round($peasants * self::GROWTH_RATE)));
        } else {
            $growth = -(int) round(($peasants - $capacity) * self::DECLINE_RATE);
        }

        return [
            'gold_in'    => (int) round($gold_in),
            'gold_out'   => (int) round($gold_out),
            'gold'       => (int) round($gold_in - $gold_out),
            'grain_in'   => (int) round($grain_in),
            'grain_out'  => (int) round($grain_out),
            'grain'      => (int) round($grain_in - $grain_out),
            'iron'       => (int) ($yield['iron'] ?? 0),
            'peasants'   => $growth,
            'capacity'   => $capacity,
        ];
    }

    /**
     * Applies the yield of $turns turns, compounding turn by turn so that
     * population growth and starvation behave the way a player expects.
     *
     * @return string[] lines describing the outcome, shown as flash notices
     */
    public static function advance(object $kingdom, int $turns, bool $hazards = true): array {
        $turns = max(1, $turns);
        $totals = ['gold' => 0, 'grain' => 0, 'iron' => 0, 'peasants' => 0];
        $starved = ['peasants' => 0, 'troops' => 0];

        $gold  = (int) $kingdom->gold;
        $grain = (int) $kingdom->grain;
        $iron  = (int) $kingdom->iron;
        $peasants = (int) $kingdom->peasants;
        $troop_losses = [];

        // Worked out once, not per turn: it costs a query, and nobody climbs
        // the standings midway through spending a handful of turns.
        // Barbarians and disasters belong to a turn somebody chose to spend. A
        // night's produce arriving on its own is not that, and a board waking to
        // a flood nobody ordered would read as the game misfiring.
        $raidable = $hazards && IDO_Barbarians::eligible($kingdom);
        $raids = 0;
        $raided = ['gold' => 0, 'grain' => 0];

        // Same reasoning, same once-per-order cost. $disaster stays null until
        // one lands and never fills twice: disasters do not combine, so the
        // first to settle is the only one this batch of turns will see.
        $strikeable = $hazards && IDO_Disasters::eligible($kingdom);
        $disaster = null;
        $buildings_lost = [];

        // A scratch copy so per_turn() sees the compounding numbers.
        $scratch = clone $kingdom;

        for ($i = 0; $i < $turns; $i++) {
            $scratch->gold = $gold;
            $scratch->grain = $grain;
            $scratch->peasants = $peasants;
            $rate = self::per_turn($scratch);

            $gold  = max(0, $gold + $rate['gold']);
            $iron  += $rate['iron'];
            $grain += $rate['grain'];
            $peasants = max(0, $peasants + $rate['peasants']);

            $totals['gold']       += $rate['gold'];
            $totals['iron']       += $rate['iron'];
            $totals['grain']      += $rate['grain'];
            $totals['peasants']   += $rate['peasants'];

            if ($raidable && IDO_Barbarians::rolls()) {
                // Taken from the stores as they stand this turn, before any
                // starvation is worked out: the barbarians get there first.
                $taken = IDO_Barbarians::take($gold, $grain);
                if ($taken['gold'] > 0 || $taken['grain'] > 0) {
                    $gold  = max(0, $gold - $taken['gold']);
                    $grain = max(0, $grain - $taken['grain']);
                    $raided['gold']  += $taken['gold'];
                    $raided['grain'] += $taken['grain'];
                    $raids++;
                }
            }

            if ($strikeable && $disaster === null && IDO_Disasters::rolls()) {
                // Read off the scratch copy so a disaster sees the empire as it
                // stands this turn, and taken before starvation is worked out,
                // for the same reason the barbarians are: losing the granaries
                // to insects is exactly the kind of thing that starts a famine,
                // and the famine should follow in the same handful of turns
                // rather than wait for the next order.
                $event = IDO_Disasters::strike($scratch, $grain);
                if ($event !== null) {
                    $spec = IDO_Disasters::kinds()[$event['kind']];
                    if ($spec['stock'] === 'grain') {
                        $grain = max(0, $grain - (int) $event['lost']);
                    } else {
                        // Fed back into the scratch copy so the turns after this
                        // one produce what the empire can actually produce. A
                        // drought on turn two of ten has to cost nine turns of
                        // grain, not one, or the loss is cosmetic.
                        $column = IDO_Buildings::column($spec['key']);
                        $buildings_lost[$column] = (int) $event['lost'];
                        $scratch->{$column} = max(0, (int) $scratch->{$column} - (int) $event['lost']);
                    }
                    $disaster = $event;
                }
            }

            if ($grain < 0) {
                // The stores are empty: peasants flee and troops desert.
                $grain = 0;
                $lost_peasants = (int) ceil($peasants * self::STARVATION_PEASANTS);
                $peasants = max(0, $peasants - $lost_peasants);
                $starved['peasants'] += $lost_peasants;
                foreach (IDO_Units::keys() as $key) {
                    $column = IDO_Units::column($key);
                    $have = (int) ($troop_losses[$key] ?? 0);
                    $standing = max(0, (int) $kingdom->{$column} - $have);
                    $lost = (int) ceil($standing * self::STARVATION_TROOPS);
                    if ($lost > 0) {
                        $troop_losses[$key] = $have + $lost;
                        $starved['troops'] += $lost;
                    }
                }
            }
        }

        // Clamped on the way out: income compounds turn after turn, and this is
        // the one place in the game where a number grows without an opponent.
        $fields = [
            'gold'       => IDO_Game::clamp($gold),
            'grain'      => IDO_Game::clamp($grain),
            'iron'       => IDO_Game::clamp($iron),
            'peasants'   => IDO_Game::clamp($peasants),
        ];
        foreach ($troop_losses as $key => $lost) {
            $fields[IDO_Units::column($key)] = max(0, (int) $kingdom->{IDO_Units::column($key)} - $lost);
        }
        foreach ($buildings_lost as $column => $lost) {
            $fields[$column] = max(0, (int) $kingdom->{$column} - $lost);
        }
        IDO_Kingdom::update($kingdom, $fields);
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        $lines = [];
        $lines[] = sprintf(
            '%d %s spent. Your empire produced %s gold, %s grain and %s iron.',
            $turns, $turns === 1 ? 'turn' : 'turns',
            IDO_Game::fmt($totals['gold']), IDO_Game::fmt($totals['grain']),
            IDO_Game::fmt($totals['iron'])
        );
        if ($raids > 0) {
            IDO_Barbarians::announce($kingdom, $raided);
            $lines[] = ['warning', IDO_Barbarians::report($raided, $raids)];
        }
        if ($disaster !== null) {
            IDO_Disasters::announce($kingdom, $disaster);
            $lines[] = ['warning', IDO_Disasters::report($disaster)];
        }
        if ($starved['peasants'] > 0 || $starved['troops'] > 0) {
            $lines[] = ['error', sprintf(
                'The granaries ran dry. %s peasants fled and %s troops deserted. Build farmsteads or buy grain on the market.',
                IDO_Game::fmt($starved['peasants']), IDO_Game::fmt($starved['troops'])
            )];
        }
        return $lines;
    }

    /**
     * Grants every living empire a night's produce on the daily tick.
     *
     * A turn-based game where nothing arrives unless a turn is spent means a
     * ruler who misses a day comes back to exactly what they left, and an empire
     * of a thousand farmsteads idles at nothing. This is the counterweight: the
     * fields were worked whether or not anybody gave an order.
     *
     * Expressed in turns rather than in gold, because a turn's produce is
     * already defined, already accounts for upkeep and appetite, and already
     * grows the population. One number, set to 0 by default, keeps the old rule
     * exactly: nothing arrives unless somebody spends a turn on it.
     *
     * The weather is not rolled: see advance(). And masterless provinces are
     * skipped, since they mend towards their own template and a night's income
     * on top would only be taken back again in the morning.
     *
     * @return array{empires:int,turns:int}
     */
    public static function nightly_yield(int $round_id): array {
        global $wpdb;

        $turns = max(0, IDO_Settings::int('daily_yield_turns'));
        if ($turns === 0) return ['empires' => 0, 'turns' => 0];

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id FROM ' . IDO_DB::t('kingdoms')
            . ' WHERE round_id = %d AND is_defeated = 0 AND is_rival = 0',
            $round_id
        ));

        $fed = 0;
        foreach ($rows as $row) {
            $kingdom = IDO_Kingdom::find((int) $row->id);
            if (!$kingdom) continue;
            self::advance($kingdom, $turns, false);
            $fed++;
        }

        return ['empires' => $fed, 'turns' => $turns];
    }

    /**
     * Explore for new land. The bigger the empire, the fewer acres a scouting
     * party finds and the more the crown has to pay for them.
     */
    public static function explore(object $kingdom): array {
        $base = IDO_Settings::int('explore_base_acres');
        $land = max(1, (int) $kingdom->land);
        $acres = (int) max(3, round($base * (400 / (400 + $land))));
        $cost  = (int) round($acres * (60 + $land / 4));

        if ((int) $kingdom->gold < $cost) {
            throw new IDO_Game_Exception(sprintf(
                'Settling %s acres costs %s gold and you have %s.',
                IDO_Game::fmt($acres), IDO_Game::fmt($cost), IDO_Game::fmt($kingdom->gold)
            ));
        }

        $messages = IDO_Kingdom::spend_turns($kingdom, 1);
        $kingdom = IDO_Kingdom::reload($kingdom);
        IDO_Kingdom::pay($kingdom, ['gold' => -$cost, 'land' => $acres], 'Your treasury cannot pay the settlers.');

        // Re-read before reporting: $kingdom still holds the row as it was
        // before the acres were added, and the message quotes the new total.
        $kingdom = IDO_Kingdom::reload($kingdom);
        IDO_Kingdom::recalc_networth($kingdom);

        $messages[] = sprintf(
            // The running total matters: the acres a party finds barely changes
            // between one exploration and the next, so without it the screen
            // looks as though nothing happened.
            'Your settlers claim %s acres of wilderness for %s gold. Your empire now holds %s acres, %s of them wilderness.',
            IDO_Game::fmt($acres), IDO_Game::fmt($cost),
            IDO_Game::fmt($kingdom->land), IDO_Game::fmt(IDO_Buildings::wilderness($kingdom))
        );
        return $messages;
    }

    /** The acres and price a single exploration would fetch, for the UI. */
    public static function explore_preview(object $kingdom): array {
        $base = IDO_Settings::int('explore_base_acres');
        $land = max(1, (int) $kingdom->land);
        $acres = (int) max(3, round($base * (400 / (400 + $land))));
        return ['acres' => $acres, 'gold' => (int) round($acres * (60 + $land / 4))];
    }
}
