<?php
/**
 * Integration test: hostile form input against a real WordPress and a real
 * database.
 *
 * The unit tests stub $wpdb, so they prove the code branches and not that the
 * SQL is safe. This one puts injection payloads through the actual service
 * layer, against a live database, and then checks that the database is still
 * there: the tables intact, the row counts unchanged, and the test empire
 * holding exactly what it held before.
 *
 *   php tests/integration-input.php /path/to/wordpress [db-host]
 *
 * It creates one throwaway empire, attacks it with its own inputs, and deletes
 * it. No row belonging to a real player is touched.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-input.php /path/to/wordpress [db-host]'); exit(2); }
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
/** Runs $fn and returns the refusal message, or '' if it was allowed through. */
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (IDO_Game_Exception $e) { return $e->getMessage(); }
}

if (!class_exists('IDO_Kingdom')) { say('The plugin is not active on that site.'); exit(1); }

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

$kingdoms = IDO_DB::t('kingdoms');
$before_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM $kingdoms");

$name = 'Input Test ' . wp_rand(1000, 9999);
$wpdb->insert($kingdoms, [
    'round_id' => (int) $round->id, 'user_id' => 999998,
    'kingdom_name' => $name, 'ruler_name' => $name . ' Ruler',
    'turns' => 20, 'last_turn_grant' => IDO_Game::today(),
    'land' => 250, 'gold' => 500000, 'grain' => 40000, 'iron' => 5000, 'peasants' => 1500,
    'b_homestead' => 60, 'b_farmstead' => 60, 'created_at' => IDO_Game::now(),
]);
$id = (int) $wpdb->insert_id;
if (!$id) { say('Could not create the test empire.'); exit(1); }
$k = IDO_Kingdom::find($id);

register_shutdown_function(static function () use ($wpdb, $kingdoms, $id) {
    $wpdb->delete($kingdoms, ['id' => $id], ['%d']);
    fwrite(STDERR, "Test empire removed." . PHP_EOL);
});

// The payloads. Each one is aimed at a different layer: the query, the shell,
// the template, the filesystem.
$payloads = [
    "'; DROP TABLE $kingdoms; --",
    "' OR 1=1 --",
    "1; UPDATE $kingdoms SET gold = 999999999",
    "gold` = 999999, `land",
    'gold),(SELECT user_pass FROM wp_users',
    '; rm -rf /',
    '$(whoami)',
    '`id`',
    '<script>alert(1)</script>',
    '../../../wp-config.php',
    "\x00gold",
    'gold" ',
];

say('=== column names cannot be supplied, only chosen from a list ===');
foreach ($payloads as $payload) {
    $why = refused(static fn() => IDO_Kingdom::pay($k, [$payload => 1]));
    check('pay() refuses  ' . substr(str_replace("\x00", '\0', $payload), 0, 36), $why !== '',
        $why === '' ? 'ACCEPTED' : '');
}
foreach (['gold', 'land', 'turns'] as $real) {
    check("pay() still accepts $real", refused(static fn() => IDO_Kingdom::pay($k, [$real => 0])) === '');
}

say('');
say('=== the same for a plain field update ===');
foreach ([$payloads[0], $payloads[3], 'wp_users', 'user_id'] as $payload) {
    check('update() refuses  ' . substr($payload, 0, 34),
        refused(static fn() => IDO_Kingdom::update($k, [$payload => 1])) !== '');
}
check('update() still accepts networth',
    refused(static fn() => IDO_Kingdom::update($k, ['networth' => 1])) === '');

say('');
say('=== keys that name a building, unit, weapon or item ===');
foreach ($payloads as $payload) {
    $refusals = [
        refused(static fn() => IDO_Construction::order($k, $payload, 1)),
        refused(static fn() => IDO_Construction::demolish($k, $payload, 1)),
        refused(static fn() => IDO_Construction::order_weapon($k, $payload, 1)),
        refused(static fn() => IDO_Military::train($k, $payload, 1)),
        refused(static fn() => IDO_Military::disband($k, $payload, 1)),
        refused(static fn() => IDO_Market::post($k, $payload, 1, 10)),
        refused(static fn() => IDO_Covert::run($k, 0, $payload)),
        refused(static fn() => IDO_Military::attack($k, 0, $payload, ['pawn' => 1])),
    ];
    $all = count(array_filter($refusals, static fn($r) => $r !== '')) === count($refusals);
    check('every service refuses  ' . substr(str_replace("\x00", '\0', $payload), 0, 30), $all);
}

say('');
say('=== empire and ruler names ===');
// The property is not "every payload is refused". A name is normalised before
// it is checked, so "\0gold" becomes "gold" and newlines collapse to spaces, and
// both of those are then perfectly ordinary names. What has to be true is that
// nothing hostile *survives* as a name, by whichever route it got there.
foreach (array_merge($payloads, ['', ' ', 'a', str_repeat('x', 500), "Name\nwith\nnewlines"]) as $payload) {
    $label = substr(str_replace(["\x00", "\n", "\r"], ['\0', '\n', '\r'], $payload), 0, 30);
    try {
        $result = IDO_Kingdom::clean_name($payload);
        $safe = (bool) preg_match("/^[\p{L}\p{N} '\\-]+\$/u", $result)
            && strpos($result, "\x00") === false
            && trim($result) === $result;
        check('clean_name neutralises  ' . $label, $safe, 'became: ' . $result);
    } catch (IDO_Game_Exception $e) {
        check('clean_name refuses  ' . $label, true);
    }
}
foreach (['Northmarch', "O'Brien", 'Saint-Denis', 'Reino de España', 'Empire 7'] as $good) {
    check("clean_name accepts  $good", refused(static fn() => IDO_Kingdom::clean_name($good)) === '');
}

say('');
say('=== quantities ===');
check('a negative quantity does not credit anything',
    refused(static fn() => IDO_Construction::order($k, 'homestead', -5)) !== '');
check('a quantity beyond what is held is refused',
    refused(static fn() => IDO_Military::disband($k, 'pawn', 999999999)) !== '');
check('a huge quantity does not overflow',
    refused(static fn() => IDO_Construction::order($k, 'homestead', PHP_INT_MAX)) !== '');

say('');
say('=== and afterwards, the database is exactly as it was ===');
$table_still_there = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $kingdoms)) === $kingdoms;
check('the empires table still exists', $table_still_there);
check('wp_users still exists',
    (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->users)) === $wpdb->users);
check('no rows were added or removed',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM $kingdoms") === $before_rows + 1);

$after = IDO_Kingdom::find($id);
check('the test empire still holds its 500,000 gold', (int) $after->gold === 500000, (string) $after->gold);
check('and its 250 acres', (int) $after->land === 250, (string) $after->land);
check('and nobody else was granted gold',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM $kingdoms WHERE gold = 999999999") === 0);
check('its name was not rewritten', $after->kingdom_name === $name);

say('');
say('=== no filename reaches an include ===');
// CWE-98, improper control of a filename for an include. The plugin has exactly
// three include/require statements whose argument is not a constant followed by
// a literal, and all three are safe for reasons that are easy to break later.
// This counts them rather than reasoning about them, so a fourth one has to be
// looked at by a person before the suite goes green again.
$shipped = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(IDO_PATH, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $path = str_replace(DIRECTORY_SEPARATOR, '/', $f->getPathname());
    if (strtolower($f->getExtension()) !== 'php') continue;
    if (strpos($path, '/tests/') !== false || strpos($path, '/tools/') !== false) continue;
    $shipped[] = $path;
}

/** True when the whole path is decided at author time: a constant, then a literal. */
$author_time = static function (string $arg): bool {
    foreach (['IDO_PATH', 'ABSPATH', '__DIR__'] as $const) {
        if (strpos($arg, $const) !== 0) continue;
        $rest = ltrim(substr($arg, strlen($const)));
        if ($rest === '' || $rest[0] !== '.') continue;
        $rest = ltrim(substr($rest, 1));
        if ($rest !== '' && ($rest[0] === chr(39) || $rest[0] === chr(34))) return true;
    }
    return false;
};

$dynamic = [];
foreach ($shipped as $path) {
    foreach (file($path) as $n => $line) {
        if (!preg_match('/\b(include|include_once|require|require_once)\s+(?!\()(.+?);/', $line, $m)) {
            continue;
        }
        $arg = trim($m[2]);
        if ($author_time($arg)) continue;
        $dynamic[] = basename($path) . ':' . ($n + 1) . '  ' . $arg;
    }
}
check('only the three known dynamic includes exist', count($dynamic) === 3,
    $dynamic ? implode(' | ', $dynamic) : 'none found');

// 1. The admin view. Every caller names its file, and the capability is checked
//    before the path is built rather than after.
$admin_src = file_get_contents(IDO_PATH . 'admin/class-ido-admin.php');
preg_match_all('/self::view\(([^)]*)\)/', $admin_src, $calls);
$quoted = '/^' . chr(39) . '[a-z_]+' . chr(39) . '$/';
$literal = array_filter($calls[1], static fn($a) => (bool) preg_match($quoted, trim($a)));
check('every admin view is asked for by name',
    count($calls[1]) > 0 && count($literal) === count($calls[1]),
    count($literal) . ' of ' . count($calls[1]) . ' callers pass a literal');
check('and permission is checked before the path is built',
    strpos($admin_src, 'current_user_can(self::CAP)')
    < strpos($admin_src, chr(39) . 'admin/views/' . chr(39)));

// 2. The page view. The key comes from a class constant, and -- the part that
//    actually matters -- the shortcode callback takes no arguments at all, so no
//    [ido_guide view="../../wp-config"] attribute can reach it however it is
//    spelled. A callback that accepted $atts would be a different question.
$sc_src = file_get_contents(IDO_PATH . 'includes/frontend/class-ido-shortcodes.php');
check('the view key is bound from a constant, not from a request',
    (bool) preg_match('/foreach \(IDO_UI::PAGES as \$key/', $sc_src));
check('and the shortcode callback accepts no attributes at all',
    strpos($sc_src, 'add_shortcode($def[2], static function () use ($key)') !== false);
check('so nothing a page author writes can steer it',
    strpos($sc_src, 'apply_filters') === false);

// 3. The CLI bootstrap, which only ever walks upward from its own directory.
$boot = file_get_contents(IDO_PATH . 'maintenance/bootstrap.php');
check('the cron bootstrap starts at its own directory',
    strpos($boot, '$dir = __DIR__;') !== false);
check('and moves only upward, never into a supplied path',
    strpos($boot, '$dir = dirname($dir);') !== false
    && strpos($boot, '$_GET') === false && strpos($boot, '$_POST') === false
    && strpos($boot, '$argv') === false);

say('');
say('=== and the command line scripts refuse the web ===');
// They sit in the web root on any site that installed from a checkout, so the
// SAPI gate is the only thing standing between a cron script and a visitor.
foreach (['maintenance/bootstrap.php', 'maintenance/daily_maintenance.php',
          'maintenance/hourly_maintenance.php'] as $cli) {
    $head = implode("\n", array_slice(file(IDO_PATH . $cli), 0, 8));
    check('  ' . basename($cli) . ' refuses a web request',
        strpos($head, 'PHP_SAPI !==') !== false
        && strpos($head, 'cli') !== false
        && strpos($head, 'exit') !== false);
}

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
