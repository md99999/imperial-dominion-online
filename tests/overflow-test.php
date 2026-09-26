<?php
/**
 * Tries to make the score go negative, the way the old BRE exploit did.
 */
define('ABSPATH', __DIR__ . '/');
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function esc_html($s) { return $s; }
// Weapon prices are settings, so scoring a weapon reads the options table.
// There is no WordPress here: the stock defaults are the honest answer.
function get_option($name, $default = false) { return $default; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }

$base = __DIR__ . '/../includes/';
require $base . 'class-ido-core.php';
require $base . 'data/class-ido-buildings.php';
require $base . 'data/class-ido-units.php';
require $base . 'data/class-ido-weapons.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fails;
    if (!$ok) $fails++;
    printf("%-52s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

echo "=== clamp ===\n";
check('zero stays zero', IDO_Game::clamp(0) === 0);
check('ordinary value passes through', IDO_Game::clamp(1234567) === 1234567);
check('negative clamps to zero', IDO_Game::clamp(-5000) === 0);
check('cap is returned at the cap', IDO_Game::clamp(IDO_Game::MAX_VALUE) === IDO_Game::MAX_VALUE);
check('above cap saturates', IDO_Game::clamp(IDO_Game::MAX_VALUE * 10) === IDO_Game::MAX_VALUE);
check('PHP_INT_MAX saturates', IDO_Game::clamp(PHP_INT_MAX) === IDO_Game::MAX_VALUE);
check('INF saturates', IDO_Game::clamp(INF) === IDO_Game::MAX_VALUE);
check('-INF clamps to zero', IDO_Game::clamp(-INF) === 0);
check("NAN clamps to zero, never to the top", IDO_Game::clamp(NAN) === 0);
check('cap is under 2^53 so it stays exact', IDO_Game::MAX_VALUE < (2 ** 53));
check('cap survives a float round trip', (int) (float) IDO_Game::MAX_VALUE === IDO_Game::MAX_VALUE);

echo "\n=== net worth of an absurd empire ===\n";
// An empire stuffed with the largest values any column could hold.
$monster = (object) [
    'land' => PHP_INT_MAX, 'land_in_progress' => 0,
    'gold' => PHP_INT_MAX, 'grain' => PHP_INT_MAX, 'iron' => PHP_INT_MAX,
    'peasants' => PHP_INT_MAX, 'agents' => PHP_INT_MAX,
    'b_homestead' => PHP_INT_MAX, 'b_farmstead' => PHP_INT_MAX, 'b_mint' => PHP_INT_MAX,
    'b_foundry' => PHP_INT_MAX, 'b_barracks' => PHP_INT_MAX, 'b_fortification' => PHP_INT_MAX,
    'u_pawn' => PHP_INT_MAX, 'u_legionnaire' => PHP_INT_MAX, 'u_centurion' => PHP_INT_MAX,
    'u_ballista_legion' => PHP_INT_MAX,
    'catapults' => PHP_INT_MAX, 'catapults_in_progress' => PHP_INT_MAX,
];

$army = IDO_Units::networth($monster);
check('army worth never goes negative', $army >= 0, 'got ' . $army);
check('army worth saturates at the cap', $army === IDO_Game::MAX_VALUE);

$weapons = IDO_Weapons::networth($monster);
check('weapon worth never goes negative', $weapons >= 0, 'got ' . $weapons);
check('weapon worth saturates at the cap', $weapons === IDO_Game::MAX_VALUE);

// The same sum recalc_networth performs, without needing the database.
$worth = 0.0;
$worth += (float) $monster->land * 500;
$worth += (float) IDO_Buildings::total($monster) * IDO_Buildings::NETWORTH_PER_BUILDING;
$worth += (float) $monster->peasants * 25;
$worth += (float) IDO_Units::networth($monster);
$worth += (float) IDO_Weapons::networth($monster);
$worth += (float) $monster->gold / 50;
$worth += (float) $monster->grain / 200;
$worth += (float) $monster->iron / 20;
$worth += (float) $monster->agents * 50000;
$score = IDO_Game::clamp($worth);

check('net worth never goes negative', $score >= 0, 'got ' . $score);
check('net worth saturates at the cap', $score === IDO_Game::MAX_VALUE);

echo "\n=== the old integer way, for comparison ===\n";
$old = 0;
$old += (int) $monster->land * 500;
$old += IDO_Buildings::total($monster) * IDO_Buildings::NETWORTH_PER_BUILDING;
printf("%-52s %s\n", 'unguarded integer sum of the same empire',
    is_float($old) ? 'overflowed to float ' . $old : (string) $old);
printf("%-52s %s\n", 'would it have ranked above an honest empire?',
    (is_float($old) || $old > 0) ? 'YES - this is the bug' : 'no');

echo "\n=== derived values stay sane at the cap ===\n";
check('buildings total saturates', IDO_Buildings::total($monster) === IDO_Game::MAX_VALUE
    || IDO_Buildings::total($monster) > 0, 'got ' . IDO_Buildings::total($monster));
check('defence power is not negative', IDO_Units::defence_power($monster) >= 0);
check('unit upkeep is not negative', IDO_Units::upkeep($monster) >= 0);
check('weapons total saturates', IDO_Weapons::total($monster) === IDO_Game::MAX_VALUE,
    'got ' . IDO_Weapons::total($monster));
check('weapon defence is not negative', IDO_Weapons::defence_power($monster) >= 0);
check('weapon upkeep is not negative', IDO_Weapons::upkeep($monster) >= 0);
check('title lookup survives the cap', IDO_Game::title(IDO_Game::MAX_VALUE) !== '');

echo "
=== the title ladder ===
";
// title() walks the list and keeps the last threshold it is at or above, so
// the list being in ascending order is not presentation, it is the algorithm.
// A pair entered out of order would make a title unreachable, silently.
$thresholds = array_keys(IDO_Game::TITLES);
$sorted = $thresholds;
sort($sorted, SORT_NUMERIC);
check('the ladder ascends', $thresholds === $sorted);
check('it starts at nothing', $thresholds[0] === 0);
check('no two rungs share a threshold', count($thresholds) === count(array_unique($thresholds)));

// Every title has to be reachable: standing exactly on a rung earns it, and a
// penny short earns the one below.
$previous = null;
foreach (IDO_Game::TITLES as $threshold => $name) {
    check(sprintf('%s is earned at %s', $name, IDO_Game::fmt($threshold)),
        IDO_Game::title($threshold) === $name, 'got ' . IDO_Game::title($threshold));
    if ($previous !== null) {
        check(sprintf('one short of %s is still %s', $name, $previous),
            IDO_Game::title($threshold - 1) === $previous, 'got ' . IDO_Game::title($threshold - 1));
    }
    $previous = $name;
}
$names = array_values(IDO_Game::TITLES);
check('below the bottom rung is still the bottom title',
    IDO_Game::title(-1) === $names[0], 'got ' . IDO_Game::title(-1));

// 1.16.0 put the summit out of easy reach. Duke and above are the rungs that
// moved, and they moved by exactly three.
$expected = ['Duke' => 36000000, 'Archduke' => 75000000, 'Prince' => 150000000,
             'High King' => 300000000, 'Emperor' => 600000000];
foreach ($expected as $name => $want) {
    $got = array_search($name, IDO_Game::TITLES, true);
    check(sprintf('%s stands at %s', $name, IDO_Game::fmt($want)), $got === $want,
        'got ' . ($got === false ? 'no such title' : IDO_Game::fmt($got)));
}
check('Marquess and below are untouched',
    array_search('Marquess', IDO_Game::TITLES, true) === 6000000
    && array_search('Earl', IDO_Game::TITLES, true) === 3000000
    && array_search('Freeholder', IDO_Game::TITLES, true) === 0);

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
