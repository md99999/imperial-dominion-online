<?php
if (!defined('ABSPATH')) exit;

/**
 * Scheduled upkeep. Runs from WP-Cron, from the admin Maintenance page, or from
 * the scripts in /maintenance when the site has a real system cron.
 *
 * Both ticks are idempotent and refuse to run twice in the same period, so a
 * site can have WP-Cron and a system cron configured at once without doubling
 * anyone turns.
 */
class IDO_Maintenance {
    const HOURLY_HOOK = 'ido_hourly_maintenance';
    const DAILY_HOOK  = 'ido_daily_maintenance';

    public static function schedule(): void {
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
    public static function hourly(bool $force = false): string {
        $round = IDO_Rounds::current();
        if (!$round) return 'No round is running.';

        $last = strtotime((string) get_option('ido_last_hourly'));
        if (!$force && $last && current_time('timestamp') - $last < 50 * MINUTE_IN_SECONDS) {
            return 'Hourly upkeep skipped: it already ran at ' . get_option('ido_last_hourly') . '.';
        }

        $expired = IDO_Market::expire((int) $round->id);
        $rollover = IDO_Rounds::maybe_roll_over();

        update_option('ido_last_hourly', IDO_Game::now(), false);
        return sprintf('Hourly upkeep: %d market lots returned to their owners.%s',
            $expired, $rollover ? ' ' . $rollover : '');
    }

    /** Turns are granted, building work finishes, old news is cleared. */
    public static function daily(bool $force = false): string {
        global $wpdb;
        $round = IDO_Rounds::current();
        if (!$round) return 'No round is running.';

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

        update_option('ido_last_daily', IDO_Game::now(), false);
        return sprintf(
            'Daily upkeep: turns granted to %d kingdoms, %d buildings finished.%s',
            $granted, $built, $rollover ? ' ' . $rollover : ''
        );
    }

    /**
     * Grants turns on demand for a single kingdom that has not had today allowance
     * yet, so a ruler who logs in before cron has run is not left waiting.
     */
    public static function catch_up(object $kingdom): void {
        if ($kingdom->last_turn_grant === IDO_Game::today() || (int) $kingdom->is_defeated === 1) return;
        $per_day = IDO_Settings::int('turns_per_day');
        $cap     = IDO_Settings::int('turn_cap');
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('kingdoms')
            . ' SET turns = LEAST(%d, turns + %d), last_turn_grant = %s'
            . ' WHERE id = %d AND (last_turn_grant IS NULL OR last_turn_grant <> %s)',
            $cap, $per_day, IDO_Game::today(), (int) $kingdom->id, IDO_Game::today()
        ));
    }
}
