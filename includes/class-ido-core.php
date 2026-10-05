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

    /**
     * Guards against the one loop this class can create.
     *
     * The overlay asks IDO_League what the league governs, and IDO_League asks
     * IDO_Settings whether league play is switched on. Without this flag that is
     * infinite. With it, the inner call gets the site's own settings, which is
     * exactly what it needs to answer the question.
     */
    private static bool $overlaying = false;

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
            // What an order costs in turns. Build and train are what they have
            // always been; pulling down and standing down have always been free,
            // and stay free by default, because they undo a decision rather than
            // making one and the loss already falls on the refund.
            'build_turn_cost'        => 1,
            'demolish_turn_cost'     => 0,
            'train_turn_cost'        => 1,
            'disband_turn_cost'      => 0,
            // What a building makes. These were written into three places at
            // once -- the yield table, the sentence describing it on screen, and
            // a bare number inside the economy -- so changing one changed what
            // the game said without changing what it did. They are one number
            // now, and this is it.
            'mint_gold_yield'        => 60,
            'farmstead_grain_yield'  => 85,
            'foundry_iron_yield'     => 25,
            'homestead_capacity'     => 30,
            // Gold per hundred peasants per turn. Held per hundred because a
            // setting is a whole number and the rate is not: 55 means 0.55 each.
            'tax_per_100_peasants'   => 55,
            // A night's produce, granted on the daily tick without a turn being
            // spent. 0 keeps the old rule that nothing arrives unless a ruler
            // spends a turn on it. 1 means every empire wakes up with one turn's
            // worth already in the stores.
            'daily_yield_turns'      => 0,
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
            // Two tiers of spy. An informer is cheap and can only look; an
            // agent costs real money and can act. See IDO_Agents for why the
            // price came down rather than the risk going up.
            'agent_gold_cost'        => 200000,
            'max_agents'             => 1,
            'informer_gold_cost'     => 50000,
            'max_informers'          => 1,
            // Barbarians: a brake on a runaway leader, not a tax on everybody.
            'barbarians_enabled'       => 1,
            'barbarian_min_players'    => 10,
            'barbarian_top_ranks'      => 3,
            'barbarian_chance_percent' => 5,
            'barbarian_gold_percent'   => 10,
            'barbarian_grain_percent'  => 10,
            // Disasters: these fall on anybody, where barbarians only visit the
            // top of the table. One in 60 turns is a little under one a week at
            // ten turns a day, and never during any kind of grace period.
            'disasters_enabled'      => 1,
            'disaster_one_in'        => 60,
            'disaster_percent'       => 7,
            // Masterless empires: provinces no living ruler holds, for boards
            // with too few players to make war out of. Off by default, like
            // every other thing that changes the shape of a game.
            'rivals_enabled'         => 0,
            'rival_count'            => 10,
            'rival_regen_percent'    => 10,
            // Striking back. Off on top of the empires themselves being off: a
            // board can have somewhere to march without anything marching back.
            'rival_retaliation'      => 0,
            'rival_memory_days'      => 3,
            'rival_attack_chance'    => 35,
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
            // League play is opt in and off by default: a site that never joins
            // one should carry none of its tables, routes or attack surface.
            // An empire worth less than this share of a founding grant, and with
            // no army left, is ruined. Well under what a founding is worth, so
            // qualifying means destroying more than relief gives back.
            'defeat_threshold_percent' => 25,
            // The day between defeat and relief. Long enough to be felt, short
            // enough that nobody gives up waiting. 0 restores overnight.
            'defeat_grace_hours'       => 24,
            // A board worth less than this share of its founding grants is
            // finished, and is refounded on the next daily tick. 0 turns the
            // automatic reset off and leaves it to the game master.
            'board_ruin_percent'     => 25,
            'league_enabled'         => 0,
            // Whether this site exposes the route other league members deliver
            // to. Off by default, like every other switch here that opens
            // something to the internet: a site opts in to league play, joins a
            // league, and then decides separately to start listening. It cannot
            // receive, and so cannot really play, until it does.
            'league_endpoint'        => 0,
        ];
    }

    /** Settings that are free text rather than integers. */
    public static function text_keys(): array {
        return ['dominion_name', 'menu_location'];
    }

    /**
     * Every setting, with the league's own values overlaid where it governs.
     *
     * A site in a league does not get to decide the numbers that decide who wins.
     * Rather than copying the league's values into this site's settings row, where
     * they could be edited back, they are laid over the top at every read: the
     * league row is the single source of truth and the local row keeps whatever
     * the game master had, ready for when the site leaves.
     */
    public static function all(): array {
        $saved = get_option(self::OPTION, []);
        $settings = wp_parse_args(is_array($saved) ? $saved : [], self::defaults());

        if (!self::$overlaying && class_exists('IDO_League')) {
            self::$overlaying = true;
            try {
                foreach (IDO_League::settings_in_force() as $key => $value) {
                    if (array_key_exists($key, $settings)) $settings[$key] = $value;
                }
            } finally {
                self::$overlaying = false;
            }
        }

        return $settings;
    }

    public static function get(string $key) {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function int(string $key): int {
        return (int) self::get($key);
    }

    /**
     * Saves settings, ignoring any the league governs.
     *
     * Ignored rather than refused, so a game master pressing Save does not lose
     * the twenty changes they are allowed to make because one field on the screen
     * belongs to the league. The screen shows those fields as the league's, and
     * this makes sure that is true rather than merely displayed.
     */
    public static function update(array $values): array {
        $current = get_option(self::OPTION, []);
        $current = wp_parse_args(is_array($current) ? $current : [], self::defaults());

        foreach (self::defaults() as $key => $default) {
            if (!array_key_exists($key, $values)) continue;
            if (class_exists('IDO_League') && IDO_League::governs($key)) continue;
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
        $current['board_ruin_percent'] = min(90, (int) $current['board_ruin_percent']);
        $current['defeat_threshold_percent'] = min(90, (int) $current['defeat_threshold_percent']);
        $current['delete_data_on_uninstall'] = !empty($current['delete_data_on_uninstall']) ? 1 : 0;
        $current['league_enabled']           = !empty($current['league_enabled']) ? 1 : 0;
        $current['league_endpoint']          = !empty($current['league_endpoint']) ? 1 : 0;
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
