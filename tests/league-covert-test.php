<?php
/**
 * The agent who rides with the army.
 *
 * Pure arithmetic here: the odds, the two rolls, and what one successful night
 * is worth. The single-agent slot is a guarded database write and is tested
 * against a real database in tests/integration-queue.php, because a race is not
 * something a stub can prove anything about.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
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
class IDO_League_Status { const MUSTERING = 'mustering'; }
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
check('a great host gives an agent cover', IDO_League_Covert::chance(4000, 1000) > $even);
check('a token raid leaves him conspicuous', IDO_League_Covert::chance(250, 1000) < $even);
check('never a certainty', IDO_League_Covert::chance(PHP_INT_MAX, 1) <= 90);
check('never hopeless', IDO_League_Covert::chance(1, PHP_INT_MAX) >= 10);
check('a defence of nothing does not divide by zero', IDO_League_Covert::chance(1000, 0) > 0);

echo "\n=== it is the local court's arithmetic, not a copy of it ===\n";
check('the odds come from IDO_Covert::odds',
    IDO_League_Covert::chance(1000, 1000)
    === IDO_Covert::odds((int) IDO_League_Covert::op()['chance'], IDO_Covert::ratio(1000, 1000), 90));
check('the ratio is bounded the same way on both sides of even',
    IDO_Covert::ratio(PHP_INT_MAX, 1) === 2.0 && IDO_Covert::ratio(1, PHP_INT_MAX) === 0.5);
check('a local mission still tops out at 95', IDO_Covert::odds(85, 2.0) === 95);
check('and a league one at 90', IDO_Covert::odds(85, 2.0, 90) === 90);

echo "\n=== the two rolls ===\n";
$GLOBALS['ido_forced_roll'] = 1;          // both rolls win
$good = IDO_League_Covert::resolve(1000, 1000);
check('a winning roll succeeds', $good['success']);
check('and the agent walks out', !$good['lost']);

$GLOBALS['ido_forced_roll'] = 100;        // the mission fails and the risk roll misses
$bad = IDO_League_Covert::resolve(1000, 1000);
check('a losing roll fails', !$bad['success']);
check('and at a roll of 100 he is not taken', !$bad['lost']);
unset($GLOBALS['ido_forced_roll']);

echo "\n=== only a failed agent can hang ===\n";
$hanged = 0;
$succeeded_and_hanged = 0;
for ($i = 0; $i < 3000; $i++) {
    $one = IDO_League_Covert::resolve(1000, 1000);
    if ($one['lost']) $hanged++;
    if ($one['success'] && $one['lost']) $succeeded_and_hanged++;
}
check('an agent who opens the gates always walks out', $succeeded_and_hanged === 0);
check('and some of the failures hang', $hanged > 0, $hanged . ' of 3000');
check('but not all of them', $hanged < 3000);

echo "\n=== what a night's work is worth ===\n";
check('a failed agent helps nobody', IDO_League_Covert::wall_reduction(false) === 0.0);
check('a successful one halves the fortifications', IDO_League_Covert::wall_reduction(true) === 0.5);
check('and never more, because there is only ever one', IDO_League_Covert::wall_reduction(true) < 1.0);

echo "\n=== the mission is dearer and deadlier than a local one ===\n";
$op = IDO_League_Covert::op();
check('it costs real gold', IDO_League_Covert::cost() >= 45000, (string) IDO_League_Covert::cost());
check('the odds are worse than local sabotage', (int) $op['chance'] < 65, (string) $op['chance']);
check('and the rope is likelier', (int) $op['risk'] > 35, (string) $op['risk']);

echo "\n=== what the ruler is told ===\n";
check('nothing at all when none was sent',
    IDO_League_Covert::summary(false, false, false, 'Northmarch') === '');
$none = IDO_League_Covert::summary(true, false, false, 'Northmarch');
check('a quiet failure says so without drama', stripos($none, 'came home with the army') !== false, $none);
$caught = IDO_League_Covert::summary(true, false, true, 'Northmarch');
check('a hanging reads as one man, not a tally',
    strpos($caught, 'Your agent') === 0 && stripos($caught, 'hanged at the gate') !== false, $caught);
check('and a success the same way',
    strpos(IDO_League_Covert::summary(true, true, false, 'Northmarch'), 'Your agent got inside') === 0);

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
