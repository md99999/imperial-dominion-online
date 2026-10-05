<?php
/**
 * Integration test: the economy settings and the nightly produce.
 *
 * Every yield used to be written in three places -- the table a building
 * declares, the sentence shown to a player, and a bare number inside the economy
 * -- so the thing most worth pinning is that one setting moves all three. A
 * screen that disagrees with the game is worse than a screen that says nothing.
 *
 *   php tests/integration-economy.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-economy.php /path/to/wordpress [db-host]'); exit(2); }
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

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }
$rid = (int) $round->id;

$was = get_option(IDO_Settings::OPTION);
$tag = 'Econ' . wp_rand(1000, 9999);
$wpdb->insert(IDO_DB::t('kingdoms'), [
    'round_id' => $rid, 'user_id' => 944001,
    'kingdom_name' => $tag, 'ruler_name' => $tag . ' Ruler',
    'turns' => 50, 'last_turn_grant' => IDO_Game::today(),
    'land' => 500, 'gold' => 100000, 'grain' => 500000, 'iron' => 20000,
    'peasants' => 3000, 'b_homestead' => 100, 'b_farmstead' => 100,
    'b_mint' => 50, 'b_foundry' => 40,
    'created_at' => IDO_Game::now(),
]);
$me = (int) $wpdb->insert_id;
if (!$me) { say('Could not create the empire: ' . $wpdb->last_error); exit(1); }
IDO_Kingdom::recalc_networth(IDO_Kingdom::find($me));

register_shutdown_function(static function () use ($me, $was) {
    global $wpdb;
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $me]);
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
});

IDO_Settings::update([
    'mint_gold_yield' => 60, 'farmstead_grain_yield' => 85, 'foundry_iron_yield' => 25,
    'homestead_capacity' => 30, 'tax_per_100_peasants' => 55, 'daily_yield_turns' => 0,
]);

say('=== the defaults are what the game always did ===');
$d = IDO_Settings::defaults();
check('a mint still makes 60', (int) $d['mint_gold_yield'] === 60);
check('a farmstead still makes 85', (int) $d['farmstead_grain_yield'] === 85);
check('a foundry still makes 25', (int) $d['foundry_iron_yield'] === 25);
check('a homestead still houses 30', (int) $d['homestead_capacity'] === 30);
check('and nothing arrives for free unless asked for',
    (int) $d['daily_yield_turns'] === 0);

say('');
say('=== one setting moves the game ===');
$base = IDO_Economy::per_turn(IDO_Kingdom::find($me));
IDO_Settings::update(['mint_gold_yield' => 160]);
$richer = IDO_Economy::per_turn(IDO_Kingdom::find($me));
check('raising the mint raises gold per turn',
    $richer['gold'] - $base['gold'] === 50 * 100,
    IDO_Game::fmt($base['gold']) . ' -> ' . IDO_Game::fmt($richer['gold']));

IDO_Settings::update(['foundry_iron_yield' => 125]);
check('and the foundry moves iron',
    IDO_Economy::per_turn(IDO_Kingdom::find($me))['iron'] === 40 * 125,
    (string) IDO_Economy::per_turn(IDO_Kingdom::find($me))['iron']);

IDO_Settings::update(['homestead_capacity' => 60]);
check('and the homestead moves the ceiling on people',
    IDO_Economy::peasant_capacity(IDO_Kingdom::find($me)) === 100 * 60,
    (string) IDO_Economy::peasant_capacity(IDO_Kingdom::find($me)));

IDO_Settings::update(['tax_per_100_peasants' => 200]);
$taxed = IDO_Economy::per_turn(IDO_Kingdom::find($me));
check('and the tax rate moves what peasants pay',
    $taxed['gold_in'] > $richer['gold_in'],
    IDO_Game::fmt($richer['gold_in']) . ' -> ' . IDO_Game::fmt($taxed['gold_in']));

say('');
say('=== and the same setting moves what the screen says ===');
IDO_Settings::update(['mint_gold_yield' => 777, 'farmstead_grain_yield' => 777,
                      'foundry_iron_yield' => 777, 'homestead_capacity' => 777]);
$all = IDO_Buildings::all();
foreach (['mint', 'farmstead', 'foundry', 'homestead'] as $key) {
    check("the $key tells a player the real figure",
        strpos($all[$key]['effect'], '777') !== false, $all[$key]['effect']);
}
check('and the yield table carries it too',
    (int) ($all['mint']['yield']['gold'] ?? 0) === 777);

// Back to the defaults for the rest.
IDO_Settings::update(['mint_gold_yield' => 60, 'farmstead_grain_yield' => 85,
                      'foundry_iron_yield' => 25, 'homestead_capacity' => 30,
                      'tax_per_100_peasants' => 55]);

say('');
say('=== the nightly produce ===');
$before = IDO_Kingdom::find($me);
check('off by default, nothing comes',
    IDO_Economy::nightly_yield($rid)['empires'] === 0);
$same = IDO_Kingdom::find($me);
check('and the stores did not move', (int) $same->gold === (int) $before->gold);

IDO_Settings::update(['daily_yield_turns' => 2]);
$before = IDO_Kingdom::find($me);
$turns_before = (int) $before->turns;
$result = IDO_Economy::nightly_yield($rid);
$after = IDO_Kingdom::find($me);

check('switched on, empires are fed', $result['empires'] >= 1, (string) $result['empires']);
check('gold arrives', (int) $after->gold > (int) $before->gold,
    IDO_Game::fmt((int) $after->gold - (int) $before->gold) . ' gold');
check('iron arrives', (int) $after->iron > (int) $before->iron);
check('and it costs no turns at all', (int) $after->turns === $turns_before,
    $turns_before . ' -> ' . $after->turns);
check('it is worth two turns, because that is what was asked for',
    (int) $after->gold - (int) $before->gold === 2 * IDO_Economy::per_turn($before)['gold'],
    IDO_Game::fmt((int) $after->gold - (int) $before->gold) . ' vs '
    . IDO_Game::fmt(2 * IDO_Economy::per_turn($before)['gold']));

say('');
say('=== the weather is not rolled on a night nobody ordered ===');
$src = file_get_contents(IDO_PATH . 'includes/services/class-economy-service.php');
check('barbarians are gated on it', strpos($src, '$raidable = $hazards &&') !== false);
check('disasters too', strpos($src, '$strikeable = $hazards &&') !== false);
check('and the nightly grant asks for none',
    strpos($src, 'self::advance($kingdom, $turns, false)') !== false);

say('');
say('=== it skips who it should ===');
$wpdb->update(IDO_DB::t('kingdoms'), ['is_defeated' => 1], ['id' => $me]);
$before = IDO_Kingdom::find($me);
IDO_Economy::nightly_yield($rid);
check('a defeated empire is left alone',
    (int) IDO_Kingdom::find($me)->gold === (int) $before->gold);
$wpdb->update(IDO_DB::t('kingdoms'), ['is_defeated' => 0], ['id' => $me]);
check('and masterless provinces are skipped',
    strpos($src, 'is_defeated = 0 AND is_rival = 0') !== false);

say('');
say('=== the league governs the economy ===');
foreach (['mint_gold_yield', 'farmstead_grain_yield', 'foundry_iron_yield',
          'homestead_capacity', 'tax_per_100_peasants', 'daily_yield_turns'] as $key) {
    check("the league owns $key", in_array($key, IDO_League::governed_keys(), true));
}

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
