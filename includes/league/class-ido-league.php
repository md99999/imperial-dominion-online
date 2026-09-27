<?php
if (!defined('ABSPATH')) exit;

/**
 * League play: whether this site is in one, and everything about the league
 * itself. Marches, packets and scoring live elsewhere; this is the state that
 * decides whether any of that exists at all.
 *
 * **League play is opt in and off by default.** Most sites running this plugin
 * will play locally and never join anything, and for those sites none of this
 * should exist: no tables, no REST route, no packets, no extra surface. The
 * endpoint is only registered once a site has actually joined a league, which
 * is the mitigation with the best ratio in the whole threat model.
 *
 * That is why the tables are created on opt in rather than at activation, and
 * removed again when a site leaves. A site that never plays a league carries
 * nothing from it.
 */
class IDO_League {

    const TABLES  = ['leagues', 'sites', 'invites', 'packets_in', 'packets_out'];
    const SETTING = 'league_enabled';

    /** Capped at what the inter-BBS node byte allowed, which is the ceiling worth keeping. */
    const MAX_SITES = 254;

    /** Recommended rather than enforced: past this, nobody reads the standings. */
    const ADVISED_SITES = 20;

    private static ?object $league = null;
    private static bool $loaded = false;

    /**
     * Whether the game master has opted in. This is a switch on the machinery,
     * not a statement that the site is playing: a site can be opted in and not
     * yet in a league, which is exactly the state the setup screen exists for.
     */
    public static function enabled(): bool {
        return IDO_Settings::int(self::SETTING) === 1;
    }

    /** The league this site belongs to, or null. */
    public static function league(): ?object {
        if (self::$loaded) return self::$league;
        self::$loaded = true;
        self::$league = null;

        if (!self::enabled() || !self::tables_exist()) return null;

        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . IDO_DB::t('leagues') . " WHERE status = 'active' ORDER BY id DESC LIMIT 1");
        self::$league = $row ?: null;
        return self::$league;
    }

    /** Playing: opted in, in a league, and not paused by the kill switch. */
    public static function active(): bool {
        $league = self::league();
        return $league !== null && (int) $league->paused === 0;
    }

    /**
     * Whether this site will expose the endpoint other league sites deliver to.
     *
     * Four things have to be true, and they are deliberately four separate
     * controls rather than one, because they answer different questions and are
     * reversed by different people.
     *
     *   1. The site has opted in to league play at all.
     *   2. It is actually in a league. A site that has opted in and joined
     *      nothing has nothing to receive, so the route is not registered.
     *   3. The game master has turned the endpoint on in the settings. It is
     *      off until somebody chooses otherwise, like every other switch here
     *      that opens something to the internet.
     *   4. Nobody has locked it shut in wp-config.php.
     *
     * The fourth is the one that matters most and it is the one this method
     * checks first. A setting lives in the database, so anything that can write
     * to the database can turn it back on, and that includes an attacker who
     * has taken an administrator account. IDO_LEAGUE_DISABLE_ENDPOINT is a
     * constant in a file, outside the reach of the admin screens entirely, and
     * a site that defines it cannot be talked into listening by anything short
     * of filesystem access.
     *
     * A site with the endpoint closed can still send. It cannot receive, which
     * in practice means it cannot play: a march it sends is resolved by the
     * defender and the result comes back to this endpoint. Closing it is a
     * decision to stop, not a way to play more safely, and the screens say so
     * rather than letting somebody discover it a week later when an army does
     * not come home.
     */
    public static function endpoint_enabled(): bool {
        if (defined('IDO_LEAGUE_DISABLE_ENDPOINT') && IDO_LEAGUE_DISABLE_ENDPOINT) return false;
        if (!self::enabled()) return false;
        if (IDO_Settings::int('league_endpoint') !== 1) return false;
        return self::league() !== null;
    }

    /** Why the endpoint is closed, in words, for the admin screens. */
    public static function endpoint_status(): string {
        if (defined('IDO_LEAGUE_DISABLE_ENDPOINT') && IDO_LEAGUE_DISABLE_ENDPOINT) {
            return 'Locked shut in wp-config.php. Nothing in these screens can open it.';
        }
        if (!self::enabled())                             return 'Closed: league play is off.';
        if (IDO_Settings::int('league_endpoint') !== 1) {
            return self::league() === null
                ? 'Closed. It is off until you turn it on.'
                : 'Closed. This site cannot receive marches or results until you turn it on in Settings.';
        }
        if (self::league() === null)                      return 'Closed: this site is not in a league.';
        if (self::paused())                               return 'Open, but all league traffic is paused.';
        return 'Open. Member sites of this league can deliver packets.';
    }

    /** The kill switch is deliberately separate from leaving: it stops traffic and keeps the league. */
    public static function paused(): bool {
        $league = self::league();
        return $league !== null && (int) $league->paused === 1;
    }

    public static function is_originator(): bool {
        $league = self::league();
        return $league !== null && (int) $league->is_originator === 1;
    }

    public static function forget(): void {
        self::$loaded = false;
        self::$league = null;
    }

    // -- Tables ------------------------------------------------------------

    public static function tables_exist(): bool {
        global $wpdb;
        $table = IDO_DB::t('leagues');
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function install_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = file_get_contents(IDO_PATH . 'sql/league.sql');
        if ($sql === false) return;
        dbDelta(str_replace(
            ['{prefix}', '{charset_collate}'],
            [$wpdb->prefix, $wpdb->get_charset_collate()],
            $sql
        ));
    }

    /**
     * Drops every league table.
     *
     * Only ever reached from an explicit choice on the setup screen, because it
     * destroys the record of who this site played and what it took. Leaving a
     * league does not do this on its own.
     */
    public static function drop_tables(): void {
        global $wpdb;
        foreach (self::TABLES as $table) {
            $wpdb->query('DROP TABLE IF EXISTS ' . IDO_DB::t($table));
        }
        self::forget();
    }

    // -- The ruleset -------------------------------------------------------

    /**
     * The settings a league governs, in a fixed order.
     *
     * A site that grants its rulers 200 turns a day wins a league without ever
     * fighting well, so every setting that affects play is the league's and
     * identical everywhere. Cosmetic ones (the world name, page titles) stay
     * local, because nothing about them changes who wins.
     *
     * The order is fixed because the fingerprint is computed over it, and a
     * fingerprint that depends on array order would differ between sites that
     * agree.
     */
    public static function governed_keys(): array {
        return [
            'turns_per_day', 'turn_cap', 'starting_turns', 'attack_turn_cost', 'op_turn_cost',
            'starting_land', 'starting_gold', 'starting_grain', 'starting_iron',
            'starting_peasants', 'starting_pawns', 'starting_legionnaires',
            'protection_hours', 'explore_base_acres',
            'build_gold_per_acre', 'build_iron_per_acre', 'build_days', 'demolish_refund_percent',
            'catapult_gold_cost', 'catapult_iron_cost', 'catapult_crew',
            'target_min_percent', 'target_max_percent', 'max_hits_per_target',
            'conquest_land_percent', 'catapult_capture_percent', 'catapult_destroy_percent',
            'agent_gold_cost', 'max_agents',
            'barbarians_enabled', 'barbarian_min_players', 'barbarian_top_ranks',
            'barbarian_chance_percent', 'barbarian_gold_percent', 'barbarian_grain_percent',
            'market_tax_percent', 'listing_days', 'max_listings_per_kingdom',
            'round_days',
        ];
    }

    /** The governed settings as they stand here, for publishing or comparing. */
    public static function ruleset(): array {
        $out = [];
        foreach (self::governed_keys() as $key) {
            $value = IDO_Settings::get($key);
            if ($value === null) continue;
            $out[$key] = (int) $value;
        }
        return $out;
    }

    /**
     * A hash of the governed settings, which rides in every packet.
     *
     * Distribution alone is not enough, because a site can change its copy
     * back. This is what makes that visible: a member whose fingerprint does
     * not match is told which setting differs rather than silently losing.
     *
     * It does not stop a determined cheat, and it is important to be honest
     * about that. An administrator owns their database and can set an empire's
     * land to anything without touching a setting. This catches drift and
     * misconfiguration, which is most of it.
     */
    public static function fingerprint(?array $ruleset = null): string {
        $ruleset = $ruleset ?? self::ruleset();
        $parts = [];
        foreach (self::governed_keys() as $key) {
            $parts[] = $key . '=' . (int) ($ruleset[$key] ?? 0);
        }
        return hash('sha256', implode('&', $parts));
    }

    /** @return string[] the settings that differ, for telling a member why they are being ignored */
    public static function ruleset_diff(array $theirs): array {
        $ours = self::ruleset();
        $out  = [];
        foreach (self::governed_keys() as $key) {
            $a = (int) ($ours[$key] ?? 0);
            $b = (int) ($theirs[$key] ?? 0);
            if ($a !== $b) $out[] = sprintf('%s (here %d, league %d)', IDO_Game::label($key) ?: $key, $a, $b);
        }
        return $out;
    }

    // -- This site's identity in a league ----------------------------------

    // -- Time --------------------------------------------------------------

    /**
     * League time is UTC, always, everywhere in these tables.
     *
     * The local game stores site-local time, which is right for it: a turn
     * grant belongs to a calendar day somebody is living in. League time is the
     * opposite. A delay is a duration, members are in different countries, and
     * a battle must not move because a site changed timezone or the clocks went
     * back. So every datetime written to a league table is UTC and every one
     * displayed is converted at the point of display.
     *
     * Mixing the two is not a theoretical risk: the daily turn grant broke
     * precisely because an absolute instant and a local calendar day were
     * treated as the same thing.
     */
    public static function now(): string {
        return gmdate('Y-m-d H:i:s');
    }

    /** A stored UTC datetime, shown in the site's own timezone. */
    public static function when(?string $utc): string {
        if (!$utc) return 'never';
        $timestamp = strtotime($utc . ' UTC');
        return $timestamp ? (string) wp_date('Y-m-d H:i T', $timestamp) : 'unknown';
    }

    /** The URL a peer will call us on, taken from WordPress rather than typed. */
    public static function own_url(): string {
        return (string) IDO_League_URL::normalize((string) home_url());
    }

    public static function own_name(): string {
        $name = trim((string) get_bloginfo('name'));
        return $name !== '' ? $name : 'A site with no name';
    }
}
