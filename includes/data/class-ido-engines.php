<?php
/**
 * Siege engines: war machines an empire builds rather than trains.
 *
 * An engine is not a building and not a troop. It stands on no acre, so
 * capturing one never has to find land to put it on; it costs no peasants and
 * so never touches the tax base. What makes an engine worth the gold is also
 * what makes it a gamble: engines march out with the army, and the losing side
 * of a battle hands a share of them to the winner and watches the rest burn.
 *
 * As with buildings and units, each key is also a column on the ido_kingdoms
 * table and must not change without a migration. Engines carry no prefix: the
 * column is the key itself.
 */
if (!defined('ABSPATH')) exit;

class IDO_Engines {

    public static function all(): array {
        return [
            'catapult' => [
                'label'   => 'Catapult',
                'plural'  => 'Catapults',
                'offence' => 5,
                'defence' => 3,
                'upkeep'  => 0.8,   // grain per turn, for the crews
                'effect'  => 'Marches with your army and stands on your walls when it does not. '
                           . 'Whichever side loses a battle gives up a share of the catapults it had at stake.',
                'note'    => 'Built, not trained: no peasants leave the fields for it and it stands on no acre. '
                           . 'Send them to war to hit harder, and risk handing them to the enemy if the day goes badly.',
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
            throw new IDO_Game_Exception('No such siege engine.');
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

    /** The ido_kingdoms column holding the standing count of an engine. */
    public static function column(string $key): string {
        return $key . 's';
    }

    /** The column holding engines of this kind still being built. */
    public static function progress_column(string $key): string {
        return $key . 's_in_progress';
    }

    /** Gold and iron price of building one engine. */
    public static function cost(string $key, int $qty = 1): array {
        self::get($key);
        return [
            'gold' => IDO_Settings::int($key . '_gold_cost') * $qty,
            'iron' => IDO_Settings::int($key . '_iron_cost') * $qty,
        ];
    }

    /** Total standing engines on an empire row, summed safely and clamped. */
    public static function total(object $kingdom): int {
        $total = 0.0;
        foreach (self::keys() as $key) {
            $total += (float) $kingdom->{self::column($key)};
        }
        return IDO_Game::clamp($total);
    }

    /** Grain eaten per turn by the engine crews. */
    public static function upkeep(object $kingdom): float {
        $upkeep = 0.0;
        foreach (self::all() as $key => $engine) {
            $upkeep += $engine['upkeep'] * (int) $kingdom->{self::column($key)};
        }
        return $upkeep;
    }

    /** Defensive strength of the engines standing at home, before fortifications. */
    public static function defence_power(object $kingdom): float {
        $power = 0.0;
        foreach (self::all() as $key => $engine) {
            $power += $engine['defence'] * (int) $kingdom->{self::column($key)};
        }
        return $power;
    }

    /** Offensive strength of a named train of engines, e.g. ['catapult' => 40]. */
    public static function offence_power(array $train): float {
        $power = 0.0;
        foreach ($train as $key => $qty) {
            if (!self::exists($key)) continue;
            $power += self::get($key)['offence'] * max(0, (int) $qty);
        }
        return $power;
    }

    /**
     * Net worth credited for the standing engines. Half the gold price, the
     * same rule the army is valued by, so a captured engine moves net worth
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
