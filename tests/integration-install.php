<?php
/**
 * Integration test: the two warnings about how the plugin was installed.
 *
 * Both exist for people who did not read the README, which is most people, so
 * both have to work in the admin without being asked for.
 *
 *   php tests/integration-install.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-install.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

// The notices are admin-only by design, so the test has to look like the admin.
define('WP_ADMIN', true);
require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-62s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}
function notices(): string {
    ob_start();
    do_action('admin_notices');
    return (string) ob_get_clean();
}

$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (!$admins) { say('No administrator on this site to show a notice to.'); exit(2); }
wp_set_current_user((int) $admins[0]->ID);
register_shutdown_function(static fn() => wp_set_current_user(0));

say('=== a source copy says so ===');
$is_source = is_dir(IDO_PATH . 'tests');
say('  this install ' . ($is_source ? 'is' : 'is not') . ' a source copy');

$out = notices();
if ($is_source) {
    check('the warning is shown', strpos($out, 'installed from a source archive') !== false);
    check('and it names the directories that do not belong',
        strpos($out, '<code>tests</code>') !== false, 'tests listed in <code>');
    check('the markup is markup, not escaped text',
        strpos($out, '&lt;/code&gt;') === false);
    check('it says they cannot run', stripos($out, 'refuse to execute') !== false);
    check('and warns about .git separately', strpos($out, '.git') !== false);
} else {
    check('a built release shows no such warning',
        strpos($out, 'installed from a source archive') === false);
}
check('whichever it is, it is only for people who can act on it',
    strpos(file_get_contents(IDO_FILE), "current_user_can('activate_plugins')") !== false);

say('');
say('=== a second copy is noticed rather than silently ignored ===');
// A second copy cannot fatal -- IDO_PATH is already defined, so its requires
// resolve to the first copy's files -- which is exactly why it needs saying.
$copy = rtrim(sys_get_temp_dir(), '/\\') . '/ido-second-copy';
if (!is_dir($copy)) mkdir($copy, 0777, true);
copy(IDO_FILE, $copy . '/imperial-dominion-online.php');
register_shutdown_function(static function () use ($copy) {
    @unlink($copy . '/imperial-dominion-online.php');
    @rmdir($copy);
});

$before = IDO_PATH;
require $copy . '/imperial-dominion-online.php';

check('loading it does not fatal', true);
check('and does not move IDO_PATH to the second copy', IDO_PATH === $before);
check('the second copy is recorded',
    !empty($GLOBALS['ido_extra_copies']), (string) count((array) ($GLOBALS['ido_extra_copies'] ?? [])));

$out = notices();
check('the administrator is told', strpos($out, 'installed more than once') !== false);
check('it names the copy that is actually running',
    strpos($out, esc_html(IDO_PATH)) !== false);
check('and the one that is not',
    strpos($out, esc_html(rtrim(str_replace('\\', '/', $copy), '/'))) !== false
    || strpos($out, 'ido-second-copy') !== false);
check('it explains why that matters',
    stripos($out, 'may appear to do nothing') !== false);

say('');
say('=== the release carries neither problem ===');
$builder = file_get_contents(IDO_PATH . 'tools/build-zip.php');
check('tests, tools and docs are all excluded from a release',
    strpos($builder, "['tests', 'tools', 'docs']") !== false);
check('and .htaccess is let through the dot rule',
    strpos($builder, "\$keep = ['.htaccess']") !== false);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
