<?php
/**
 * Siege weapons: war machines an empire builds rather than trains.
 *
 * A weapon is not a building and not a troop. It stands on no acre, so
 * capturing one never has to find land to put it on, and it costs no peasants,
 * so it never touches the tax base.
 *
 * Nor is it an army of its own. A catapult goes nowhere and does nothing
 * without men to haul it and work it: every weapon names the troops that crew
 * it and how many each one needs. An uncrewed weapon is so much timber, whether
 * it is sitting in an empire's yards or standing on its wall.
 *
 * As with buildings and units, each key is also a column on the ido_kingdoms
 * table and must not change without a migration. Weapons carry no prefix: the
 * column is the key itself.
 */
if (!defined('ABSPATH')) exit;

class IDO_Weapons {

    public static function all(): array {
        return [
            'catapult' => [
                'label'   => 'Catapult',
                'plural'  => 'Catapults',
                'offence' => 5,
                'defence' => 3,
                // Legionnaires built and worked Rome's artillery, and it gives
                // the one troop that is useless on an attack something to do
                // there without making it good at fighting.
                'crew_unit' => 'legionnaire',
                'crew'      => 5,
                'upkeep'    => 0.8, // grain per turn, for the draught teams
                'effect'  => 'Worked by legionnaires, five to a catapult. Adds to your attack when you haul it out '
                           . 'and to your walls when you do not. Whichever side loses a battle gives up a share of '
                           . 'the catapults that were in it.',
                'note'    => 'Built, not trained: no peasants leave the fields for it and it stands on no acre. '
                           . 'It needs legionnaires to work it, so send them with it, and risk handing both to the '
                           . 'enemy if the day goes badly.',
            ],
        ];
    }

    public static function keys(): array {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key): array {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new IDO_Game_Exception('No such siege weapon.');
        }
        return $all[$key];
    }

    public static function label(string $key): string {
        $all = self::all();
        return $all[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function plural(string $key): string {
        $all = self::all();
        return $all[$key]['plural'] ?? self::label($key);
    }

    /** The troop type that works this weapon. */
    public static function crew_unit(string $key): string {
        return (string) self::get($key)['crew_unit'];
    }

    /** How many of those troops one of these weapons needs. */
    public static function crew_each(string $key): int {
        return max(1, (int) self::get($key)['crew']);
    }

    /**
     * The troops a train of weapons needs before it can be hauled anywhere.
     *
     * @param array $train weapon key => quantity
     * @return array unit key => crew required
     */
    public static function crew_needed(array $train): array {
        $needed = [];
        foreach ($train as $key => $qty) {
            if (!self::exists($key)) continue;
            $qty = max(0, (int) $qty);
            if ($qty < 1) continue;
            $unit = self::crew_unit($key);
            $needed[$unit] = ($needed[$unit] ?? 0) + $qty * self::crew_each($key);
        }
        return $needed;
    }

    /**
     * How many of each weapon an empire can actually work with the troops it
     * has standing at home. Anything beyond that is timber on a wall.
     *
     * Each crew pool is spent in the order weapons are declared, so two weapons
     * drawing on the same troops can never both be counted as fully crewed.
     *
     * @return array weapon key => the number that is manned
     */
    public static function crewed(object $kingdom): array {
        $pool = [];
        $manned = [];
        foreach (self::all() as $key => $weapon) {
            $unit = (string) $weapon['crew_unit'];
            if (!isset($pool[$unit])) {
                $pool[$unit] = IDO_Units::exists($unit) ? (int) $kingdom->{IDO_Units::column($unit)} : 0;
            }
            $standing = (int) $kingdom->{self::column($key)};
            $can_work = intdiv($pool[$unit], self::crew_each($key));
            $manned[$key] = max(0, min($standing, $can_work));
            $pool[$unit] -= $manned[$key] * self::crew_each($key);
        }
        return $manned;
    }

    /** The ido_kingdoms column holding the standing count of a weapon. */
    public static function column(string $key): string {
        return $key . 's';
    }

    /** The column holding weapons of this kind still being built. */
    public static function progress_column(string $key): string {
        return $key . 's_in_progress';
    }

    /** Gold and iron price of building one weapon. */
    public static function cost(string $key, int $qty = 1): array {
        self::get($key);
        return [
            'gold' => IDO_Settings::int($key . '_gold_cost') * $qty,
            'iron' => IDO_Settings::int($key . '_iron_cost') * $qty,
        ];
    }

    /** Total standing weapons on an empire row, summed safely and clamped. */
    public static function total(object $kingdom): int {
        $total = 0.0;
        foreach (self::keys() as $key) {
            $total += (float) $kingdom->{self::column($key)};
        }
        return IDO_Game::clamp($total);
    }

    /** Grain eaten per turn by the weapon crews. */
    public static function upkeep(object $kingdom): float {
        $upkeep = 0.0;
        foreach (self::all() as $key => $weapon) {
            $upkeep += $weapon['upkeep'] * (int) $kingdom->{self::column($key)};
        }
        return $upkeep;
    }

    /**
     * Defensive strength of the weapons standing at home, before fortifications.
     * Only the manned ones count: a catapult with nobody on it is scenery.
     */
    public static function defence_power(object $kingdom): float {
        $power = 0.0;
        $manned = self::crewed($kingdom);
        foreach (self::all() as $key => $weapon) {
            $power += $weapon['defence'] * (int) ($manned[$key] ?? 0);
        }
        return $power;
    }

    /** Offensive strength of a named train of weapons, e.g. ['catapult' => 40]. */
    public static function offence_power(array $train): float {
        $power = 0.0;
        foreach ($train as $key => $qty) {
            if (!self::exists($key)) continue;
            $power += self::get($key)['offence'] * max(0, (int) $qty);
        }
        return $power;
    }

    /**
     * Net worth credited for the standing weapons. Half the gold price, the
     * same rule the army is valued by, so a captured weapon moves net worth
     * from the loser to the winner instead of conjuring it.
     */
    public static function networth(object $kingdom): int {
        $worth = 0.0;
        foreach (self::keys() as $key) {
            $worth += round(self::cost($key)['gold'] / 2) * (float) $kingdom->{self::column($key)};
        }
        return IDO_Game::clamp($worth);
    }
}
