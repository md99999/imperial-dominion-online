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

    /**
     * Re-aims the daily event at local midnight when the site timezone changes.
     *
     * WP-Cron stores an absolute instant, so an event scheduled while the site
     * was on UTC keeps firing at that instant afterwards: on a New York site
     * that is eight in the evening, and turns would arrive mid-evening for
     * good. Nobody is stranded meanwhile, because the catch-up on page load
     * grants as soon as the date rolls, but the hour should still be right.
     */
    public static function sync_timezone(): void {
        $now = wp_timezone()->getName();
        if ((string) get_option('ido_cron_timezone', '') === $now) return;

        update_option('ido_cron_timezone', $now, true);
        if (!IDO_Settings::int('use_wp_cron')) return;

        wp_clear_scheduled_hook(self::DAILY_HOOK);
        $midnight = new DateTime('tomorrow', wp_timezone());
        wp_schedule_event($midnight->getTimestamp(), 'daily', self::DAILY_HOOK);
    }

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
        // League traffic first, and before the round check, because packets have
        // to keep moving whether or not a round happens to be running here: a
        // peer waiting on a result should not be held up by this site's calendar.
        $league_note = self::league_traffic();

        $round = IDO_Rounds::current();
        if (!$round) return self::log_tick('hourly', $source,
            trim('No round is running. ' . $league_note), false);

        if (!IDO_Lock::acquire('maintenance_hourly', 0)) {
            return self::log_tick('hourly', $source,
                'Hourly upkeep skipped: another run is already in progress.', false);
        }

        try {
            // Checked again now the lock is held: a run that started a moment
            // ago may have finished while this one was waiting.
            $last = strtotime((string) get_option('ido_last_hourly'));
            if (!$force && $last && current_time('timestamp') - $last < 50 * MINUTE_IN_SECONDS) {
                return self::log_tick('hourly', $source,
                    'Hourly upkeep skipped: it already ran at ' . get_option('ido_last_hourly') . '.', false);
            }

            $expired = IDO_Market::expire((int) $round->id);

            $rollover = IDO_Rounds::maybe_roll_over();

            self::record('hourly', $source);
            return self::log_tick('hourly', $source, trim(sprintf(
                'Hourly upkeep: %d market lots returned to their owners.%s %s',
                $expired, $rollover ? ' ' . $rollover : '', $league_note)), true);
        } finally {
            IDO_Lock::release('maintenance_hourly');
        }
    }

    /**
     * Moves the league queues: sends what is due and applies what has waited.
     *
     * Its own lock, because it runs from both ticks and from the admin button,
     * and two runs at once would try to send the same packet twice. A packet is
     * idempotent at the receiver, so that would be survivable rather than
     * harmful, but it would also be a waste and would muddle the counts.
     */
    public static function league_traffic(bool $daily_tick = false): string {
        if (!IDO_League::active()) return '';
        if (!IDO_Lock::acquire('league_traffic', 0)) return 'League traffic skipped: already running.';

        try {
            $sent      = IDO_League_Queue::flush();
            // Only the daily run may land a march or a result. The hourly run
            // moves news and keeps the outbound queue going, which makes it the
            // safety net the design asks for without turning the dispatches into
            // something that arrives at any hour.
            $processed = IDO_League_Queue::process($daily_tick);

            // The muster closes and armies come home on the daily tick, beside
            // the packets. Both are things that happen to a ruler overnight.
            $closed  = $daily_tick ? IDO_League_Muster::close_due() : '';
            $lost    = $daily_tick ? IDO_League_March::release_timed_out() : 0;

            $parts = [];
            if (!empty($processed['held'])) {
                $parts[] = sprintf('%d packet(s) held: this site needs resynchronising with the league',
                    (int) $processed['held']);
            }
            if ($closed !== '')           $parts[] = $closed;
            if ($lost > 0)                $parts[] = sprintf('%d army/armies given up for lost and returned', $lost);
            if ($sent['sent'])            $parts[] = sprintf('%d packet(s) sent', $sent['sent']);
            if ($sent['failed'])          $parts[] = sprintf('%d send(s) failed', $sent['failed']);
            if ($processed['processed'])  $parts[] = sprintf('%d packet(s) applied', $processed['processed']);
            if ($processed['rejected'])   $parts[] = sprintf('%d packet(s) rejected', $processed['rejected']);

            return $parts ? 'League: ' . implode(', ', $parts) . '.' : '';
        } finally {
            IDO_Lock::release('league_traffic');
        }
    }

    /** Publishes this site's own figures to every paired peer, once a day. */
    public static function league_news(): string {
        if (!IDO_League::active()) return '';
        $queued = IDO_League_News::broadcast();
        return $queued['queued'] > 0
            ? sprintf('League: news queued for %d peer site(s).', $queued['queued'])
            : '';
    }

    /** Turns are granted, building work finishes, old news is cleared. */
    public static function daily(bool $force = false, string $source = 'WP-Cron'): string {
        global $wpdb;
        $round = IDO_Rounds::current();
        if (!$round) return self::log_tick('daily', $source, 'No round is running.', false);

        if (!IDO_Lock::acquire('maintenance_daily', 0)) {
            return self::log_tick('daily', $source,
                'Daily upkeep skipped: another run is already in progress.', false);
        }

        try {
            $today = IDO_Game::today();
            if (!$force && substr((string) get_option('ido_last_daily'), 0, 10) === $today) {
                return self::log_tick('daily', $source,
                    'Daily upkeep skipped: it already ran today at ' . get_option('ido_last_daily') . '.', false);
            }

            global $wpdb;
            $eligible = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_defeated = 0',
                (int) $round->id
            ));
            $granted = IDO_Kingdom::grant_daily_turns((int) $round->id);
            $built   = IDO_Construction::complete_due((int) $round->id);
            IDO_Market::expire((int) $round->id);

            $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - IDO_Settings::int('news_retention_days') * DAY_IN_SECONDS);
            $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('news') . ' WHERE created_at < %s', $cutoff));

            $rollover = IDO_Rounds::maybe_roll_over();
            // After the league traffic, so an army that came home tonight counts
            // toward whether the board can still stand.
            $league_note = trim(self::league_traffic(true) . ' ' . self::league_news());
            // One empire at a time first, then the whole board: an empire given
            // relief tonight counts toward whether the board can still stand.
            $ruined   = IDO_Board::mark_ruined((int) $round->id);
            $relieved = IDO_Board::relieve_due((int) $round->id);
            if ($ruined > 0) {
                $league_note = trim($league_note . sprintf(' %d empire(s) ruined.', $ruined));
            }
            if ($relieved > 0) {
                $league_note = trim($league_note . sprintf(' %d empire(s) given relief.', $relieved));
            }

            $reset_note = IDO_Board::reset_if_ruined((int) $round->id);
            if ($reset_note !== '') $league_note = trim($league_note . ' ' . $reset_note);

            // The masterless provinces are brought up to number and nursed back
            // towards what they were, before the leader is worked out.
            $rivals_made = IDO_Rivals::populate((int) $round->id);
            $rivals_back = IDO_Rivals::regenerate((int) $round->id);
            // After they have recovered, not before: a province strikes back
            // with the army it has this morning, which is the one a player left
            // it with plus a night's repair.
            $rivals_war  = IDO_Rivals::retaliate((int) $round->id);

            self::announce_leader((int) $round->id);

            self::record('daily', $source);
            // Saying how many were skipped matters: pressing Run now after the
            // tick has already run reports "0 granted", which reads as a fault
            // when the truth is that everyone already holds today's turns.
            if ($rivals_made > 0) {
                $league_note = trim($league_note . sprintf(' %d masterless empire(s) founded.', $rivals_made));
            }
            if ($rivals_back > 0) {
                $league_note = trim($league_note . sprintf(' %d recovering.', $rivals_back));
            }
            if (!empty($rivals_war['marched'])) {
                $league_note = trim($league_note . sprintf(' %d struck back.', (int) $rivals_war['marched']));
            }

            $skipped = max(0, $eligible - $granted);
            return self::log_tick('daily', $source, trim(sprintf(
                'Daily upkeep: turns granted to %d %s%s, %d buildings finished.%s %s',
                $granted,
                $granted === 1 ? 'empire' : 'empires',
                $skipped > 0 ? sprintf(' (%d already had today, so received nothing)', $skipped) : '',
                $built,
                $rollover ? ' ' . $rollover : '',
                $league_note
            )), true);
        } finally {
            IDO_Lock::release('maintenance_daily');
        }
    }

    /** How many days of each tick the log keeps. */
    const LOG_OPTION = 'ido_cron_log';
    const LOG_DAYS   = 10;

    /**
     * Records one invocation of a tick and returns its message unchanged, so a
     * caller can write `return self::log_tick(...)` at every exit and none of
     * them can be forgotten.
     *
     * One row per job per day. A tick can be started many times in a day --
     * WP-Cron and a server cron both firing, somebody pressing Run now, the
     * hourly tick by design -- and most of those starts do nothing, because the
     * second arrival finds the lock held or finds the work already done. The
     * count is of starts, which is the number that answers "is cron actually
     * reaching this site", while the time, the source and the summary belong to
     * the run that did the work.
     *
     * That is the whole point of keeping them apart. A skipped repeat must never
     * overwrite the run that did the work, or the log would show midnight's real
     * run replaced by "skipped: it already ran today" from a 1am duplicate, and
     * the screen would read as though turns were never granted.
     *
     * A later run that does work does replace an earlier one, which is right:
     * the hourly tick works many times a day and the most recent is the useful
     * one. The skip reason is kept separately and only shown on a day where
     * nothing worked at all, because then it is the only explanation available.
     */
    private static function log_tick(string $which, string $source, string $summary, bool $did_work): string {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) $log = [];

        $day   = IDO_Game::today();
        $entry = $log[$which][$day] ?? [
            'starts' => 0, 'worked' => 0, 'ran_at' => '', 'source' => '', 'summary' => '', 'note' => '',
        ];

        $entry['starts'] = (int) $entry['starts'] + 1;
        if ($did_work) {
            $entry['worked']  = (int) $entry['worked'] + 1;
            $entry['ran_at']  = IDO_Game::now();
            $entry['source']  = self::describe_source($source);
            $entry['summary'] = $summary;
        } else {
            $entry['note'] = $summary;
        }

        $log[$which][$day] = $entry;

        // Newest first, then cut. Keyed by day, so a tick started forty times in
        // one day is still one row and the log cannot grow without bound.
        krsort($log[$which]);
        $log[$which] = array_slice($log[$which], 0, self::LOG_DAYS, true);

        update_option(self::LOG_OPTION, $log, false);
        return $summary;
    }

    /**
     * What started a run, in words a person reads rather than a token.
     *
     * 'admin' means somebody pressed Run now, and which somebody is worth
     * recording: on a site with more than one administrator, a tick that ran at
     * an odd hour is a different thing depending on whether a person or a
     * machine started it.
     */
    private static function describe_source(string $source): string {
        if ($source !== 'admin') return sanitize_text_field($source);

        $user = wp_get_current_user();
        $who  = ($user && $user->exists()) ? $user->user_login : '';
        return $who !== '' ? 'Run now by ' . sanitize_text_field($who) : 'Run now';
    }

    /**
     * The log for the Maintenance screen: newest day first, per job.
     *
     * @return array<string, array<string, array>>
     */
    public static function log(): array {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) return [];

        foreach ($log as $which => $days) {
            if (!is_array($days)) { unset($log[$which]); continue; }
            krsort($days);
            $log[$which] = array_slice($days, 0, self::LOG_DAYS, true);
        }
        return $log;
    }

    /** Notes when a tick ran and what started it, for the Maintenance screen. */
    private static function record(string $which, string $source): void {
        update_option('ido_last_' . $which, IDO_Game::now(), false);
        update_option('ido_last_' . $which . '_source', sanitize_text_field($source), false);
    }

    /**
     * Announces the lead changing hands, once a day at most.
     *
     * Checked on the daily tick rather than whenever a net worth is recalculated,
     * which happens several times inside a single order. A gazette that reported
     * every swap during one ruler's turn would read as noise and would tell
     * everybody precisely when a rival was mid-build.
     *
     * Silent on a board too small for a lead to mean anything, and silent the
     * first time it looks at a round: there is no news in discovering that
     * somebody is in front, only in them taking it from somebody else.
     */
    private static function announce_leader(int $round_id): void {
        if (IDO_Rankings::kingdom_count($round_id) < 3) return;

        // A handful, not one: standings include the defeated, and a fallen
        // empire still holding a high net worth must not be crowned.
        $leader = null;
        foreach (IDO_Rankings::standings($round_id, 10) as $row) {
            if ((int) $row->is_defeated === 0) { $leader = $row; break; }
        }
        if (!$leader) return;

        // One option for the whole game, holding the round it refers to, so a
        // new round starts the question again without leaving rows behind.
        $held  = explode(':', (string) get_option('ido_leader', ''));
        $same_round = (int) ($held[0] ?? 0) === $round_id;
        $was   = $same_round ? (int) ($held[1] ?? 0) : 0;

        if ($was === (int) $leader->id) return;
        update_option('ido_leader', $round_id . ':' . (int) $leader->id, false);
        if ($was === 0) return;

        IDO_Log::news('rankings', sprintf(
            '%s of %s has taken the lead, with a net worth of %s.',
            $leader->ruler_name, $leader->kingdom_name, IDO_Game::fmt((int) $leader->networth)
        ), $round_id);
    }

    public static function last_source(string $which): string {
        $source = (string) get_option('ido_last_' . $which . '_source', '');
        return $source !== '' ? $source : 'unknown';
    }

    /**
     * Grants turns on demand for a single empire that has not had today's
     * allowance yet, so a ruler who logs in before cron has run is not left
     * waiting. The guarded UPDATE makes this safe to race with the daily tick.
     */
    public static function catch_up(object $kingdom): void {
        if ($kingdom->last_turn_grant === IDO_Game::today() || (int) $kingdom->is_defeated === 1) return;
        $per_day = max(1, IDO_Settings::int('turns_per_day'));
        // The cap can never sit below a single day's grant: a misconfigured
        // ceiling would otherwise stamp the date, grant nothing, and freeze
        // every empire at whatever it held, silently and for good.
        $cap     = max(IDO_Settings::int('turn_cap'), $per_day);
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('kingdoms')
            . ' SET turns = GREATEST(turns, LEAST(%d, turns + %d)), last_turn_grant = %s'
            . ' WHERE id = %d AND (last_turn_grant IS NULL OR last_turn_grant <> %s)',
            $cap, $per_day, IDO_Game::today(), (int) $kingdom->id, IDO_Game::today()
        ));
    }
}
