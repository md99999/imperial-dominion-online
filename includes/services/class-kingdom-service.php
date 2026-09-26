<?php
if (!defined('ABSPATH')) exit;

/**
 * Empires: creation, lookup, and the two primitives every other service is built
 * on, paying resources and spending turns.
 *
 * All spending goes through pay(), which writes a single guarded UPDATE
 * (... WHERE gold >= cost) and checks the affected row count. Two requests from
 * the same ruler can therefore never spend the same gold twice, no matter how
 * they interleave.
 */
class IDO_Kingdom {

    const MIN_NAME = 3;
    const MAX_NAME = 40;



    /** The empire of the logged-in user in the open round, or null. */
    public static function current(): ?object {
        $user_id = get_current_user_id();
        $round_id = IDO_Rounds::current_id();
        if (!$user_id || !$round_id) return null;
        return self::by_user($user_id, $round_id);
    }

    public static function by_user(int $user_id, int $round_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('kingdoms') . ' WHERE user_id = %d AND round_id = %d',
            $user_id, $round_id
        ));
        return $row ?: null;
    }

    /**
     * Rows are deliberately never cached: services write to them through
     * guarded UPDATEs and then re-read, so a cached copy would be stale exactly
     * where correctness matters most.
     */
    public static function find(int $kingdom_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('kingdoms') . ' WHERE id = %d', $kingdom_id
        ));
        return $row ?: null;
    }

    /** Re-reads an empire row after it has been written to. */
    public static function reload(object $kingdom): object {
        $fresh = self::find((int) $kingdom->id);
        if (!$fresh) throw new IDO_Game_Exception('Your empire could not be found.');
        return $fresh;
    }

    /** Founds an empire for the logged-in user in the open round. */
    public static function create(int $user_id, string $kingdom_name, string $ruler_name): object {
        global $wpdb;

        if (!IDO_Settings::int('allow_new_kingdoms')) {
            throw new IDO_Game_Exception('The heralds are turning away new claimants for now.');
        }
        $round = IDO_Rounds::current();
        if (!$round) {
            throw new IDO_Game_Exception('No round is running. Ask the game master to open one.');
        }
        if (!$user_id) {
            throw new IDO_Game_Exception('You must be signed in to claim an empire.');
        }
        if (self::by_user($user_id, (int) $round->id)) {
            throw new IDO_Game_Exception('You already rule an empire this round.');
        }

        $kingdom_name = self::clean_name($kingdom_name, 'empire');
        $ruler_name = self::clean_name($ruler_name ?: wp_get_current_user()->display_name, 'ruler');

        // Both names must be unique within the round. The database enforces this
        // too; checking here is only so the player gets a useful message rather
        // than a failed insert.
        $clash = $wpdb->get_row($wpdb->prepare(
            'SELECT kingdom_name, ruler_name FROM ' . IDO_DB::t('kingdoms')
            . ' WHERE round_id = %d AND (kingdom_name = %s OR ruler_name = %s) LIMIT 1',
            (int) $round->id, $kingdom_name, $ruler_name
        ));
        if ($clash) {
            throw new IDO_Game_Exception(self::same_name($clash->kingdom_name, $kingdom_name)
                ? 'Another empire in this round already goes by that name. Choose another.'
                : 'Another ruler in this round already goes by that name. Choose another.');
        }

        $protection = IDO_Settings::int('protection_hours');
        $data = [
            'round_id'         => (int) $round->id,
            'user_id'          => $user_id,
            'kingdom_name'       => $kingdom_name,
            'ruler_name'       => $ruler_name,
            'turns'            => IDO_Settings::int('starting_turns'),
            'last_turn_grant'  => IDO_Game::today(),
            'land'             => IDO_Settings::int('starting_land'),
            'gold'             => IDO_Settings::int('starting_gold'),
            'grain'            => IDO_Settings::int('starting_grain'),
            'iron'             => IDO_Settings::int('starting_iron'),
            'peasants'         => IDO_Settings::int('starting_peasants'),
            'u_pawn'           => IDO_Settings::int('starting_pawns'),
            'u_legionnaire'    => IDO_Settings::int('starting_legionnaires'),
            'protection_until' => date('Y-m-d H:i:s', current_time('timestamp') + $protection * HOUR_IN_SECONDS),
            'created_at'       => IDO_Game::now(),
            'last_seen'        => IDO_Game::now(),
        ];
        // A starting empire arrives with a little of everything already standing.
        $land = (int) $data['land'];
        $data['b_homestead']      = (int) round($land * 0.24);
        $data['b_farmstead']      = (int) round($land * 0.24);
        $data['b_mint'] = (int) round($land * 0.12);
        $data['b_foundry']        = (int) round($land * 0.10);
        $data['b_barracks']       = (int) round($land * 0.05);
        $data['b_fortification']        = (int) round($land * 0.05);

        $ok = $wpdb->insert(IDO_DB::t('kingdoms'), $data);
        $kingdom = $ok ? self::find((int) $wpdb->insert_id) : null;
        if (!$kingdom) {
            // Two claimants submitting the same name at the same moment both pass
            // the check above; the unique index is what actually stops the second.
            if (stripos((string) $wpdb->last_error, 'duplicate') !== false) {
                throw new IDO_Game_Exception('Someone claimed that name a moment before you did. Choose another.');
            }
            throw new IDO_Game_Exception('Your claim could not be recorded. Please try again.');
        }

        self::recalc_networth($kingdom);
        IDO_Log::news('founding', sprintf('%s of %s has claimed a seat among the empires.', $ruler_name, $kingdom_name));
        return self::reload($kingdom);
    }

    /**
     * Whether two names are the same for uniqueness purposes: case is ignored,
     * matching the case-insensitive collation the database compares them with.
     */
    private static function same_name(string $a, string $b): bool {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
        }
        return strcasecmp($a, $b) === 0;
    }

    /** Validates and sanitises an empire or ruler name. */
    public static function clean_name(string $name, string $what = 'empire'): string {
        $name = sanitize_text_field(wp_strip_all_tags($name));
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($length < self::MIN_NAME || $length > self::MAX_NAME) {
            throw new IDO_Game_Exception(sprintf(
                'A %s name must be between %d and %d characters.', $what, self::MIN_NAME, self::MAX_NAME
            ));
        }
        if (!preg_match('/^[\p{L}\p{N} \'\-]+$/u', $name)) {
            throw new IDO_Game_Exception(sprintf('A %s name may use letters, numbers, spaces, hyphens and apostrophes only.', $what));
        }
        return $name;
    }

    /** Plain field update. Callers pass already-validated values. */
    public static function update(object $kingdom, array $fields): void {
        global $wpdb;
        if (!$fields) return;
        $wpdb->update(IDO_DB::t('kingdoms'), $fields, ['id' => (int) $kingdom->id], null, ['%d']);

    }

    /**
     * Applies signed deltas to numeric columns in one guarded statement.
     *
     * Two things are enforced here, and they are the whole defence against a
     * column ever going wrong:
     *
     *  - Nothing goes below zero. Every column that decreases carries a
     *    `>= cost` guard, so the write fails rather than leaving a negative.
     *  - Nothing goes above IDO_Game::MAX_VALUE. Every column that increases is
     *    wrapped in LEAST(), so a runaway value saturates at the ceiling
     *    instead of climbing until it overflows the column and wraps negative.
     *
     * Both are applied by the database inside the single UPDATE, so they hold
     * no matter which service called this or how two requests interleave.
     *
     * @param array $deltas column => signed integer
     * @throws IDO_Game_Exception when the empire cannot cover the cost
     */
    public static function pay(object $kingdom, array $deltas, string $shortfall_message = ''): void {
        global $wpdb;
        if (!$deltas) return;

        $allowed = self::numeric_columns();
        $cap = IDO_Game::MAX_VALUE;
        $sets = [];
        $set_args = [];
        $where = [];
        $where_args = [];

        foreach ($deltas as $column => $delta) {
            $delta = (int) $delta;
            if ($delta === 0 || !in_array($column, $allowed, true)) continue;

            if ($delta > 0) {
                if ($delta > $cap) {
                    throw new IDO_Game_Exception('That number is larger than this game can hold.');
                }
                $sets[] = "`$column` = LEAST(%d, `$column` + %d)";
                $set_args[] = $cap;
                $set_args[] = $delta;
            } else {
                $sets[] = "`$column` = `$column` + %d";
                $set_args[] = $delta;
                $where[] = "`$column` >= %d";
                $where_args[] = abs($delta);
            }
        }
        if (!$sets) return;

        $args = array_merge($set_args, $where_args);
        $args[] = (int) $kingdom->id;

        $sql = 'UPDATE ' . IDO_DB::t('kingdoms') . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . ($where ? implode(' AND ', $where) . ' AND ' : '') . 'id = %d';

        $rows = $wpdb->query($wpdb->prepare($sql, $args));

        if ($rows !== 1) {
            throw new IDO_Game_Exception($shortfall_message ?: 'Your treasury and stores cannot cover that.');
        }
    }

    /** Columns pay() is allowed to touch. */
    public static function numeric_columns(): array {
        $columns = ['turns', 'turns_spent', 'land', 'land_in_progress', 'gold', 'grain', 'iron',
            'peasants', 'agents', 'attacks_made', 'attacks_won', 'attacks_suffered',
            'land_taken', 'land_lost'];
        foreach (IDO_Buildings::keys() as $key) $columns[] = IDO_Buildings::column($key);
        foreach (IDO_Units::keys() as $key)     $columns[] = IDO_Units::column($key);
        foreach (IDO_Engines::keys() as $key) {
            $columns[] = IDO_Engines::column($key);
            $columns[] = IDO_Engines::progress_column($key);
        }
        return $columns;
    }

    /**
     * Spends turns and applies the income they generate. Every action in the
     * game funnels through here, which is what makes turns the real currency.
     *
     * @return array human-readable lines describing what the turns produced
     */
    public static function spend_turns(object $kingdom, int $turns): array {
        $turns = max(1, $turns);
        if ((int) $kingdom->turns < $turns) {
            throw new IDO_Game_Exception(sprintf(
                'That needs %d turns and you have %d. Turns are granted each day.', $turns, (int) $kingdom->turns
            ));
        }
        self::pay($kingdom, ['turns' => -$turns, 'turns_spent' => $turns], 'You do not have enough turns.');
        return IDO_Economy::advance(self::reload($kingdom), $turns);
    }

    /**
     * Grants the daily turn allowance, capped. Safe to call more than once a day.
     *
     * GREATEST keeps whatever the empire already holds: a game master who
     * lowers the cap should stop the pool growing, not take turns away that
     * were granted under the old ceiling. An empire above the cap simply
     * receives nothing until it has spent back below it.
     */
    public static function grant_daily_turns(int $round_id): int {
        global $wpdb;
        $per_day = IDO_Settings::int('turns_per_day');
        $cap     = IDO_Settings::int('turn_cap');
        $today   = IDO_Game::today();
        $rows = $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('kingdoms')
            . ' SET turns = GREATEST(turns, LEAST(%d, turns + %d)), last_turn_grant = %s'
            . ' WHERE round_id = %d AND is_defeated = 0 AND (last_turn_grant IS NULL OR last_turn_grant <> %s)',
            $cap, $per_day, $today, $round_id, $today
        ));
        return (int) $rows;
    }

    /** Recomputes and stores net worth, the single score the game ranks on. */
    /**
     * Recomputes and stores net worth, the single score the game ranks on.
     *
     * The sum is accumulated as a float rather than an integer. That is
     * deliberate: a float saturates towards infinity, which clamp() turns into
     * the ceiling, whereas an integer sum that exceeds PHP range wraps and
     * would hand the cheater the top of the leaderboard with a negative score.
     * The result is clamped into [0, MAX_VALUE] before it is stored.
     */
    public static function recalc_networth(object $kingdom): int {
        $worth = 0.0;
        $worth += (float) $kingdom->land * 500;
        $worth += (float) IDO_Buildings::total($kingdom) * IDO_Buildings::NETWORTH_PER_BUILDING;
        $worth += (float) $kingdom->peasants * 25;
        $worth += (float) IDO_Units::networth($kingdom);
        $worth += (float) IDO_Engines::networth($kingdom);
        $worth += (float) $kingdom->gold / 50;
        $worth += (float) $kingdom->grain / 200;
        $worth += (float) $kingdom->iron / 20;
        $worth += (float) $kingdom->agents * 50000;

        $worth = IDO_Game::clamp($worth);
        self::update($kingdom, ['networth' => $worth]);
        return $worth;
    }

    public static function touch(object $kingdom): void {
        global $wpdb;
        $wpdb->update(IDO_DB::t('kingdoms'), ['last_seen' => IDO_Game::now()], ['id' => (int) $kingdom->id], ['%s'], ['%d']);
    }

    public static function is_protected(object $kingdom): bool {
        return $kingdom->protection_until && strtotime($kingdom->protection_until) > current_time('timestamp');
    }

    /** Protection ends the moment a ruler marches on someone else. */
    public static function drop_protection(object $kingdom): void {
        if (self::is_protected($kingdom)) {
            self::update($kingdom, ['protection_until' => IDO_Game::now()]);
        }
    }
}
