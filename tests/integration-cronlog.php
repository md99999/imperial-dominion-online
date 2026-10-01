<?php
/**
 * Integration test: the Cron Maintenance Log.
 *
 * The rule that matters is the one that is easy to get wrong: a run that stands
 * down must count as a start and must not overwrite the run that did the work.
 * Get it backwards and the screen shows midnight's real run replaced by "skipped:
 * it already ran today" from a 1am duplicate, and a working site looks broken.
 *
 *   php tests/integration-cronlog.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-cronlog.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-62s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}

$held_log   = get_option(IDO_Maintenance::LOG_OPTION, false);
$held_daily = get_option('ido_last_daily', false);

register_shutdown_function(static function () use ($held_log, $held_daily) {
    if ($held_log === false) { delete_option(IDO_Maintenance::LOG_OPTION); }
    else { update_option(IDO_Maintenance::LOG_OPTION, $held_log); }
    if ($held_daily === false) { delete_option('ido_last_daily'); }
    else { update_option('ido_last_daily', $held_daily); }
    wp_set_current_user(0);
});

$tick = new ReflectionMethod('IDO_Maintenance', 'log_tick');
$tick->setAccessible(true);
$today = IDO_Game::today();

delete_option(IDO_Maintenance::LOG_OPTION);

say('=== the message is handed straight back ===');
$back = $tick->invoke(null, 'daily', 'WP-Cron', 'Daily upkeep: turns granted to 14 empires.', true);
check('so every exit can return through it',
    $back === 'Daily upkeep: turns granted to 14 empires.', $back);

$row = IDO_Maintenance::log()['daily'][$today];
check('one start recorded', (int) $row['starts'] === 1);
check('and one of them worked', (int) $row['worked'] === 1);
check('the source is kept', $row['source'] === 'WP-Cron', $row['source']);
check('and the time', $row['ran_at'] !== '');

say('');
say('=== a repeat that stands down must not hide the real run ===');
$tick->invoke(null, 'daily', 'system cron',
    'Daily upkeep skipped: it already ran today at 2026-10-01 00:05:00.', false);
$tick->invoke(null, 'daily', 'WP-Cron',
    'Daily upkeep skipped: another run is already in progress.', false);

$row = IDO_Maintenance::log()['daily'][$today];
check('the starts are counted', (int) $row['starts'] === 3, (string) $row['starts']);
check('but only one did work', (int) $row['worked'] === 1, (string) $row['worked']);
check('the summary is still the run that did the work',
    strpos($row['summary'], 'turns granted to 14 empires') !== false, $row['summary']);
check('and so is the source', $row['source'] === 'WP-Cron', $row['source']);
check('the skip reason is kept aside, not thrown away',
    strpos($row['note'], 'already in progress') !== false, $row['note']);

say('');
say('=== a later run that works does replace an earlier one ===');
$tick->invoke(null, 'hourly', 'WP-Cron', 'Hourly upkeep: 1 market lots returned.', true);
$tick->invoke(null, 'hourly', 'system cron', 'Hourly upkeep: 4 market lots returned.', true);
$h = IDO_Maintenance::log()['hourly'][$today];
check('the newest run wins', strpos($h['summary'], '4 market lots') !== false, $h['summary']);
check('and both are counted as work', (int) $h['worked'] === 2, (string) $h['worked']);
check('the jobs are kept apart',
    (int) IDO_Maintenance::log()['daily'][$today]['starts'] === 3);

say('');
say('=== a day where nothing ran at all ===');
delete_option(IDO_Maintenance::LOG_OPTION);
$tick->invoke(null, 'daily', 'WP-Cron', 'No round is running.', false);
$row = IDO_Maintenance::log()['daily'][$today];
check('it is still a start', (int) $row['starts'] === 1);
check('nothing is claimed to have worked', (int) $row['worked'] === 0);
check('and the reason is the only thing to show',
    strpos($row['note'], 'No round is running') !== false, $row['note']);

say('');
say('=== Run now names the administrator ===');
$admins = get_users(['role' => 'administrator', 'number' => 1]);
if ($admins) {
    wp_set_current_user((int) $admins[0]->ID);
    delete_option(IDO_Maintenance::LOG_OPTION);
    $tick->invoke(null, 'daily', 'admin', 'Daily upkeep: turns granted to 3 empires.', true);
    $row = IDO_Maintenance::log()['daily'][$today];
    check('the button is attributed to whoever pressed it',
        $row['source'] === 'Run now by ' . $admins[0]->user_login, $row['source']);
    wp_set_current_user(0);
} else {
    say('  (no administrator on this site to attribute to; skipped)');
}

say('');
say('=== it cannot grow without bound ===');
delete_option(IDO_Maintenance::LOG_OPTION);
$log = [];
for ($i = 0; $i < 25; $i++) {
    $log['daily'][gmdate('Y-m-d', time() - $i * DAY_IN_SECONDS)] = [
        'starts' => 1, 'worked' => 1, 'ran_at' => IDO_Game::now(),
        'source' => 'WP-Cron', 'summary' => 'old', 'note' => '',
    ];
}
update_option(IDO_Maintenance::LOG_OPTION, $log, false);
$tick->invoke(null, 'daily', 'WP-Cron', 'Daily upkeep: a fresh one.', true);

$days = IDO_Maintenance::log()['daily'];
check('ten days kept, not twenty-six', count($days) === IDO_Maintenance::LOG_DAYS,
    count($days) . ' of ' . IDO_Maintenance::LOG_DAYS);
check('newest first', array_key_first($days) === $today, (string) array_key_first($days));
check('and the oldest were the ones dropped',
    !array_key_exists(gmdate('Y-m-d', time() - 24 * DAY_IN_SECONDS), $days));

say('');
say('=== the reader survives a corrupt option ===');
update_option(IDO_Maintenance::LOG_OPTION, 'not an array', false);
check('a junk option reads as empty rather than fatally',
    IDO_Maintenance::log() === []);
update_option(IDO_Maintenance::LOG_OPTION, ['daily' => 'also junk'], false);
check('and so does a junk job', !isset(IDO_Maintenance::log()['daily']));

say('');
say('=== the lead check sits on the daily tick, not the hourly one ===');
$src = file_get_contents(IDO_PATH . 'includes/services/class-maintenance-service.php');
$daily_at  = strpos($src, 'public static function daily(');
$hourly_at = strpos($src, 'public static function hourly(');
$leader_at = strpos($src, 'self::announce_leader(');
check('announce_leader is called once', substr_count($src, 'self::announce_leader(') === 1);
check('and it is inside daily(), which is what its comment claims',
    $leader_at > $daily_at && $daily_at > $hourly_at,
    "hourly@$hourly_at daily@$daily_at leader@$leader_at");

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
