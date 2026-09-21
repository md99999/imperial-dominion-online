<?php
/**
 * Tries to make the score go negative, the way the old BRE exploit did.
 */
define('ABSPATH', __DIR__ . '/');
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function esc_html($s) { return $s; }

$base = __DIR__ . '/../includes/';
require $base . 'class-ido-core.php';
require $base . 'data/class-ido-buildings.php';
require $base . 'data/class-ido-units.php';

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

echo "\n=== net worth of an absurd kingdom ===\n";
// A kingdom stuffed with the largest values any column could hold.
$monster = (object) [
    'land' => PHP_INT_MAX, 'land_in_progress' => 0,
    'gold' => PHP_INT_MAX, 'grain' => PHP_INT_MAX, 'iron' => PHP_INT_MAX,
    'peasants' => PHP_INT_MAX, 'agents' => PHP_INT_MAX,
    'b_homestead' => PHP_INT_MAX, 'b_farmstead' => PHP_INT_MAX, 'b_counting_house' => PHP_INT_MAX,
    'b_foundry' => PHP_INT_MAX, 'b_barracks' => PHP_INT_MAX, 'b_fortification' => PHP_INT_MAX,
    'u_pawn' => PHP_INT_MAX, 'u_knight' => PHP_INT_MAX, 'u_squire' => PHP_INT_MAX, 'u_rook' => PHP_INT_MAX,
];

$army = IDO_Units::networth($monster);
check('army worth never goes negative', $army >= 0, 'got ' . $army);
check('army worth saturates at the cap', $army === IDO_Game::MAX_VALUE);

// The same sum recalc_networth performs, without needing the database.
$worth = 0.0;
$worth += (float) $monster->land * 500;
$worth += (float) IDO_Buildings::total($monster) * IDO_Buildings::NETWORTH_PER_BUILDING;
$worth += (float) $monster->peasants * 25;
$worth += (float) IDO_Units::networth($monster);
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
printf("%-52s %s\n", 'unguarded integer sum of the same kingdom',
    is_float($old) ? 'overflowed to float ' . $old : (string) $old);
printf("%-52s %s\n", 'would it have ranked above an honest kingdom?',
    (is_float($old) || $old > 0) ? 'YES - this is the bug' : 'no');

echo "\n=== derived values stay sane at the cap ===\n";
check('buildings total saturates', IDO_Buildings::total($monster) === IDO_Game::MAX_VALUE
    || IDO_Buildings::total($monster) > 0, 'got ' . IDO_Buildings::total($monster));
check('defence power is not negative', IDO_Units::defence_power($monster) >= 0);
check('unit upkeep is not negative', IDO_Units::upkeep($monster) >= 0);
check('title lookup survives the cap', IDO_Game::title(IDO_Game::MAX_VALUE) !== '');

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
