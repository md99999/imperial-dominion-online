<?php
if (!defined('ABSPATH')) exit;

/**
 * Training and war. Battles resolve the moment the attacker commits, against
 * whatever the defender had standing at that instant, and both sides get a
 * written report.
 */
class IDO_Military {

    /** The three ways to march on a neighbour. */
    public static function attack_types(): array {
        return [
            'conquest' => [
                'label' => 'Conquest',
                'note'  => 'Seize acres. The costliest fight and the only one that grows your empire.',
            ],
            'raid' => [
                'label' => 'Raid',
                'note'  => 'Strip the treasury and granaries. No land changes hands and your losses are lighter.',
            ],
            'siege' => [
                'label' => 'Siege',
                'note'  => 'Throw down buildings, fortifications first. Softens an empire you mean to conquer later.',
            ],
        ];
    }

    /**
     * Everything an empire can put on its walls: the troops standing at home
     * and the siege weapons beside them, lifted by the fortification bonus.
     *
     * This is the one definition of a defence rating. The battle resolver works
     * from the same two sums, so what a ruler reads on their own screen and
     * what an attacker runs into are the same number.
     */
    public static function defence_power(object $kingdom): float {
        $raw = IDO_Units::defence_power($kingdom) + IDO_Weapons::defence_power($kingdom);
        return $raw * IDO_Buildings::fortification_bonus($kingdom);
    }

    /** Gold and iron price of training, after the barracks discount. */
    public static function training_cost(object $kingdom, string $key, int $qty): array {
        $unit = IDO_Units::get($key);
        $discount = IDO_Buildings::barracks_discount($kingdom);
        return [
            'gold'     => (int) round($unit['gold'] * (1 - $discount) * $qty),
            'iron'     => (int) $unit['iron'] * $qty,
            'peasants' => (int) $unit['peasants'] * $qty,
        ];
    }

    /** Trains troops. Peasants become soldiers, so the tax base shrinks. */
    public static function train(object $kingdom, string $key, int $qty): array {
        if (!IDO_Units::exists($key)) {
            throw new IDO_Game_Exception('No such troop type.');
        }
        $qty = IDO_Game::qty($qty, 10000000);
        if ($qty < 1) {
            throw new IDO_Game_Exception('Train at least one soldier.');
        }

        $cost = self::training_cost($kingdom, $key, $qty);
        if ((int) $kingdom->gold < $cost['gold'] || (int) $kingdom->iron < $cost['iron'] || (int) $kingdom->peasants < $cost['peasants']) {
            throw new IDO_Game_Exception(sprintf(
                'Training %s %s needs %s gold, %s iron and %s peasants. You have %s gold, %s iron and %s peasants.',
                IDO_Game::fmt($qty), strtolower(IDO_Units::plural($key)),
                IDO_Game::fmt($cost['gold']), IDO_Game::fmt($cost['iron']), IDO_Game::fmt($cost['peasants']),
                IDO_Game::fmt($kingdom->gold), IDO_Game::fmt($kingdom->iron), IDO_Game::fmt($kingdom->peasants)
            ));
        }

        $messages = IDO_Kingdom::spend_turns($kingdom, 1);
        $kingdom = IDO_Kingdom::reload($kingdom);
        IDO_Kingdom::pay($kingdom, [
            'gold'     => -$cost['gold'],
            'iron'     => -$cost['iron'],
            'peasants' => -$cost['peasants'],
            IDO_Units::column($key) => $qty,
        ], 'Your treasury, forges or villages cannot supply that many.');
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        $messages[] = sprintf(
            '%s %s muster for service, at a cost of %s gold and %s iron.',
            IDO_Game::fmt($qty), strtolower(IDO_Units::plural($key)),
            IDO_Game::fmt($cost['gold']), IDO_Game::fmt($cost['iron'])
        );
        return $messages;
    }

    /** Disbands troops. They return to the fields as peasants. */
    public static function disband(object $kingdom, string $key, int $qty): string {
        if (!IDO_Units::exists($key)) {
            throw new IDO_Game_Exception('No such troop type.');
        }
        $unit = IDO_Units::get($key);
        $column = IDO_Units::column($key);
        $qty = IDO_Game::qty($qty, (int) $kingdom->{$column});
        if ($qty < 1) {
            throw new IDO_Game_Exception('You have none of those under arms.');
        }

        IDO_Kingdom::pay($kingdom, [
            $column => -$qty,
            'peasants' => $qty * (int) $unit['peasants'],
        ], 'Those troops are no longer under your banner.');
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        return sprintf(
            '%s %s hand back their arms and return to the fields.',
            IDO_Game::fmt($qty), strtolower(IDO_Units::plural($key))
        );
    }

    /** Empires this ruler is allowed to march on, by net worth band. */
    public static function targets(object $kingdom, int $limit = 100): array {
        global $wpdb;
        $min = max(1, (int) round((int) $kingdom->networth * IDO_Settings::int('target_min_percent') / 100));
        $max = (int) round((int) $kingdom->networth * IDO_Settings::int('target_max_percent') / 100);
        if ($max < $min) $max = PHP_INT_MAX;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, kingdom_name, ruler_name, land, networth, protection_until, attacks_suffered'
            . ' FROM ' . IDO_DB::t('kingdoms')
            . ' WHERE round_id = %d AND id <> %d AND is_defeated = 0 AND networth BETWEEN %d AND %d'
            . ' ORDER BY networth DESC LIMIT %d',
            (int) $kingdom->round_id, (int) $kingdom->id, $min, $max, $limit
        ));
    }

    /** Times this empire has already hit that target since midnight. */
    public static function hits_today(int $attacker_id, int $defender_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('battles')
            . ' WHERE attacker_kingdom_id = %d AND defender_kingdom_id = %d AND created_at >= %s',
            $attacker_id, $defender_id, IDO_Game::today() . ' 00:00:00'
        ));
    }

    /**
     * Marches on another empire and resolves the battle at once.
     *
     * @param array $force unit key => quantity committed
     * @param array $train weapon key => quantity committed
     * @return string[] report lines for the attacker
     */
    public static function attack(object $kingdom, int $target_id, string $type, array $force, array $train = []): array {
        global $wpdb;

        $types = self::attack_types();
        if (!isset($types[$type])) {
            throw new IDO_Game_Exception('Choose a kind of attack.');
        }
        if ($target_id === (int) $kingdom->id) {
            throw new IDO_Game_Exception('You cannot march on your own empire.');
        }

        // Normalise and verify the force before anything is spent.
        $committed = [];
        foreach ($force as $key => $qty) {
            if (!IDO_Units::exists($key)) continue;
            $qty = IDO_Game::qty($qty);
            if ($qty < 1) continue;
            if ($qty > (int) $kingdom->{IDO_Units::column($key)}) {
                throw new IDO_Game_Exception(sprintf(
                    'You have only %s %s under arms.',
                    IDO_Game::fmt($kingdom->{IDO_Units::column($key)}), strtolower(IDO_Units::plural($key))
                ));
            }
            $committed[$key] = $qty;
        }
        if (!$committed) {
            throw new IDO_Game_Exception('Send at least one soldier.');
        }

        // The weapon train is hauled out by the army, and every weapon in it is
        // also at stake, which is the whole decision: a bigger train hits
        // harder and is a bigger prize for the enemy if the day goes badly.
        $weapons = [];
        foreach ($train as $key => $qty) {
            if (!IDO_Weapons::exists($key)) continue;
            $qty = IDO_Game::qty($qty);
            if ($qty < 1) continue;
            if ($qty > (int) $kingdom->{IDO_Weapons::column($key)}) {
                throw new IDO_Game_Exception(sprintf(
                    'You have only %s %s standing.',
                    IDO_Game::fmt($kingdom->{IDO_Weapons::column($key)}), strtolower(IDO_Weapons::plural($key))
                ));
            }
            $weapons[$key] = $qty;
        }

        // A weapon goes nowhere on its own. The men to work it have to be in
        // the force you are sending, not merely somewhere in the empire, and
        // the order is refused rather than quietly sending a train that cannot
        // be worked when it arrives.
        foreach (IDO_Weapons::crew_needed($weapons) as $unit => $required) {
            $sending = (int) ($committed[$unit] ?? 0);
            if ($sending >= $required) continue;
            throw new IDO_Game_Exception(sprintf(
                'Your siege train needs %s %s to work it and you are sending %s. Add %s more, or send fewer weapons.',
                IDO_Game::fmt($required), strtolower(IDO_Units::plural($unit)),
                IDO_Game::fmt($sending), IDO_Game::fmt($required - $sending)
            ));
        }

        $offence_base = IDO_Units::offence_power($committed) + IDO_Weapons::offence_power($weapons);
        if ($offence_base <= 0) {
            throw new IDO_Game_Exception('None of those troops can carry an attack. Send centurions or ballistae legions.');
        }

        $turn_cost = max(1, IDO_Settings::int('attack_turn_cost'));
        if ((int) $kingdom->turns < $turn_cost) {
            throw new IDO_Game_Exception(sprintf('Marching costs %d turns and you have %d.', $turn_cost, (int) $kingdom->turns));
        }

        // One battle per attacker at a time, so two tabs cannot loot the same empire twice.
        if (!IDO_Lock::acquire('battle_' . min((int) $kingdom->id, $target_id) . '_' . max((int) $kingdom->id, $target_id), 8)) {
            throw new IDO_Game_Exception('That empire is already under attack. Try again in a moment.');
        }

        try {
            $target = IDO_Kingdom::find($target_id);
            if (!$target || (int) $target->round_id !== (int) $kingdom->round_id || (int) $target->is_defeated === 1) {
                throw new IDO_Game_Exception('No such empire stands in this round.');
            }
            if (IDO_Kingdom::is_protected($target)) {
                throw new IDO_Game_Exception(sprintf('%s is still under the crown truce and cannot be attacked yet.', $target->kingdom_name));
            }
            $min = (int) round((int) $kingdom->networth * IDO_Settings::int('target_min_percent') / 100);
            $max = (int) round((int) $kingdom->networth * IDO_Settings::int('target_max_percent') / 100);
            if ((int) $target->networth < $min || ((int) $target->networth > $max && $max > 0)) {
                throw new IDO_Game_Exception(sprintf(
                    '%s is outside the net worth band you are allowed to attack (%s to %s).',
                    $target->kingdom_name, IDO_Game::fmt($min), IDO_Game::fmt($max)
                ));
            }
            $cap = IDO_Settings::int('max_hits_per_target');
            if ($cap > 0 && self::hits_today((int) $kingdom->id, $target_id) >= $cap) {
                throw new IDO_Game_Exception(sprintf(
                    'You have already struck %s %d times today. The heralds forbid another march until tomorrow.',
                    $target->kingdom_name, $cap
                ));
            }

            $messages = IDO_Kingdom::spend_turns($kingdom, $turn_cost);
            $kingdom = IDO_Kingdom::reload($kingdom);
            IDO_Kingdom::drop_protection($kingdom);

            // Strength on the day. The small random swing keeps a narrow win uncertain.
            $ballista_share = $offence_base > 0
                ? IDO_Units::offence_power(array_intersect_key($committed, ['ballista_legion' => 1])) / $offence_base
                : 0.0;
            $offence = $offence_base * self::swing();

            // Weapons at home man the walls beside the troops, and so are part
            // of what the attacker has to break through.
            $raw_defence = IDO_Units::defence_power($target) + IDO_Weapons::defence_power($target);
            // Ballistae blunt the fortification bonus rather than the troops behind it.
            $fortification = IDO_Buildings::fortification_bonus($target);
            $effective_fortification = 1.0 + ($fortification - 1.0) * (1 - 0.75 * $ballista_share);
            $defence = $raw_defence * $effective_fortification * self::swing();

            $won = $offence > $defence;
            $ratio = $defence > 0 ? $offence / $defence : 2.0;

            $result = [
                'land' => 0, 'gold' => 0, 'grain' => 0, 'iron' => 0, 'demolished' => 0,
                'weapons_taken' => 0, 'weapons_wrecked' => 0,
            ];
            if ($won) {
                switch ($type) {
                    case 'conquest':
                        $result['land'] = self::take_land($kingdom, $target, $ratio);
                        break;
                    case 'raid':
                        $result = array_merge($result, self::take_plunder($kingdom, $target, $ratio));
                        break;
                    case 'siege':
                        $result['demolished'] = self::demolish_buildings($target, $ratio);
                        break;
                }
            }

            // Weapons change hands before the casualty rolls, so the counts the
            // spoils are worked out from are the ones both sides marched with.
            // The loser's stake is what they had in the fight: for the attacker
            // that is the train they sent, for the defender everything standing.
            $spoils = self::resolve_weapon_spoils($kingdom, $target, $weapons, $won);
            $result['weapons_taken'] = $spoils['taken'];
            $result['weapons_wrecked'] = $spoils['wrecked'];

            $attacker_losses = self::apply_losses($kingdom, $committed, $won ? 0.07 : 0.18);
            $defender_home = [];
            foreach (IDO_Units::keys() as $key) {
                $defender_home[$key] = (int) $target->{IDO_Units::column($key)};
            }
            $defender_losses = self::apply_losses($target, $defender_home, $won ? 0.06 : 0.03);

            $kingdom  = IDO_Kingdom::reload($kingdom);
            $target = IDO_Kingdom::reload($target);
            IDO_Kingdom::pay($kingdom, ['attacks_made' => 1] + ($won ? ['attacks_won' => 1] : []));
            IDO_Kingdom::pay($target, ['attacks_suffered' => 1]);
            IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));
            IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($target));

            $attacker_report = self::report_lines($types[$type]['label'], $won, $result, $attacker_losses, $defender_losses, true, $target->kingdom_name);
            $defender_report = self::report_lines($types[$type]['label'], $won, $result, $attacker_losses, $defender_losses, false, $kingdom->kingdom_name);

            $wpdb->insert(IDO_DB::t('battles'), [
                'round_id'          => (int) $kingdom->round_id,
                'attacker_kingdom_id' => (int) $kingdom->id,
                'defender_kingdom_id' => (int) $target->id,
                'attack_type'       => $type,
                'outcome'           => $won ? 'victory' : 'repelled',
                'offence_power'     => (int) round($offence),
                'defence_power'     => (int) round($defence),
                'land_taken'        => (int) $result['land'],
                'loot_gold'         => (int) $result['gold'],
                'loot_grain'        => (int) $result['grain'],
                'loot_iron'         => (int) $result['iron'],
                // The column keeps its original name: renaming it would cost a
                // migration, and the word never reaches a player.
                'buildings_razed'   => (int) $result['demolished'],
                'catapults_captured' => (int) $result['weapons_taken'],
                'catapults_destroyed' => (int) $result['weapons_wrecked'],
                'attacker_report'   => implode("\n", $attacker_report),
                'defender_report'   => implode("\n", $defender_report),
                'created_at'        => IDO_Game::now(),
            ], ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']);

            IDO_Log::news('war', $won
                ? sprintf('%s marched on %s and carried the field.', $kingdom->kingdom_name, $target->kingdom_name)
                : sprintf('%s marched on %s and was thrown back.', $kingdom->kingdom_name, $target->kingdom_name)
            );

            return array_merge($messages, [[$won ? 'success' : 'warning', implode("\n", $attacker_report)]]);
        } finally {
            IDO_Lock::release('battle_' . min((int) $kingdom->id, $target_id) . '_' . max((int) $kingdom->id, $target_id));
        }
    }

    /**
     * How a stake of siege weapons is divided after a battle is lost.
     *
     * The winner drags home catapult_capture_percent of it and
     * catapult_destroy_percent is smashed where it stands, so a defeat costs
     * the two added together. Where rounding would claim more than was at
     * stake, the wrecked share gives way first: a weapon cannot be both
     * captured and splinters. Kept separate from the battle so the arithmetic
     * can be read, and tested, without a database behind it.
     *
     * @return array ['captured' => int, 'wrecked' => int]
     */
    public static function weapon_spoils(int $stake): array {
        $stake = max(0, $stake);
        if ($stake < 1) return ['captured' => 0, 'wrecked' => 0];

        $capture = max(0, min(100, IDO_Settings::int('catapult_capture_percent')));
        $destroy = max(0, min(100, IDO_Settings::int('catapult_destroy_percent')));

        $captured = min($stake, (int) round($stake * $capture / 100));
        $wrecked  = min($stake - $captured, (int) round($stake * $destroy / 100));
        return ['captured' => $captured, 'wrecked' => $wrecked];
    }

    /**
     * Moves siege weapons from the losing side to the winning one, and smashes
     * a further share where it stands.
     *
     * Only what was in the fight is at stake. The attacker stakes the train
     * they hauled out and nothing they left at home; the defender stakes the
     * weapons they had crews for, because an unmanned catapult took no part in
     * the battle and so is neither a prize nor a casualty.
     *
     * @return array ['taken' => int, 'wrecked' => int]
     */
    private static function resolve_weapon_spoils(object $kingdom, object $target, array $sent, bool $attacker_won): array {
        $winner = $attacker_won ? $kingdom : $target;
        $loser  = $attacker_won ? $target : $kingdom;

        $manned = IDO_Weapons::crewed($target);

        $taken = 0;
        $wrecked = 0;
        foreach (IDO_Weapons::keys() as $key) {
            $column = IDO_Weapons::column($key);
            $stake = $attacker_won
                ? (int) ($manned[$key] ?? 0)        // only what the defender had men to work
                : (int) ($sent[$key] ?? 0);         // only the train the attacker hauled out
            $stake = max(0, min($stake, (int) $loser->{$column}));

            $split = self::weapon_spoils($stake);
            $lost = $split['captured'] + $split['wrecked'];
            if ($lost < 1) continue;

            IDO_Kingdom::pay($loser, [$column => -$lost], 'Those weapons were no longer there to lose.');
            if ($split['captured'] > 0) {
                IDO_Kingdom::pay($winner, [$column => $split['captured']]);
            }
            $taken += $split['captured'];
            $wrecked += $split['wrecked'];
        }
        return ['taken' => $taken, 'wrecked' => $wrecked];
    }

    /** A 5% swing either way, so evenly matched armies are a gamble. */
    private static function swing(): float {
        return 0.95 + (wp_rand(0, 1000) / 10000);
    }

    /** Moves acres from defender to attacker, demolishing what stood on them. */
    private static function take_land(object $kingdom, object $target, float $ratio): int {
        $percent = IDO_Settings::int('conquest_land_percent') / 100;
        $acres = (int) round((int) $target->land * $percent * min(1.5, max(0.5, $ratio)));
        $acres = max(1, min($acres, (int) $target->land - 1));
        if ($acres < 1) return 0;

        // The captured acres come out of the defender proportionally: some built, some wild.
        $built = IDO_Buildings::total($target);
        $deltas = ['land' => -$acres, 'land_lost' => $acres];
        if ($built > 0 && (int) $target->land > 0) {
            $built_share = (int) round($acres * ($built / (int) $target->land));
            $remaining = $built_share;
            foreach (IDO_Buildings::keys() as $key) {
                if ($remaining <= 0) break;
                $column = IDO_Buildings::column($key);
                $standing = (int) $target->{$column};
                if ($standing <= 0) continue;
                $take = min($remaining, (int) ceil($built_share * ($standing / max(1, $built))));
                $take = min($take, $standing);
                if ($take > 0) {
                    $deltas[$column] = -$take;
                    $remaining -= $take;
                }
            }
        }
        IDO_Kingdom::pay($target, $deltas, 'The defending empire no longer holds that land.');
        IDO_Construction::trim_to_land($target);
        IDO_Kingdom::pay($kingdom, ['land' => $acres, 'land_taken' => $acres]);
        return $acres;
    }

    /** Strips a share of the defender stores and carries it home. */
    private static function take_plunder(object $kingdom, object $target, float $ratio): array {
        $scale = min(1.4, max(0.6, $ratio));
        $loot = [
            'gold'  => (int) round((int) $target->gold * 0.09 * $scale),
            'grain' => (int) round((int) $target->grain * 0.07 * $scale),
            'iron'  => (int) round((int) $target->iron * 0.07 * $scale),
        ];
        $loot['gold']  = min($loot['gold'], (int) $target->gold);
        $loot['grain'] = min($loot['grain'], (int) $target->grain);
        $loot['iron']  = min($loot['iron'], (int) $target->iron);

        IDO_Kingdom::pay($target, [
            'gold' => -$loot['gold'], 'grain' => -$loot['grain'], 'iron' => -$loot['iron'],
        ], 'There was nothing left to take.');
        IDO_Kingdom::pay($kingdom, $loot);
        return $loot;
    }

    /** Throws down buildings, fortifications first. */
    private static function demolish_buildings(object $target, float $ratio): int {
        $built = IDO_Buildings::total($target);
        if ($built < 1) return 0;
        $total = (int) round($built * 0.04 * min(1.5, max(0.6, $ratio)));
        $total = max(1, min($total, $built));

        $order = array_merge(['fortification'], array_diff(IDO_Buildings::keys(), ['fortification']));
        $deltas = [];
        $remaining = $total;
        foreach ($order as $key) {
            if ($remaining <= 0) break;
            $column = IDO_Buildings::column($key);
            $standing = (int) $target->{$column};
            if ($standing <= 0) continue;
            $take = $key === 'fortification'
                ? min($standing, (int) ceil($total * 0.5), $remaining)
                : min($standing, (int) ceil($total * 0.2), $remaining);
            if ($take > 0) {
                $deltas[$column] = -$take;
                $remaining -= $take;
            }
        }
        if (!$deltas) return 0;
        IDO_Kingdom::pay($target, $deltas, 'There was nothing left standing to throw down.');
        return $total - $remaining;
    }

    /**
     * Applies a proportional casualty rate to a force and writes it off.
     *
     * @return array unit key => losses
     */
    private static function apply_losses(object $kingdom, array $force, float $rate): array {
        $losses = [];
        $deltas = [];
        foreach ($force as $key => $qty) {
            $qty = (int) $qty;
            if ($qty < 1 || !IDO_Units::exists($key)) continue;
            $lost = (int) round($qty * $rate);
            if ($lost < 1 && $qty > 0 && $rate > 0) $lost = 1;
            $lost = min($lost, (int) $kingdom->{IDO_Units::column($key)});
            if ($lost > 0) {
                $losses[$key] = $lost;
                $deltas[IDO_Units::column($key)] = -$lost;
            }
        }
        if ($deltas) {
            IDO_Kingdom::pay($kingdom, $deltas, 'Casualties could not be recorded.');
        }
        return $losses;
    }

    /** Turns a battle result into the lines both sides read. */
    private static function report_lines(string $type_label, bool $attacker_won, array $result, array $attacker_losses, array $defender_losses, bool $for_attacker, string $other): array {
        $lines = [];
        if ($for_attacker) {
            $lines[] = $attacker_won
                ? sprintf('%s against %s: your banners hold the field.', $type_label, $other)
                : sprintf('%s against %s: your army is thrown back from the walls.', $type_label, $other);
        } else {
            $lines[] = $attacker_won
                ? sprintf('%s of %s broke through your defences.', $type_label, $other)
                : sprintf('%s of %s was thrown back from your walls.', $type_label, $other);
        }

        if ($attacker_won) {
            if ($result['land'] > 0) {
                $lines[] = $for_attacker
                    ? sprintf('You annex %s acres.', IDO_Game::fmt($result['land']))
                    : sprintf('You lose %s acres.', IDO_Game::fmt($result['land']));
            }
            if ($result['gold'] || $result['grain'] || $result['iron']) {
                $lines[] = sprintf(
                    '%s %s gold, %s grain and %s iron.',
                    $for_attacker ? 'Plundered:' : 'Carried off:',
                    IDO_Game::fmt($result['gold']), IDO_Game::fmt($result['grain']), IDO_Game::fmt($result['iron'])
                );
            }
            if ($result['demolished'] > 0) {
                $lines[] = sprintf('%s buildings were thrown down.', IDO_Game::fmt($result['demolished']));
            }
        }

        // The weapon spoils read the same way whichever side won, so they are
        // written once, outside the branch that only covers an attacker's win.
        if ($result['weapons_taken'] > 0 || $result['weapons_wrecked'] > 0) {
            $kept_them = $for_attacker === $attacker_won;
            $lines[] = $kept_them
                ? sprintf(
                    'You haul off %s enemy catapults and leave %s burning on the field.',
                    IDO_Game::fmt($result['weapons_taken']), IDO_Game::fmt($result['weapons_wrecked'])
                )
                : sprintf(
                    'You lose %s catapults: %s are dragged away by the enemy and %s are smashed where they stand.',
                    IDO_Game::fmt($result['weapons_taken'] + $result['weapons_wrecked']),
                    IDO_Game::fmt($result['weapons_taken']), IDO_Game::fmt($result['weapons_wrecked'])
                );
        }

        $lines[] = 'Your losses: ' . (self::losses_text($for_attacker ? $attacker_losses : $defender_losses) ?: 'none');
        $lines[] = 'Enemy losses: ' . (self::losses_text($for_attacker ? $defender_losses : $attacker_losses) ?: 'none');
        return $lines;
    }

    private static function losses_text(array $losses): string {
        $parts = [];
        foreach ($losses as $key => $qty) {
            $parts[] = sprintf('%s %s', IDO_Game::fmt($qty), strtolower(IDO_Units::plural($key)));
        }
        return implode(', ', $parts);
    }

    /** Recent battles involving an empire, newest first. */
    public static function history(object $kingdom, int $limit = 25): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT b.*, a.kingdom_name AS attacker_name, d.kingdom_name AS defender_name'
            . ' FROM ' . IDO_DB::t('battles') . ' b'
            . ' LEFT JOIN ' . IDO_DB::t('kingdoms') . ' a ON a.id = b.attacker_kingdom_id'
            . ' LEFT JOIN ' . IDO_DB::t('kingdoms') . ' d ON d.id = b.defender_kingdom_id'
            . ' WHERE b.attacker_kingdom_id = %d OR b.defender_kingdom_id = %d'
            . ' ORDER BY b.id DESC LIMIT %d',
            (int) $kingdom->id, (int) $kingdom->id, $limit
        ));
    }
}
