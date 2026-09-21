<?php
if (!defined('ABSPATH')) exit;

/**
 * The economy runs on turns, not on the clock: a turn spent is a day of your
 * kingdom's life, and it pays out the moment you spend it. Sitting on turns earns
 * a ruler nothing, which is what keeps everyone playing rather than hoarding.
 */
class IDO_Economy {

    const PEASANTS_PER_HOMESTEAD = 30;
    const TAX_PER_PEASANT        = 0.55;
    const GRAIN_PER_PEASANT      = 0.35;
    const GOLD_UPKEEP_PER_BUILDING = 6;
    const GROWTH_RATE            = 0.015;
    const DECLINE_RATE           = 0.01;
    const STARVATION_PEASANTS    = 0.02;
    const STARVATION_TROOPS      = 0.01;

    public static function peasant_capacity(object $kingdom): int {
        return (int) $kingdom->b_homestead * self::PEASANTS_PER_HOMESTEAD;
    }

    /**
     * What one turn would produce right now, before it is spent. Used for the
     * projection shown on the throne room screen and by advance().
     */
    public static function per_turn(object $kingdom): array {
        $buildings = IDO_Buildings::total($kingdom);
        $peasants  = (int) $kingdom->peasants;

        $gold_in    = $peasants * self::TAX_PER_PEASANT + (int) $kingdom->b_counting_house * 60;
        $gold_out   = $buildings * self::GOLD_UPKEEP_PER_BUILDING;
        $grain_in   = (int) $kingdom->b_farmstead * 85;
        $grain_out  = $peasants * self::GRAIN_PER_PEASANT + IDO_Units::upkeep($kingdom);

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
            'iron'       => (int) $kingdom->b_foundry * 25,
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
    public static function advance(object $kingdom, int $turns): array {
        $turns = max(1, $turns);
        $totals = ['gold' => 0, 'grain' => 0, 'iron' => 0, 'peasants' => 0];
        $starved = ['peasants' => 0, 'troops' => 0];

        $gold  = (int) $kingdom->gold;
        $grain = (int) $kingdom->grain;
        $iron  = (int) $kingdom->iron;
        $peasants = (int) $kingdom->peasants;
        $troop_losses = [];

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
        IDO_Kingdom::update($kingdom, $fields);
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        $lines = [];
        $lines[] = sprintf(
            '%d %s spent. Your kingdom produced %s gold, %s grain and %s iron.',
            $turns, $turns === 1 ? 'turn' : 'turns',
            IDO_Game::fmt($totals['gold']), IDO_Game::fmt($totals['grain']),
            IDO_Game::fmt($totals['iron'])
        );
        if ($starved['peasants'] > 0 || $starved['troops'] > 0) {
            $lines[] = ['error', sprintf(
                'The granaries ran dry. %s peasants fled and %s troops deserted. Build farmsteads or buy grain on the market.',
                IDO_Game::fmt($starved['peasants']), IDO_Game::fmt($starved['troops'])
            )];
        }
        return $lines;
    }

    /**
     * Explore for new land. The bigger the kingdom, the fewer acres a scouting
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
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        $messages[] = sprintf(
            'Your settlers claim %s acres of wilderness for %s gold.',
            IDO_Game::fmt($acres), IDO_Game::fmt($cost)
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
