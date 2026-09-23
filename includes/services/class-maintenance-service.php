<?php
if (!defined('ABSPATH')) exit;

/**
 * Scheduled upkeep. Runs from WP-Cron, from the admin Maintenance page, or from
 * the scripts in /maintenance when the site has a real system cron.
 *
 * Running from more than one of those at once has to be safe, because a shared
 * host often cannot disable WP-Cron at all. Two things make it safe:
 *
 *  - A named database lock. Only one tick of a kind runs at a time, whatever
 *    started it, so two processes firing in the same second cannot both do the
 *    work. Without this the "has it run recently" check below is a race: both
 *    read the old timestamp before either writes the new one.
 *  - A period check, taken again inside the lock. The second caller sees the
 *    timestamp the first one wrote and stands down.
 *
 * The underlying work is written to be idempotent as well, so even a tick that
 * somehow ran twice would not grant two days of turns.
 */
class IDO_Maintenance {
    const HOURLY_HOOK = 'ido_hourly_maintenance';
    const DAILY_HOOK  = 'ido_daily_maintenance';

    /** Schedules or clears the WP-Cron events to match the setting. */
    public static function apply_schedule(): void {
        if (IDO_Settings::int('use_wp_cron')) {
            self::schedule();
        } else {
            self::unschedule();
        }
    }

    public static function schedule(): void {
        if (!IDO_Settings::int('use_wp_cron')) return;

        if (!wp_next_scheduled(self::HOURLY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::HOURLY_HOOK);
        }
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            // First run at the next local midnight.
            $midnight = new DateTime('tomorrow', wp_timezone());
            wp_schedule_event($midnight->getTimestamp(), 'daily', self::DAILY_HOOK);
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::HOURLY_HOOK);
        wp_clear_scheduled_hook(self::DAILY_HOOK);
    }

    /** Market lots expire and a finished round is wound up. */
    public static function hourly(bool $force = false, string $source = 'WP-Cron'): string {
        $round = IDO_Rounds::current();
        if (!$round) return 'No round is running.';

        if (!IDO_Lock::acquire('maintenance_hourly', 0)) {
            return 'Hourly upkeep skipped: another run is already in progress.';
        }

        try {
            // Checked again now the lock is held: a run that started a moment
            // ago may have finished while this one was waiting.
            $last = strtotime((string) get_option('ido_last_hourly'));
            if (!$force && $last && current_time('timestamp') - $last < 50 * MINUTE_IN_SECONDS) {
                return 'Hourly upkeep skipped: it already ran at ' . get_option('ido_last_hourly') . '.';
            }

            $expired = IDO_Market::expire((int) $round->id);
            $rollover = IDO_Rounds::maybe_roll_over();

            self::record('hourly', $source);
            return sprintf('Hourly upkeep: %d market lots returned to their owners.%s',
                $expired, $rollover ? ' ' . $rollover : '');
        } finally {
            IDO_Lock::release('maintenance_hourly');
        }
    }

    /** Turns are granted, building work finishes, old news is cleared. */
    public static function daily(bool $force = false, string $source = 'WP-Cron'): string {
        global $wpdb;
        $round = IDO_Rounds::current();
        if (!$round) return 'No round is running.';

        if (!IDO_Lock::acquire('maintenance_daily', 0)) {
            return 'Daily upkeep skipped: another run is already in progress.';
        }

        try {
            $today = IDO_Game::today();
            if (!$force && substr((string) get_option('ido_last_daily'), 0, 10) === $today) {
                return 'Daily upkeep skipped: it already ran today at ' . get_option('ido_last_daily') . '.';
            }

            $granted = IDO_Kingdom::grant_daily_turns((int) $round->id);
            $built   = IDO_Construction::complete_due((int) $round->id);
            IDO_Market::expire((int) $round->id);

            $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - IDO_Settings::int('news_retention_days') * DAY_IN_SECONDS);
            $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('news') . ' WHERE created_at < %s', $cutoff));

            $rollover = IDO_Rounds::maybe_roll_over();

            self::record('daily', $source);
            return sprintf(
                'Daily upkeep: turns granted to %d kingdoms, %d buildings finished.%s',
                $granted, $built, $rollover ? ' ' . $rollover : ''
            );
        } finally {
            IDO_Lock::release('maintenance_daily');
        }
    }

    /** Notes when a tick ran and what started it, for the Maintenance screen. */
    private static function record(string $which, string $source): void {
        update_option('ido_last_' . $which, IDO_Game::now(), false);
        update_option('ido_last_' . $which . '_source', sanitize_text_field($source), false);
    }

    public static function last_source(string $which): string {
        $source = (string) get_option('ido_last_' . $which . '_source', '');
        return $source !== '' ? $source : 'unknown';
    }

    /**
     * Grants turns on demand for a single kingdom that has not had today's
     * allowance yet, so a ruler who logs in before cron has run is not left
     * waiting. The guarded UPDATE makes this safe to race with the daily tick.
     */
    public static function catch_up(object $kingdom): void {
        if ($kingdom->last_turn_grant === IDO_Game::today() || (int) $kingdom->is_defeated === 1) return;
        $per_day = IDO_Settings::int('turns_per_day');
        $cap     = IDO_Settings::int('turn_cap');
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('kingdoms')
            . ' SET turns = GREATEST(turns, LEAST(%d, turns + %d)), last_turn_grant = %s'
            . ' WHERE id = %d AND (last_turn_grant IS NULL OR last_turn_grant <> %s)',
            $cap, $per_day, IDO_Game::today(), (int) $kingdom->id, IDO_Game::today()
        ));
    }
}
