<?php
/**
 * Integration test: the install health check.
 *
 * Everything here exists for people who did not read the README, which is most
 * people, so all of it has to work in the admin without being asked for.
 *
 * Needs an HTTP transport for the .git probe. The PHP bundled with Local has
 * curl and openssl but does not enable them by default:
 *
 *   php -d extension=php_curl.dll -d extension=php_openssl.dll \
 *       tests/integration-install.php /path/to/wordpress 127.0.0.1:10005
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-install.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

// The notice is admin-only by design, so the test has to look like the admin.
define('WP_ADMIN', true);
require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-62s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}
/** The issue whose title contains $needle, or null. */
function issue(string $needle): ?array {
    foreach (IDO_Health::issues() as $i) {
        if (stripos($i['title'], $needle) !== false) return $i;
    }
    return null;
}

$admins = get_users(['role' => 'administrator', 'number' => 1]);
if (!$admins) { say('No administrator on this site to show a notice to.'); exit(2); }
wp_set_current_user((int) $admins[0]->ID);
register_shutdown_function(static fn() => wp_set_current_user(0));

$health_src = file_get_contents(IDO_PATH . 'includes/class-ido-health.php');

say('=== where this copy lives ===');
check('it knows its own folder',
    IDO_Health::folder() === basename(untrailingslashit(IDO_PATH)), IDO_Health::folder());
check('and the folder updates expect', IDO_Health::SLUG === 'imperial-dominion-online');
check('a correctly named folder raises nothing about naming',
    IDO_Health::folder() !== IDO_Health::SLUG || issue('folder is not named') === null);

say('');
say('=== development directories ===');
$dev = issue('directories a release does not');
if (is_dir(IDO_PATH . 'tests')) {
    check('a source copy is reported', $dev !== null);
    check('as a warning rather than an error', $dev && $dev['level'] === 'warning');
    check('naming what it found', $dev && strpos($dev['body'], '<code>tests</code>') !== false);
    check('the markup is markup, not escaped text',
        $dev && strpos($dev['body'], '&lt;code&gt;') === false);
    check('and it says they cannot run',
        $dev && stripos($dev['body'], 'refuses to execute') !== false);
} else {
    check('a release reports nothing here', $dev === null);
}

say('');
say('=== a second copy, installed but not activated ===');
// The case the old inline check could never see: nothing running inside the
// first copy can notice a folder WordPress has not loaded, and that folder is
// still what the next update will land on.
$plugins = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
$twin = $plugins . '/' . IDO_Health::SLUG . '-healthtest';
$made_twin = @mkdir($twin) && (bool) @copy(IDO_FILE, $twin . '/' . IDO_Health::SLUG . '.php');
register_shutdown_function(static function () use ($twin, $made_twin) {
    if (!$made_twin) return;
    @unlink($twin . '/' . IDO_Health::SLUG . '.php');
    @rmdir($twin);
});

if ($made_twin) {
    $dupe = issue('more than one copy');
    check('it is found', $dupe !== null);
    check('and treated as an error', $dupe && $dupe['level'] === 'error');
    check('it names the other folder',
        $dupe && strpos($dupe['body'], IDO_Health::SLUG . '-healthtest') !== false);
    check('it names the one actually running',
        $dupe && strpos($dupe['body'], esc_html(IDO_Health::folder())) !== false);
    check('and says deleting a copy does not take the game with it',
        $dupe && stripos($dupe['body'], 'does not touch the game') !== false);
} else {
    say('  (could not create a second plugin folder here; skipped)');
}

say('');
say('=== a second copy that does load stands down ===');
// It cannot carry on: IDO_PATH is already defined, so its requires would resolve
// to the other copy's files and its hooks would register against the other
// copy's paths. It must not fatal either.
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
check('and IDO_PATH still points at the copy that is running', IDO_PATH === $before);

say('');
say('=== the .git probe ===');
check('it is only reached when a .git directory exists',
    strpos($health_src, "if (!is_dir(IDO_PATH . '.git')) return null;") !== false);
check('the answer is cached rather than asked on every page',
    strpos($health_src, 'set_transient') !== false);
check('an unexpected status is unknown, never assumed safe',
    strpos($health_src, 'do not guess') !== false);
check('redirects are followed, so http to https cannot look safe',
    strpos($health_src, "'redirection' => 3") !== false);
check('and a served HEAD is confirmed by its contents, not just a 200',
    strpos($health_src, "strpos(\$body, 'ref:') === 0") !== false);

$git = IDO_PATH . '.git';
$made_git = !is_dir($git) && @mkdir($git) && (bool) @file_put_contents($git . '/HEAD', "ref: refs/heads/main\n");
register_shutdown_function(static function () use ($git, $made_git) {
    if (!$made_git) return;
    @unlink($git . '/HEAD');
    @rmdir($git);
});

if ($made_git) {
    delete_transient('ido_git_reachable');
    $reachable = IDO_Health::git_reachable(true);
    say('  this server ' . ($reachable === true ? 'SERVES it'
        : ($reachable === false ? 'refuses it' : 'could not be asked (no HTTP transport?)')));

    $g = issue('.git');
    check('having one is reported', $g !== null);
    check('an error when the web really serves it, a warning when it does not',
        $g && $g['level'] === ($reachable === true ? 'error' : 'warning'), $g['level'] ?? '-');
    check('and the advice is to stop keeping it on a server',
        $g && stripos($g['body'], 'not to keep') !== false);
} else {
    say('  (could not create a .git directory here; skipped)');
}

say('');
say('=== where the notice appears ===');
check('only for someone who could act on it',
    strpos($health_src, "current_user_can('manage_options')") !== false);
check('and only on the Plugins screen and the game\'s own',
    strpos($health_src, "'plugins'") !== false && strpos($health_src, "'ido_'") !== false);
check('the dashboard shows the same findings',
    strpos(file_get_contents(IDO_PATH . 'admin/views/dashboard.php'), 'IDO_Health::issues()') !== false);

say('');
say('=== a release carries none of this ===');
$builder = file_get_contents(IDO_PATH . 'tools/build-zip.php');
check('tests, tools and docs are excluded from a build',
    strpos($builder, "['tests', 'tools', 'docs']") !== false);
check('and .htaccess is let through the dot rule',
    strpos($builder, "\$keep = ['.htaccess']") !== false);
check('and all three are out of GitHub\'s archive too',
    strpos(file_get_contents(IDO_PATH . '.gitattributes'), 'export-ignore') !== false);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
