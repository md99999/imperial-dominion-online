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

say('=== the defaults hang together ===');
$d = IDO_Settings::defaults();
check('a board gets 25 turns a day', (int) $d['turns_per_day'] === 25,
    (string) $d['turns_per_day']);
check('and can bank several days of them',
    (int) $d['turn_cap'] >= (int) $d['turns_per_day'] * 3,
    $d['turn_cap'] . ' cap against ' . $d['turns_per_day'] . ' a day');
check('a new ruler starts with a day in hand',
    (int) $d['starting_turns'] >= (int) $d['turns_per_day'],
    (string) $d['starting_turns']);
check('the mint carries the gold, since gold is what binds',
    (int) $d['mint_gold_yield'] > (int) $d['foundry_iron_yield'] * 4,
    $d['mint_gold_yield'] . ' gold against ' . $d['foundry_iron_yield'] . ' iron');
check('and a night produces something on its own',
    (int) $d['daily_yield_turns'] > 0, (string) $d['daily_yield_turns']);
check('the cap can never sit below a day of grants, whatever is typed in',
    (int) IDO_Settings::defaults()['turn_cap'] >= (int) IDO_Settings::defaults()['turns_per_day']);

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
say('=== feeding people is a decision ===');
IDO_Settings::update(['grain_per_100_peasants' => 140, 'farmstead_grain_yield' => 85,
                      'homestead_capacity' => 30]);
$fed = IDO_Economy::per_turn(IDO_Kingdom::find($me));
check('peasants eat what the setting says',
    $fed['grain_out'] >= 3000 * 1.4, IDO_Game::fmt($fed['grain_out']));

// The ratio is the whole point: a farmstead should feed about two homesteads,
// not eight, or a ruler builds one field and spends the rest of the map on gold.
$per_home = IDO_Settings::int('homestead_capacity') * (IDO_Settings::int('grain_per_100_peasants') / 100);
$ratio = IDO_Settings::int('farmstead_grain_yield') / max(1, $per_home);
check('one farmstead feeds about two homesteads, not eight',
    $ratio > 1.5 && $ratio < 3.0, sprintf('%.1f homesteads', $ratio));

IDO_Settings::update(['grain_per_100_peasants' => 35]);
$cheap = IDO_Economy::per_turn(IDO_Kingdom::find($me));
check('lowering it makes farms an afterthought again',
    $cheap['grain'] > $fed['grain'],
    IDO_Game::fmt($fed['grain']) . ' -> ' . IDO_Game::fmt($cheap['grain']));
IDO_Settings::update(['grain_per_100_peasants' => 140]);

check('an army eats on top of the people',
    IDO_Economy::per_turn((object) array_merge((array) IDO_Kingdom::find($me),
        ['u_centurion' => 5000]))['grain_out'] > $fed['grain_out'],
    'centurions counted');

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
say('=== the settings screen does not lie about its own defaults ===');
// Help text that quotes a figure is a promise. Left unchecked it drifts the
// moment a default moves, and a screen that misreports the game is worse than
// one that says nothing.
$help_src = file_get_contents(IDO_PATH . 'admin/views/settings.php');
$help_src = substr($help_src, strpos($help_src, '$help = ['));

$checked = 0;
$wrong = [];
foreach (IDO_Settings::defaults() as $key => $value) {
    if (!preg_match("/'" . preg_quote($key, '/') . "'\s*=> (.*?)(?=,
    '[a-z_]+'\s*=>|,
\];)/s",
        $help_src, $m)) continue;
    if (!preg_match('/Ships at ([\d,]+)/', $m[1], $q)) continue;

    $checked++;
    if ((int) str_replace(',', '', $q[1]) !== (int) $value) {
        $wrong[] = sprintf('%s says %s, ships %s', $key, $q[1], $value);
    }
}
check('some entries quote a shipped figure at all', $checked >= 10, (string) $checked . ' quoted');
check('and every one of them is true', $wrong === [], implode('; ', $wrong));

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
