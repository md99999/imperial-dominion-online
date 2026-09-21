<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$hourly_next = wp_next_scheduled(IDO_Maintenance::HOURLY_HOOK);
$daily_next  = wp_next_scheduled(IDO_Maintenance::DAILY_HOOK);
$log = $wpdb->get_results('SELECT * FROM ' . IDO_DB::t('admin_log') . ' ORDER BY id DESC LIMIT 50');
?>
<div class="wrap">
    <h1>Maintenance</h1>
    <?php IDO_Admin::notice(); ?>

    <h2>The ticks</h2>
    <p>
        The <strong>daily</strong> tick grants turns, finishes building work, clears old gazette items and winds up a
        finished round. The <strong>hourly</strong> tick returns expired market lots and picks up a round whose time ran
        out between daily runs. Both refuse to run twice in the same period, so WP-Cron and a system cron can be set up
        together without anyone getting two days of turns.
    </p>
    <table class="widefat striped" style="max-width:760px">
        <tbody>
            <tr><th>Next hourly run</th><td><?php echo $hourly_next ? esc_html(date_i18n('Y-m-d H:i', $hourly_next)) : 'not scheduled'; ?></td></tr>
            <tr><th>Next daily run</th><td><?php echo $daily_next ? esc_html(date_i18n('Y-m-d H:i', $daily_next)) : 'not scheduled'; ?></td></tr>
            <tr><th>Last hourly run</th><td><?php echo esc_html((string) get_option('ido_last_hourly', 'never')); ?></td></tr>
            <tr><th>Last daily run</th><td><?php echo esc_html((string) get_option('ido_last_daily', 'never')); ?></td></tr>
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

    <h2>Running from a real cron</h2>
    <p>
        WP-Cron only fires when someone visits the site, which is no good for a game where turns arrive at midnight.
        On a quiet site, disable WP-Cron and call the scripts in the plugin <code>maintenance</code> folder instead:
    </p>
    <pre><code>0 * * * * php <?php echo esc_html(IDO_PATH); ?>maintenance/hourly_maintenance.php
5 0 * * * php <?php echo esc_html(IDO_PATH); ?>maintenance/daily_maintenance.php</code></pre>

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
