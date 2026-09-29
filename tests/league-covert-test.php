<?php
/**
 * The agent who rides with the army.
 *
 * Pure arithmetic, so it is tested the way the barbarians were: the odds, the
 * two rolls, what a success is worth, and above all the ceiling, because a
 * mechanic that can switch a defender's walls off entirely is a mechanic that
 * ends sieges before they start.
 */
define('ABSPATH', __DIR__ . '/');

function wp_rand($min = 0, $max = 1) { return $GLOBALS['ido_forced_roll'] ?? random_int($min, $max); }

// The league mission runs the local court's arithmetic, so the real class is
// loaded rather than stubbed. A stub here would let the two drift apart, which is
// the exact failure sharing the code exists to prevent.
class IDO_Game_Exception extends Exception {}
class IDO_Settings { public static function int(string $k): int { return 1; } }
class IDO_DB { public static function t(string $n): string { return 'wp_ido_' . $n; } }
class IDO_Game {
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function fmt($n): string { return number_format((float) $n); }
}
require __DIR__ . '/../includes/services/class-covert-service.php';
require __DIR__ . '/../includes/league/class-ido-league-covert.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-60s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

echo "=== the odds ===\n";
$even = IDO_League_Covert::chance(1000, 1000);
check('an even fight sits near the base chance', $even >= 50 && $even <= 60, (string) $even);

$strong = IDO_League_Covert::chance(4000, 1000);
$weak   = IDO_League_Covert::chance(250, 1000);
check('a great host gives an agent cover', $strong > $even, sprintf('%d vs %d', $strong, $even));
check('a token raid leaves him conspicuous', $weak < $even, sprintf('%d vs %d', $weak, $even));
check('never a certainty', IDO_League_Covert::chance(PHP_INT_MAX, 1) <= 90);
check('never hopeless', IDO_League_Covert::chance(1, PHP_INT_MAX) >= 10);
check('a defence of nothing does not divide by zero', IDO_League_Covert::chance(1000, 0) > 0);

echo "\n=== the two rolls ===\n";
$GLOBALS['ido_forced_roll'] = 1;          // everything succeeds
$all_good = IDO_League_Covert::resolve([1, 2, 3], 1000, 1000);
check('a winning roll succeeds', $all_good['successes'] === 3);
check('and no agent is lost', count(array_filter($all_good['results'], static fn($r) => $r['lost'])) === 0);

$GLOBALS['ido_forced_roll'] = 100;        // everything fails, and the risk roll fails too
$all_bad = IDO_League_Covert::resolve([1, 2, 3], 1000, 1000);
check('a losing roll fails', $all_bad['successes'] === 0);
check('and at a roll of 100 nobody is taken', count(array_filter($all_bad['results'], static fn($r) => $r['lost'])) === 0);

// A failed mission with a low risk roll: the agent is caught.
$GLOBALS['ido_forced_roll'] = null;
unset($GLOBALS['ido_forced_roll']);

echo "\n=== only a failed agent can hang ===\n";
$hanged = 0;
$succeeded_and_hanged = 0;
for ($i = 0; $i < 3000; $i++) {
    $one = IDO_League_Covert::resolve([7], 1000, 1000)['results'][7];
    if ($one['lost']) $hanged++;
    if ($one['success'] && $one['lost']) $succeeded_and_hanged++;
}
check('an agent who opens the gates always walks out', $succeeded_and_hanged === 0);
check('and some of the failures hang', $hanged > 0, $hanged . ' of 3000');
check('but not all of them', $hanged < 3000);

echo "\n=== what a night's work is worth ===\n";
check('no agent, no help', IDO_League_Covert::wall_reduction(0) === 0.0);
check('one agent halves the fortifications', IDO_League_Covert::wall_reduction(1) === 0.5);
check('two do better', IDO_League_Covert::wall_reduction(2) > IDO_League_Covert::wall_reduction(1));
check('but with diminishing returns',
    IDO_League_Covert::wall_reduction(2) - IDO_League_Covert::wall_reduction(1)
    < IDO_League_Covert::wall_reduction(1));
check('ten agents cannot flatten a wall', IDO_League_Covert::wall_reduction(10) <= 0.75);
check('nor can a hundred', IDO_League_Covert::wall_reduction(100) <= 0.75);
check('the cap leaves the defender something', IDO_League_Covert::wall_reduction(100) < 1.0);

echo "\n=== it is the local court's arithmetic, not a copy of it ===\n";
check('the odds come from IDO_Covert::odds',
    IDO_League_Covert::chance(1000, 1000)
    === IDO_Covert::odds((int) IDO_League_Covert::op()['chance'], IDO_Covert::ratio(1000, 1000), 90));
check('the ratio is bounded the same way on both sides of even',
    IDO_Covert::ratio(PHP_INT_MAX, 1) === 2.0 && IDO_Covert::ratio(1, PHP_INT_MAX) === 0.5);
check('a local mission still tops out at 95', IDO_Covert::odds(85, 2.0) === 95, (string) IDO_Covert::odds(85, 2.0));
check('and a league one at 90', IDO_Covert::odds(85, 2.0, 90) === 90, (string) IDO_Covert::odds(85, 2.0, 90));
$GLOBALS['ido_forced_roll'] = 1;
check('the shared attempt succeeds on a winning roll', IDO_Covert::attempt(55, 45)['success']);
check('and never hangs an agent who succeeded', !IDO_Covert::attempt(55, 45)['lost']);
unset($GLOBALS['ido_forced_roll']);

echo "\n=== the mission is dearer and deadlier than a local one ===\n";
$op = IDO_League_Covert::op();
check('it costs real gold', IDO_League_Covert::cost() >= 45000, (string) IDO_League_Covert::cost());
check('the odds are worse than local sabotage', (int) $op['chance'] < 65, (string) $op['chance']);
check('and the rope is likelier', (int) $op['risk'] > 35, (string) $op['risk']);

echo "\n=== what the site is told ===\n";
check('nothing at all when nobody sent one', IDO_League_Covert::summary(0, 0, 0, 'Northmarch') === '');
$won = IDO_League_Covert::summary(3, 2, 1, 'Northmarch');
check('a mixed night names all three numbers',
    strpos($won, '2 of 3') !== false && strpos($won, 'Northmarch') !== false
    && stripos($won, 'hanged') !== false, $won);
$none = IDO_League_Covert::summary(1, 0, 0, 'Northmarch');
check('a quiet failure says so without drama', stripos($none, 'came home with the army') !== false, $none);
$caught = IDO_League_Covert::summary(1, 0, 1, 'Northmarch');
check('one agent hanged reads as one man, not a tally',
    strpos($caught, 'Your agent') === 0 && strpos($caught, 'One was caught') === false
    && stripos($caught, 'hanged at the gate') !== false, $caught);
check('one agent succeeding reads the same way',
    strpos(IDO_League_Covert::summary(1, 1, 0, 'Northmarch'), 'Your agent got inside') === 0,
    IDO_League_Covert::summary(1, 1, 0, 'Northmarch'));

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
