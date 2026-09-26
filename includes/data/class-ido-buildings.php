<?php
/**
 * The building types an empire can raise on its land. Each building occupies one
 * acre; unbuilt acres are wilderness and produce nothing.
 *
 * Every column here is a real column on the ido_kingdoms table (prefixed b_), so the
 * key names are also part of the schema and must not change without a migration.
 */
if (!defined('ABSPATH')) exit;

class IDO_Buildings {

    /** Net worth credited for each standing building. */
    const NETWORTH_PER_BUILDING = 1000;

    /** Fortifications cannot lift defence beyond this multiplier. */
    const MAX_FORTIFICATION_BONUS = 0.50;

    /** Barracks cannot discount training beyond this share of the gold price. */
    const MAX_BARRACKS_DISCOUNT = 0.35;

    /**
     * key => label, plural, what it does, and the per-turn yield used by the
     * economy service. Yields are per building, per turn spent.
     */
    public static function all(): array {
        return [
            'homestead' => [
                'label'   => 'Homestead',
                'plural'  => 'Homesteads',
                'effect'  => 'Houses 30 peasants. Peasants pay your taxes, so homesteads set the ceiling on every other income.',
                'yield'   => ['peasant_capacity' => 30],
            ],
            'farmstead' => [
                'label'   => 'Farmstead',
                'plural'  => 'Farmsteads',
                'effect'  => 'Produces 85 grain a turn. Grain feeds your peasants and your army; run out and both start to desert.',
                'yield'   => ['grain' => 85],
            ],
            'mint' => [
                'label'   => 'Mint',
                'plural'  => 'Mints',
                'effect'  => 'Produces 60 gold a turn on top of the taxes your peasants pay.',
                'yield'   => ['gold' => 60],
            ],
            'foundry' => [
                'label'   => 'Foundry',
                'plural'  => 'Foundries',
                'effect'  => 'Produces 25 iron a turn. Iron is needed for construction and for every soldier you train.',
                'yield'   => ['iron' => 25],
            ],
            'barracks' => [
                'label'   => 'Barracks',
                'plural'  => 'Barracks',
                'effect'  => 'Each barracks trims the gold price of training by 0.5%, to a maximum of 35%.',
                'yield'   => [],
            ],
            'fortification' => [
                'label'   => 'Fortification',
                'plural'  => 'Fortifications',
                'effect'  => 'Each fortification adds 0.6% to your defence, to a maximum of 50%. Ballistae legions are built to break them.',
                'yield'   => [],
            ],
        ];
    }

    public static function keys(): array {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool {
        return array_key_exists($key, self::all());
    }

    public static function label(string $key): string {
        $all = self::all();
        return $all[$key]['label'] ?? ucfirst($key);
    }

    public static function plural(string $key): string {
        $all = self::all();
        return $all[$key]['plural'] ?? self::label($key);
    }

    /** The empires table column holding the standing count of a building. */
    public static function column(string $key): string {
        return 'b_' . $key;
    }

    /**
     * Total standing buildings on an empire row. Summed as a float and clamped,
     * so a corrupt row can never overflow the sum into a float and fail the
     * int return type, or wrap into a negative count.
     */
    public static function total(object $kingdom): int {
        $total = 0.0;
        foreach (self::keys() as $key) {
            $total += (float) $kingdom->{self::column($key)};
        }
        return IDO_Game::clamp($total);
    }

    /** Acres with nothing on them: land less standing buildings and work in progress. */
    public static function wilderness(object $kingdom): int {
        $free = (float) $kingdom->land - (float) self::total($kingdom) - (float) $kingdom->land_in_progress;
        return IDO_Game::clamp($free);
    }

    /** Defence multiplier granted by fortifications, e.g. 1.24 for 40 fortifications. */
    public static function fortification_bonus(object $kingdom): float {
        $bonus = 0.006 * (int) $kingdom->b_fortification;
        return 1.0 + min(self::MAX_FORTIFICATION_BONUS, $bonus);
    }

    /** Training discount granted by barracks, e.g. 0.20 for 40 barracks. */
    public static function barracks_discount(object $kingdom): float {
        return min(self::MAX_BARRACKS_DISCOUNT, 0.005 * (int) $kingdom->b_barracks);
    }
}
