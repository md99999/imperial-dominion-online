<?php
/**
 * Integration test: a ruler cannot spend a turn on a building that has stopped
 * doing anything.
 *
 * Turns are the currency of this game. Fortifications stop lifting defence at
 * their cap and barracks stop discounting at theirs, and before this the game
 * would happily take the turn, the gold, the iron and the acre for the one after
 * that. A trap that quietly wastes the thing the whole game is made of.
 *
 *   php tests/integration-caps.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-caps.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-60s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (IDO_Game_Exception $e) { return $e->getMessage(); }
}

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

$made = [];
register_shutdown_function(static function () use (&$made) {
    global $wpdb;
    foreach ($made as $id) {
        $wpdb->delete(IDO_DB::t('constructions'), ['kingdom_id' => $id], ['%d']);
        $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $id], ['%d']);
    }
    fwrite(STDERR, 'Test empire removed.' . PHP_EOL);
});

function empire(array $fields = []): object {
    global $wpdb, $round, $made;
    static $user = 999800;
    $user++;
    $wpdb->insert(IDO_DB::t('kingdoms'), array_merge([
        'round_id' => (int) $round->id, 'user_id' => $user,
        'kingdom_name' => 'Cap Test ' . $user, 'ruler_name' => 'Cap Ruler ' . $user,
        'turns' => 50, 'last_turn_grant' => IDO_Game::today(),
        'land' => 2000, 'gold' => 50000000, 'grain' => 500000, 'iron' => 5000000, 'peasants' => 5000,
        'created_at' => IDO_Game::now(),
    ], $fields));
    $id = (int) $wpdb->insert_id;
    $made[] = $id;
    return IDO_Kingdom::find($id);
}

say('=== which buildings have a ceiling ===');
$fort_cap = IDO_Buildings::useful_cap('fortification');
$barracks_cap = IDO_Buildings::useful_cap('barracks');
check('fortifications have one', $fort_cap > 0, (string) $fort_cap);
check('barracks have one', $barracks_cap > 0, (string) $barracks_cap);
check('homesteads do not', IDO_Buildings::useful_cap('homestead') === 0);
check('farmsteads do not', IDO_Buildings::useful_cap('farmstead') === 0);

// The cap is where the bonus stops moving, and is derived from it rather than
// typed in, so tuning the bonus moves the cap.
$at_cap  = (object) ['b_fortification' => $fort_cap];
$over    = (object) ['b_fortification' => $fort_cap * 3];
check('the bonus is maxed at the cap',
    abs(IDO_Buildings::fortification_bonus($at_cap) - IDO_Buildings::fortification_bonus($over)) < 0.0001,
    sprintf('%.3f vs %.3f', IDO_Buildings::fortification_bonus($at_cap),
        IDO_Buildings::fortification_bonus($over)));
$below = (object) ['b_fortification' => $fort_cap - 1];
check('and still climbing one short of it',
    IDO_Buildings::fortification_bonus($below) < IDO_Buildings::fortification_bonus($at_cap));

say('');
say('=== a turn is not spent on a building that cannot help ===');
$maxed = empire(['b_fortification' => $fort_cap]);
$turns_before = (int) $maxed->turns;
$gold_before  = (int) $maxed->gold;

$why = refused(static fn() => IDO_Construction::order($maxed, 'fortification', 1));
check('ordering another is refused', $why !== '', $why);
check('the message names the ceiling', strpos($why, (string) IDO_Game::fmt($fort_cap)) !== false, $why);

$maxed = IDO_Kingdom::reload($maxed);
check('no turn was spent', (int) $maxed->turns === $turns_before,
    $maxed->turns . ' of ' . $turns_before);
check('no gold was spent', (int) $maxed->gold === $gold_before);
check('and nothing was queued', IDO_Construction::queued($maxed, 'fortification') === 0);

say('');
say('=== ordering past the ceiling is refused, not trimmed ===');
$nearly = empire(['b_fortification' => $fort_cap - 5]);
$why = refused(static fn() => IDO_Construction::order($nearly, 'fortification', 50));
check('an order of fifty with five of room is refused', $why !== '', $why);
check('and says how many would count', strpos($why, 'Only 5 more') !== false, $why);
$nearly = IDO_Kingdom::reload($nearly);
check('nothing was built', (int) $nearly->b_fortification === $fort_cap - 5);

say('');
say('=== but the room that is left can be used ===');
IDO_Construction::order($nearly, 'fortification', 5);
check('an order that fits is accepted', IDO_Construction::queued($nearly, 'fortification') === 5);
$nearly = IDO_Kingdom::reload($nearly);
check('and it cost a turn, as any order does', (int) $nearly->turns === 49, (string) $nearly->turns);

say('');
say('=== what is already on the way counts against the ceiling ===');
// Five are queued and none standing, so there is no room left even though the
// empire still shows the old count. Without this a ruler could queue the cap
// several times over on consecutive turns and only find out when they landed.
$why = refused(static fn() => IDO_Construction::order($nearly, 'fortification', 1));
check('a second order is refused while the first is still building', $why !== '', $why);
check('and the message says some are being built',
    stripos($why, 'still being built') !== false, $why);

say('');
say('=== uncapped buildings are untouched ===');
$plain = empire();
IDO_Construction::order($plain, 'homestead', 500);
check('five hundred homesteads is fine', IDO_Construction::queued($plain, 'homestead') === 500);
IDO_Construction::order($plain, 'farmstead', 300);
check('and three hundred farmsteads', IDO_Construction::queued($plain, 'farmstead') === 300);

say('');
say('=== barracks have the same ceiling behaviour ===');
$barracked = empire(['b_barracks' => $barracks_cap]);
check('ordering another barracks is refused',
    refused(static fn() => IDO_Construction::order($barracked, 'barracks', 1)) !== '');
check('and the discount is maxed there',
    abs(IDO_Buildings::barracks_discount($barracked) - IDO_Buildings::MAX_BARRACKS_DISCOUNT) < 0.0001,
    sprintf('%.3f', IDO_Buildings::barracks_discount($barracked)));

say('');
say('=== demolishing frees the room again ===');
IDO_Construction::demolish($maxed, 'fortification', 10);
$maxed = IDO_Kingdom::reload($maxed);
check('ten come down', (int) $maxed->b_fortification === $fort_cap - 10);
check('and ten can be ordered again',
    refused(static fn() => IDO_Construction::order($maxed, 'fortification', 10)) === '');

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
