<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$hourly_next = wp_next_scheduled(IDO_Maintenance::HOURLY_HOOK);
$daily_next  = wp_next_scheduled(IDO_Maintenance::DAILY_HOOK);
$uses_wp_cron = (bool) IDO_Settings::int('use_wp_cron');
$wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
$log = $wpdb->get_results('SELECT * FROM ' . IDO_DB::t('admin_log') . ' ORDER BY id DESC LIMIT 50');

// cPanel's crontab needs an absolute path to a PHP binary; PHP_BINARY is the
// one running WordPress, which is the safest thing to suggest.
$php = PHP_BINARY ?: 'php';
// WordPress's own cron entry point, which runs every due event including ours.
$cron_url = site_url('wp-cron.php?doing_wp_cron');
$dir = rtrim(str_replace('\\', '/', IDO_PATH), '/') . '/maintenance';
?>
<div class="wrap">
    <h1>Maintenance</h1>
    <?php IDO_Admin::notice(); ?>

    <h2>The ticks</h2>
    <p>
        The <strong>daily</strong> tick grants turns, finishes building work, clears old gazette items and winds up a
        finished round. The <strong>hourly</strong> tick returns expired market lots and picks up a round whose time ran
        out between daily runs.
    </p>
    <p>
        <strong>Running both WP-Cron and a real cron is safe.</strong> Each tick takes a database lock before it does
        anything, so only one run of a kind happens at a time no matter what started it, and a second run that arrives
        while the first is working stands down rather than repeating it. Each also refuses to run twice in the same
        period. You do not need to disable WP-Cron, which many shared hosts will not let you do anyway.
    </p>

    <table class="widefat striped" style="max-width:820px">
        <tbody>
            <tr>
                <th style="width:220px">WP-Cron scheduling</th>
                <td>
                    <?php if ($uses_wp_cron) : ?>
                        <strong>On.</strong> This plugin schedules its own WP-Cron events.
                        <?php if ($wp_cron_disabled) : ?>
                            <br><span class="description">WordPress itself has <code>DISABLE_WP_CRON</code> set, so those
                            events only fire if something calls <code>wp-cron.php</code>. Set up a real cron below.</span>
                        <?php endif; ?>
                    <?php else : ?>
                        <strong>Off.</strong> No WP-Cron events are scheduled; the ticks only run from a real cron or
                        from the buttons below.
                    <?php endif; ?>
                    <br><span class="description">Change this under Settings &rarr; Rounds and housekeeping.</span>
                </td>
            </tr>
            <tr><th>Next hourly run</th><td><?php echo $hourly_next ? esc_html(date_i18n('Y-m-d H:i', $hourly_next)) : 'not scheduled'; ?></td></tr>
            <tr><th>Next daily run</th><td><?php echo $daily_next ? esc_html(date_i18n('Y-m-d H:i', $daily_next)) : 'not scheduled'; ?></td></tr>
            <tr>
                <th>Last hourly run</th>
                <td><?php echo esc_html((string) get_option('ido_last_hourly', 'never')); ?>
                    <span class="description">(started by <?php echo esc_html(IDO_Maintenance::last_source('hourly')); ?>)</span></td>
            </tr>
            <tr>
                <th>Last daily run</th>
                <td><?php echo esc_html((string) get_option('ido_last_daily', 'never')); ?>
                    <span class="description">(started by <?php echo esc_html(IDO_Maintenance::last_source('daily')); ?>)</span></td>
            </tr>
        </tbody>
    </table>

    <p>
        <?php echo IDO_Admin::form_open('run_hourly', 'ido_maintenance'); ?>
            <button type="submit" class="button">Run hourly upkeep now</button>
        </form>
        <?php echo IDO_Admin::form_open('run_daily', 'ido_maintenance'); ?>
            <button type="submit" class="button">Run daily upkeep now</button>
        </form>
    </p>

    <h2>Running from cPanel or any other host</h2>
    <p>
        WP-Cron only fires when somebody visits the site, which is no good for a game where turns arrive at midnight.
        A real cron fixes that. There are two ways, and the first is simpler.
    </p>

    <h3>1. Call the site by URL (recommended)</h3>
    <p>
        One entry under <strong>cPanel &rarr; Cron Jobs</strong>, or any scheduler that can fetch a URL. This runs
        every WordPress scheduled task that is due, the game's included, so it is also the only cron line the rest of
        your site needs:
    </p>
    <pre><code>*/15 * * * * curl -s <?php echo esc_html($cron_url); ?> &gt;/dev/null 2&gt;&amp;1</code></pre>
    <p class="description">
        Or with wget: <code>wget -q -O - <?php echo esc_html($cron_url); ?> &gt;/dev/null 2&gt;&amp;1</code>
    </p>
    <p class="description">
        Every fifteen minutes is a good default. WordPress only runs what is actually due, so calling it often is
        cheap, and it means the daily tick lands within a quarter hour of midnight rather than waiting for a visitor.
    </p>
    <?php if (!$uses_wp_cron) : ?>
        <div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px">
            <p>
                <strong>This method will not run the game while WP-Cron scheduling is off.</strong> Calling
                <code>wp-cron.php</code> runs the events that are <em>scheduled</em>, and with that setting at 0 this
                plugin schedules none. Either turn it back on under Settings, or use method 2 below.
            </p>
        </div>
    <?php endif; ?>
    <p class="description">
        This route needs the site to be reachable over HTTP from the server running the cron. Behind HTTP
        authentication, an IP allowlist or a staging password, use method 2 instead.
    </p>

    <h3>2. Call the game's own scripts</h3>
    <p>
        Independent of WordPress scheduling: these run the ticks directly, so they work with WP-Cron scheduling off
        and on a site that is not publicly reachable. The paths are this installation's.
    </p>
    <pre><code>0 * * * * <?php echo esc_html($php . ' ' . $dir . '/hourly_maintenance.php'); ?> &gt;/dev/null 2&gt;&amp;1
5 0 * * * <?php echo esc_html($php . ' ' . $dir . '/daily_maintenance.php'); ?> &gt;/dev/null 2&gt;&amp;1</code></pre>
    <p class="description">
        Run the daily job a few minutes after midnight in the site's own timezone, not the server's, or turns will
        arrive on the wrong day for your players. If cPanel offers several PHP versions, use the one the site runs on.
    </p>

    <p>
        Either way, running a real cron alongside WP-Cron is safe: the ticks take a database lock and check the period
        again before doing anything, so whichever arrives second stands down. The <em>Last run</em> rows above name
        which scheduler actually did the work, which is the quickest way to confirm a new cron entry is firing.
    </p>

    <h2>Audit log</h2>
    <table class="widefat striped">
        <thead><tr><th>When</th><th>Type</th><th>Message</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($log as $entry) :
            $user = get_userdata((int) $entry->user_id); ?>
            <tr>
                <td><?php echo esc_html((string) $entry->created_at); ?></td>
                <td><?php echo esc_html($entry->event_type); ?></td>
                <td><?php echo esc_html((string) $entry->message); ?></td>
                <td><?php echo $user ? esc_html($user->user_login) : '&mdash;'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
