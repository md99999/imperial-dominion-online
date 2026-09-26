<?php
/**
 * Core helpers shared by every part of the game: table names, settings, the
 * game-level exception, resource formatting, locking and logging.
 */
if (!defined('ABSPATH')) exit;

/**
 * Thrown by services when a command from a ruler cannot be carried out.
 * The message is shown to the player; $type controls the flash colour.
 */
class IDO_Game_Exception extends Exception {
    public string $type;
    public function __construct(string $message, string $type = 'error') {
        parent::__construct($message);
        $this->type = $type;
    }
}

class IDO_DB {
    const TABLES = ['rounds', 'kingdoms', 'constructions', 'listings', 'battles', 'ops', 'news', 'hall', 'admin_log'];

    public static function t(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'ido_' . $name;
    }
}

/**
 * Named advisory lock around the multi-row reads and writes that must not
 * interleave (combat, market purchases). wpdb has no transaction helper and a
 * site may be running storage weapons without them, so the critical section is
 * serialised explicitly.
 */
class IDO_Lock {
    private static array $held = [];

    public static function acquire(string $key, int $timeout = 5): bool {
        global $wpdb;
        $name = self::name($key);
        $ok = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout)) === 1;
        if ($ok) self::$held[$name] = true;
        return $ok;
    }

    public static function release(string $key): void {
        global $wpdb;
        $name = self::name($key);
        if (empty(self::$held[$name])) return;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        unset(self::$held[$name]);
    }

    /** Lock names are limited to 64 characters in MySQL 5.7 and later. */
    private static function name(string $key): string {
        global $wpdb;
        return substr('ido_' . md5($wpdb->prefix . $key), 0, 64);
    }
}

class IDO_Settings {
    const OPTION = 'ido_settings';

    public static function defaults(): array {
        return [
            // Blank means "name it after the WordPress site": see IDO_Game::dominion().
            'dominion_name'          => '',
            // Turns
            'turns_per_day'          => 10,
            // Three days' worth: enough to forgive a weekend away, not enough
            // to bank a fortnight and spend it in one sitting.
            'turn_cap'               => 30,
            // What an empire is founded with, so a new ruler has more than one
            // day's worth to learn the game with on their first sitting.
            'starting_turns'         => 15,
            'attack_turn_cost'       => 2,
            'op_turn_cost'           => 1,
            // Starting empire
            'starting_land'          => 250,
            'starting_gold'          => 75000,
            'starting_grain'         => 40000,
            'starting_iron'          => 5000,
            'starting_peasants'      => 1500,
            'starting_pawns'        => 200,
            'starting_legionnaires'  => 50,
            'protection_hours'       => 72,
            // Land and building
            'explore_base_acres'     => 30,
            'build_gold_per_acre'    => 300,
            'build_iron_per_acre'    => 15,
            'build_days'             => 1,
            'demolish_refund_percent'    => 20,
            // Siege weapons. Priced well above a fortification: a weapon that
            // can march, take land and change hands is not the same purchase
            // as a wall that only ever stands where it was built, and pricing
            // the two alike made the catapult the obvious buy every time.
            'catapult_gold_cost'     => 5000,
            'catapult_iron_cost'     => 500,
            // Legionnaires needed to work one catapult, both to haul it out on
            // an attack and to man it on the wall at home.
            'catapult_crew'          => 5,
            // War
            'target_min_percent'     => 40,
            'target_max_percent'     => 250,
            'max_hits_per_target'    => 3,
            'conquest_land_percent'  => 6,
            // What happens to the catapults the losing side had at stake: the
            // winner drags this share home, and a further share is smashed
            // where it stands. Together they are what a defeat costs in weapons,
            // so 30 and 10 means the loser is out 40% of what they committed.
            'catapult_capture_percent' => 30,
            'catapult_destroy_percent' => 10,
            // Covert
            'agent_gold_cost'        => 500000,
            'max_agents'             => 1,
            // Market
            'market_tax_percent'     => 5,
            'listing_days'           => 3,
            'max_listings_per_kingdom'  => 10,
            // Housekeeping
            'round_days'             => 45,
            'auto_start_next_round'  => 1,
            'news_retention_days'    => 14,
            // Off when a real cron calls the scripts in /maintenance instead.
            'use_wp_cron'            => 1,
            // A theme menu location, or blank for "do not touch the site menu".
            'menu_location'          => '',
            'allow_new_kingdoms'      => 1,
            // Deleting the plugin keeps the game's data unless this is turned on.
            'delete_data_on_uninstall' => 0,
        ];
    }

    /** Settings that are free text rather than integers. */
    public static function text_keys(): array {
        return ['dominion_name', 'menu_location'];
    }

    public static function all(): array {
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function get(string $key) {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function int(string $key): int {
        return (int) self::get($key);
    }

    public static function update(array $values): array {
        $current = self::all();
        foreach (self::defaults() as $key => $default) {
            if (!array_key_exists($key, $values)) continue;
            if (in_array($key, self::text_keys(), true)) {
                $current[$key] = sanitize_text_field((string) $values[$key]);
            } else {
                $current[$key] = max(0, (int) $values[$key]);
            }
        }
        $current['turns_per_day']      = max(1, (int) $current['turns_per_day']);
        $current['turn_cap']           = max((int) $current['turns_per_day'], (int) $current['turn_cap']);
        $current['round_days']         = max(1, (int) $current['round_days']);
        $current['max_agents']         = max(1, (int) $current['max_agents']);
        $current['target_max_percent'] = max((int) $current['target_min_percent'], (int) $current['target_max_percent']);
        $current['market_tax_percent'] = min(50, (int) $current['market_tax_percent']);
        $current['delete_data_on_uninstall'] = !empty($current['delete_data_on_uninstall']) ? 1 : 0;
        update_option(self::OPTION, $current);
        return $current;
    }
}

class IDO_Log {
    /** Public Imperial Gazette item, shown to every ruler. */
    public static function news(string $type, string $message, int $round_id = 0): void {
        global $wpdb;
        $wpdb->insert(IDO_DB::t('news'), [
            'round_id'   => $round_id ?: (int) IDO_Rounds::current_id(),
            'event_type' => $type,
            'message'    => $message,
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s']);
    }

    /** Administrative audit log. */
    public static function admin(string $type, string $message): void {
        global $wpdb;
        $wpdb->insert(IDO_DB::t('admin_log'), [
            'event_type' => $type,
            'message'    => $message,
            'user_id'    => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%d', '%s']);
    }
}

class IDO_Game {
    /** The name of the game. Not a setting: there is nothing here to configure. */
    const NAME = 'Imperial Dominion Online';

    /**
     * The credit in the footer bar. Not a setting either: it says who wrote
     * the game, which is not a fact about the site running it, and a site that
     * could edit it could also quietly claim the work as its own. The link
     * goes to the source, so anyone reading the footer can go and read it.
     */
    const CREDIT_TEXT = 'maddogproductions.online';
    const CREDIT_URL  = 'https://github.com/md99999/imperial-dominion-online';

    /**
     * The ceiling on every stored number in the game: gold, land, troops, net
     * worth, all of it. Nothing may exceed it and nothing may go below zero.
     *
     * Old door games were routinely broken by players who deliberately drove a
     * score past the width of its column so it wrapped around to a large
     * negative number, which ruined the rankings and sometimes the economy with
     * it. The defence is not a wider column, it is a hard ceiling that
     * saturates: a number that reaches the cap simply stays there.
     *
     * 9e15 is chosen to sit just under 2^53, so the value stays exactly
     * representable as a float in PHP and as a number in JavaScript, and far
     * under the signed BIGINT limit the database column can hold.
     */
    const MAX_VALUE = 9000000000000000;

    /**
     * Saturating clamp into [0, MAX_VALUE]. Takes a float so that a sum which
     * has already overflowed PHP integer range is still handled sensibly rather
     * than being cast into nonsense.
     *
     * A NAN resolves to zero, not to the ceiling. If the arithmetic has gone
     * wrong badly enough to produce one, the safe answer is the bottom of the
     * table: a broken number must never be able to outrank honest play.
     */
    public static function clamp($n): int {
        $n = (float) $n;
        if (is_nan($n)) return 0;
        if ($n <= 0) return 0;
        if ($n >= (float) self::MAX_VALUE) return self::MAX_VALUE;
        return (int) $n;
    }

    /** Stored resources, in the order they appear on the status bar. */
    const RESOURCES = [
        'gold'       => 'Gold',
        'grain'      => 'Grain',
        'iron'       => 'Iron',
    ];

    /**
     * Net worth thresholds and titles, lowest first.
     *
     * The lower half is a ladder a builder climbs on their own. From Duke up
     * it is meant to be an achievement, and it was not: a ruler who went to
     * war could reach the top of it comfortably inside a round, because taking
     * land takes it from someone who had already paid to build on it, so a
     * conqueror's net worth climbs far faster than a builder's. Those five
     * thresholds were tripled in 1.16.0 to put the summit back out of easy
     * reach. Titles already recorded in the hall of fame are left as they were
     * won, since a round is scored against the ladder that was standing at
     * the time.
     */
    const TITLES = [
        0 => 'Freeholder', 250000 => 'Thane', 750000 => 'Baron', 1500000 => 'Viscount',
        3000000 => 'Earl', 6000000 => 'Marquess', 36000000 => 'Duke', 75000000 => 'Archduke',
        150000000 => 'Prince', 300000000 => 'High King', 600000000 => 'Emperor',
    ];

    public static function title(int $networth): string {
        $title = 'Freeholder';
        foreach (self::TITLES as $threshold => $name) {
            if ($networth >= $threshold) $title = $name;
        }
        return $title;
    }

    public static function label(string $key): string {
        return self::RESOURCES[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * The name of this world: every empire on this site belongs to one
     * dominion, named after the WordPress site unless a game master overrides
     * it. When empires on different sites eventually go to war, this is the
     * name each side is known by.
     */
    public static function dominion(): string {
        $override = trim((string) IDO_Settings::get('dominion_name'));
        if ($override !== '') return $override;

        $site = trim(wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES));
        if ($site === '') return 'The Dominion';

        // Do not end up with "Westmarch Dominion Dominion".
        if (preg_match('/\bdominion\b/i', $site)) return $site;
        return $site . ' Dominion';
    }

    public static function fmt($n): string {
        return number_format_i18n((float) $n);
    }

    public static function today(): string {
        return current_time('Y-m-d');
    }

    public static function now(): string {
        return current_time('mysql');
    }

    /** Clamp a quantity supplied by a player to a sane, positive integer. */
    public static function qty($value, int $max = PHP_INT_MAX): int {
        $n = (int) $value;
        if ($n < 0) $n = 0;
        return min($n, $max);
    }
}
