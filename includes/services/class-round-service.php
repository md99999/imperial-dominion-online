<?php
if (!defined('ABSPATH')) exit;

/**
 * Rounds. A round runs for a fixed number of days; when it ends the standings
 * are copied into the Hall of Fame, every empire is retired, and (optionally) a
 * fresh round begins at once so latecomers always have a clean slate to join.
 */
class IDO_Rounds {

    private static ?object $cache = null;

    /**
     * Whether $cache has been filled. Kept separate from $cache itself because
     * "no round is open" is a real answer worth caching, and null cannot say
     * both "nothing found" and "not looked yet".
     */
    private static bool $cached = false;

    /** The round currently being played, or null if none is open. */
    public static function current(): ?object {
        if (self::$cached) return self::$cache;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('rounds') . ' WHERE status = %s ORDER BY id DESC LIMIT 1',
            'active'
        ));
        self::$cache = $row ?: null;
        self::$cached = true;
        return self::$cache;
    }

    public static function current_id(): int {
        $round = self::current();
        return $round ? (int) $round->id : 0;
    }

    public static function flush_cache(): void {
        self::$cache = null;
        self::$cached = false;
    }

    public static function get(int $round_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('rounds') . ' WHERE id = %d', $round_id
        ));
        return $row ?: null;
    }

    /** Days left in the current round, or null when no round is running. */
    public static function days_left(?object $round = null): ?int {
        $round = $round ?: self::current();
        if (!$round || !$round->ends_at) return null;
        $left = strtotime($round->ends_at) - current_time('timestamp');
        return max(0, (int) ceil($left / DAY_IN_SECONDS));
    }

    /**
     * Opens a new round. Any round still marked active is closed out first.
     * The name is always generated from the round number: there is nothing for
     * a game master to decide, and a hand-typed name only risks two rounds
     * sharing one in the Hall of Fame.
     */
    public static function start(int $days = 0): object {
        global $wpdb;
        $open = self::current();
        if ($open) self::conclude($open);

        $days = $days > 0 ? $days : IDO_Settings::int('round_days');
        $now  = current_time('timestamp');
        $number = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . IDO_DB::t('rounds')) + 1;
        $name = sprintf('Round %d', $number);

        $inserted = $wpdb->insert(IDO_DB::t('rounds'), [
            'round_name' => $name,
            'status'     => 'active',
            'starts_at'  => date('Y-m-d H:i:s', $now),
            'ends_at'    => date('Y-m-d H:i:s', $now + $days * DAY_IN_SECONDS),
            'created_at' => IDO_Game::now(),
        ], ['%s', '%s', '%s', '%s', '%s']);

        self::flush_cache();
        $round = $inserted ? self::get((int) $wpdb->insert_id) : null;
        if (!$round) {
            // Almost always a schema that did not install; say so rather than
            // dying on a return type a page or two later.
            throw new IDO_Game_Exception('The round could not be created. Check that the game tables exist.');
        }
        delete_option('ido_activation_error');
        IDO_Log::news('round', sprintf('%s has begun in %s. The land is unclaimed and every empire is unclaimed.', $name, IDO_Game::dominion()), (int) $round->id);
        IDO_Log::admin('round_start', sprintf('Started %s, ending %s.', $name, $round->ends_at));
        return $round;
    }

    /** True once the clock has run out on the open round. */
    public static function is_expired(?object $round = null): bool {
        $round = $round ?: self::current();
        if (!$round || !$round->ends_at) return false;
        return strtotime($round->ends_at) <= current_time('timestamp');
    }

    /**
     * Closes a round: archives the standings, marks it completed and retires its
     * empires. Returns a short summary line for the log.
     */
    public static function conclude(object $round): string {
        global $wpdb;
        $kingdoms = IDO_Rankings::standings((int) $round->id, 100);
        $position = 0;
        foreach ($kingdoms as $kingdom) {
            $position++;
            $wpdb->insert(IDO_DB::t('hall'), [
                'round_id'    => (int) $round->id,
                'round_name'  => $round->round_name,
                'position'    => $position,
                'kingdom_name'  => $kingdom->kingdom_name,
                'ruler_name'  => $kingdom->ruler_name,
                'user_id'     => (int) $kingdom->user_id,
                'title'       => IDO_Game::title((int) $kingdom->networth),
                'networth'    => (int) $kingdom->networth,
                'land'        => (int) $kingdom->land,
                'attacks_won' => (int) $kingdom->attacks_won,
                'recorded_at' => IDO_Game::now(),
            ], ['%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s']);
        }

        // Nothing is left sitting in escrow once the round is over.
        IDO_Market::close_round((int) $round->id);

        $wpdb->update(IDO_DB::t('rounds'),
            ['status' => 'completed', 'completed_at' => IDO_Game::now()],
            ['id' => (int) $round->id],
            ['%s', '%s'], ['%d']
        );

        $champion = $kingdoms[0] ?? null;
        if ($champion) {
            IDO_Log::news('round', sprintf(
                '%1$s is over. %2$s of %3$s ends the round as %4$s of %5$s, with a net worth of %6$s.',
                $round->round_name, $champion->ruler_name, $champion->kingdom_name,
                IDO_Game::title((int) $champion->networth), IDO_Game::dominion(),
                IDO_Game::fmt($champion->networth)
            ), (int) $round->id);
        }

        self::flush_cache();
        return sprintf('%s concluded with %d empires archived.', $round->round_name, count($kingdoms));
    }

    /**
     * Called from cron. Concludes the round if its time is up and, when the
     * setting allows, opens the next one immediately.
     */
    public static function maybe_roll_over(): ?string {
        $round = self::current();
        if (!$round || !self::is_expired($round)) return null;
        $summary = self::conclude($round);
        if (IDO_Settings::int('auto_start_next_round')) {
            $next = self::start();
            $summary .= sprintf(' %s has begun.', $next->round_name);
        }
        IDO_Log::admin('round_end', $summary);
        return $summary;
    }
}
