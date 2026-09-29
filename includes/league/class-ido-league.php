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

    const TABLES  = ['leagues', 'sites', 'invites', 'packets_in', 'packets_out',
                     'league_marches', 'league_contributions'];
    const SETTING = 'league_enabled';

    /** Capped at what the inter-BBS node byte allowed, which is the ceiling worth keeping. */
    const MAX_SITES = 254;

    /** Recommended rather than enforced: past this, nobody reads the standings. */
    const ADVISED_SITES = 20;

    /**
     * How long a march waits before it is fought, in whole days. Three to six,
     * and **not a setting.**
     *
     * Deliberately in the code rather than in the league's hands. The wait is not
     * a tuning knob, it is the mechanic: the attacker commits blind and finds out
     * days later, and a league that could shorten it to nothing would be playing
     * a different and worse game. Leaving it settable also left a real hole,
     * since the calendar arrives from the hub at enrolment and a hostile or
     * careless hub could have pushed a range of zero and made every march
     * instant. A constant on both sides cannot be pushed anywhere.
     *
     * Three days is the floor because the wait is counted in daily cron runs: a
     * packet arriving today is fought on the third daily tick after it. Six is
     * the ceiling, and the seventh day belongs to the ride home, so no exchange
     * runs longer than a week from the army leaving to the dispatches being
     * read.
     */
    const DELAY_MIN_DAYS = 3;
    const DELAY_MAX_DAYS = 6;

    /**
     * How long the dispatches take to ride home: one day, always.
     *
     * The outbound leg is a wide random draw because the suspense is the point.
     * The homeward leg is not suspense, it is a courier, and making it another
     * week would only mean a player who has already been waiting a week waits
     * another one for news of a battle that is over.
     *
     * One day rather than none, because it does the work of a whole state. The
     * result packet is sent the moment the battle is fought, arrives the same
     * day, and is staged rather than applied. While it sits staged the attacker
     * knows their army has arrived and is fighting, and the next daily tick
     * reads out the dispatches. That is where "In battle" comes from: not a
     * separate message to announce it, but a result already in hand and not yet
     * opened.
     */
    const RESULT_DELAY_DAYS = 1;

    /**
     * The draw for one packet: 3, 4, 5 or 6 days, evenly.
     *
     * random_int, so it is drawn from the CSPRNG rather than from something an
     * attacker could predict or grind. Drawn by the receiver, per packet, and
     * never derived from anything in the packet itself: a sender who could
     * influence the draw would be choosing when its own army lands, which is the
     * one part of the fight it must not control.
     */
    public static function delay_days(): int {
        return random_int(self::DELAY_MIN_DAYS, self::DELAY_MAX_DAYS);
    }

    /**
     * The worst case from calling a muster to the army coming home.
     *
     * Derived, never written down, so a change to the muster window moves the
     * end-of-round cutoff with it.
     */
    public static function longest_exchange_days(int $muster_days): int {
        return max(0, $muster_days) + self::DELAY_MAX_DAYS + self::RESULT_DELAY_DAYS;
    }

    private static ?object $league = null;
    private static bool $loaded = false;
    private static ?array $in_force = null;

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
        // Pending as well as active, which was a bug when this only read
        // active: joining records the enrolment as pending, so the League screen
        // could not see the very row it had just written and told the
        // administrator they were not in a league at all.
        $row = $wpdb->get_row(
            'SELECT * FROM ' . IDO_DB::t('leagues')
            . " WHERE status IN ('active', 'pending') ORDER BY id DESC LIMIT 1"
        );
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

    /** Enrolled but not yet a member: the handshake has not finished. */
    public static function pending(): bool {
        $league = self::league();
        return $league !== null && (string) $league->status === 'pending';
    }

    public static function is_originator(): bool {
        $league = self::league();
        return $league !== null && (int) $league->is_originator === 1;
    }

    public static function forget(): void {
        self::$loaded = false;
        self::$league = null;
        self::$in_force = null;
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

    /**
     * The governed values actually in force here, or an empty array.
     *
     * Read straight from the league row and cached, and deliberately *not*
     * through IDO_Settings, because IDO_Settings is the caller: the settings
     * overlay asks this method what to overlay. Going back through it would be a
     * loop.
     *
     * Empty until the first round boundary after joining. That is the whole
     * reason this is a separate column from `ruleset`: a member adopts the
     * league's numbers at a boundary, not the moment it enrols, because changing
     * turns a day or training costs under players who planned around them is
     * unfair in a way that has nothing to do with cheating.
     */
    public static function settings_in_force(): array {
        global $wpdb;

        if (self::$in_force !== null) return self::$in_force;
        self::$in_force = [];

        if (!self::tables_exist()) return self::$in_force;

        $json = $wpdb->get_var(
            'SELECT ruleset_in_force FROM ' . IDO_DB::t('leagues')
            . " WHERE status = 'active' ORDER BY id DESC LIMIT 1"
        );
        if (!$json) return self::$in_force;

        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) return self::$in_force;

        $governed = self::governed_keys();
        foreach ($decoded as $key => $value) {
            if (in_array($key, $governed, true) && is_numeric($value)) {
                self::$in_force[$key] = (int) $value;
            }
        }
        return self::$in_force;
    }

    /** Whether one setting is the league's to decide rather than this site's. */
    public static function governs(string $key): bool {
        return array_key_exists($key, self::settings_in_force());
    }

    /**
     * Brings the league's published ruleset into force. Called at a round boundary.
     *
     * The originator's own settings are the ruleset, so for the hub this is a
     * formality that keeps both sides on the same code path rather than a special
     * case nobody exercises.
     */
    public static function apply_ruleset(): string {
        global $wpdb;

        $league = self::league();
        if (!$league) return '';

        $published = json_decode((string) $league->ruleset, true);
        if (!is_array($published) || !$published) return '';

        $wpdb->update(IDO_DB::t('leagues'),
            ['ruleset_in_force' => wp_json_encode($published)], ['id' => (int) $league->id]);

        self::forget();
        IDO_Log::admin('league', sprintf(
            'The league ruleset (version %d) is now in force: %d settings are the league\'s.',
            (int) $league->ruleset_version, count($published)
        ));

        return sprintf('The league ruleset now governs %d settings.', count($published));
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

    // -- The calendar ------------------------------------------------------

    /**
     * When the season this site is playing started and ends, as absolute instants.
     *
     * **Computed, never stored, and never advanced by anybody.** Seasons run from
     * the league's founding instant in fixed steps of `round_days`, so season
     * three begins at start + 2 x days whoever is asking and whenever they ask.
     * Every member arrives at the same two timestamps from the same two numbers
     * with no message passing, no agreement to reach, and nothing to drift.
     *
     * The alternative was to advance a stored date at each boundary, which needs
     * every member to do it, exactly once, at the same time. A member that was
     * offline for a rollover would wake up a season behind and fight people who
     * had already wiped.
     *
     * UTC, because that is the whole point. A season that ended at each member's
     * local midnight would end up to a day apart for members in different
     * countries, and for that day one site would be playing a fresh round while
     * another finished the old one, with packets crossing between them. It is the
     * same mistake the daily turn grant made, at a larger scale.
     *
     * @return array{start:int,end:int,season:int}|null
     */
    public static function season(): ?array {
        $league = self::league();
        if (!$league || empty($league->round_starts_at)) return null;

        $days = max(1, (int) $league->round_days);
        $length = $days * DAY_IN_SECONDS;

        $start = strtotime((string) $league->round_starts_at . ' UTC');
        if ($start === false) return null;

        $now = time();
        $season = 1;
        if ($now > $start) {
            $season += (int) floor(($now - $start) / $length);
            $start += ($season - 1) * $length;
        }

        return ['start' => $start, 'end' => $start + $length, 'season' => $season];
    }

    /** When the current season ends, or null when this site is not in a league. */
    public static function season_ends_at(): ?int {
        $season = self::season();
        return $season ? $season['end'] : null;
    }

    /**
     * Whether the league calendar, rather than the local one, decides the round.
     *
     * A pending enrolment does not count: a site that has not finished joining is
     * still playing its own game and should keep its own calendar until it is
     * actually a member.
     */
    public static function owns_calendar(): bool {
        return self::active() && self::season() !== null;
    }

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
