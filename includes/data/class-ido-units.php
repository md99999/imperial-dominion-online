<?php
/**
 * The troops an empire can raise. Offence counts only when attacking, defence
 * only when defending, so an army built for one job is nearly useless at the
 * other: that trade-off is the heart of the war game.
 *
 * As with buildings, each key is also a column on the ido_kingdoms table (prefixed
 * u_) and must not change without a migration.
 */
if (!defined('ABSPATH')) exit;

class IDO_Units {

    public static function all(): array {
        return [
            'pawn' => [
                'label'   => 'Pawn',
                'plural'  => 'Pawns',
                'offence' => 0,
                'defence' => 3,
                'gold'    => 120,
                'iron'    => 5,
                'peasants'=> 1,
                'upkeep'  => 0.3,   // grain per turn
                'note'    => 'Cheap conscripts. They hold a wall and nothing more.',
            ],
            'legionnaire' => [
                'label'   => 'Legionnaire',
                'plural'  => 'Legionnaires',
                'offence' => 1,
                'defence' => 9,
                'gold'    => 340,
                'iron'    => 25,
                'peasants'=> 1,
                'upkeep'  => 0.5,
                'note'    => 'Professional defenders, and the backbone of any empire that expects to be hit.',
            ],
            'centurion' => [
                'label'   => 'Centurion',
                'plural'  => 'Centurions',
                'offence' => 9,
                'defence' => 1,
                'gold'    => 380,
                'iron'    => 30,
                'peasants'=> 1,
                'upkeep'  => 0.5,
                'note'    => 'Officers who lead from the front. At home they are little better than pawns.',
            ],
            'ballista_legion' => [
                'label'   => 'Ballista Legion',
                'plural'  => 'Ballistae Legions',
                'offence' => 18,
                'defence' => 4,
                'gold'    => 1400,
                'iron'    => 180,
                'peasants'=> 3,
                'upkeep'  => 1.5,
                'note'    => 'Slow, ruinously expensive, and the only thing that reliably breaks fortifications.',
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
            throw new IDO_Game_Exception('No such troop type.');
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

    /** The empires table column holding the standing count of a unit. */
    public static function column(string $key): string {
        return 'u_' . $key;
    }

    /** Total standing troops on an empire row, summed safely and clamped. */
    public static function total(object $kingdom): int {
        $total = 0.0;
        foreach (self::keys() as $key) {
            $total += (float) $kingdom->{self::column($key)};
        }
        return IDO_Game::clamp($total);
    }

    /** Grain eaten per turn by the standing army. */
    public static function upkeep(object $kingdom): float {
        $upkeep = 0.0;
        foreach (self::all() as $key => $unit) {
            $upkeep += $unit['upkeep'] * (int) $kingdom->{self::column($key)};
        }
        return $upkeep;
    }

    /**
     * Defensive strength of the troops standing at home, before fortifications.
     * Ballistae legions left behind still count, at their poor defence value.
     *
     * The fortification bonus is deliberately not applied here. Siege engines
     * man the same walls, so the multiplier is applied once by
     * IDO_Military::defence_power() over the troops and the engines together.
     */
    public static function defence_power(object $kingdom): float {
        $power = 0.0;
        foreach (self::all() as $key => $unit) {
            $power += $unit['defence'] * (int) $kingdom->{self::column($key)};
        }
        return $power;
    }

    /** Offensive strength of a named force, e.g. ['centurion' => 500]. */
    public static function offence_power(array $force): float {
        $power = 0.0;
        foreach ($force as $key => $qty) {
            if (!self::exists($key)) continue;
            $power += self::get($key)['offence'] * max(0, (int) $qty);
        }
        return $power;
    }

    /** Net worth credited for the standing army. */
    public static function networth(object $kingdom): int {
        $worth = 0.0;
        foreach (self::all() as $key => $unit) {
            $worth += round($unit['gold'] / 2) * (float) $kingdom->{self::column($key)};
        }
        return IDO_Game::clamp($worth);
    }
}
