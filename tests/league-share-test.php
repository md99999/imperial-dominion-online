<?php
/**
 * Splitting whole things by share.
 *
 * Small and worth testing hard, because it runs on every march in both
 * directions and a rounding error here is a slow leak of troops or gold that
 * nobody would notice until the numbers stopped adding up.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', __DIR__ . '/');

require __DIR__ . '/../includes/league/class-ido-league-share.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-58s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

echo "=== it always adds up ===\n";
$cases = [
    [97, [1 => 1, 2 => 1, 3 => 1]],
    [100, [1 => 1, 2 => 2, 3 => 3]],
    [7, [1 => 1000000, 2 => 1]],
    [1, [1 => 1, 2 => 1]],
    [3, [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1]],
    [999999, [1 => 3.7, 2 => 96.3]],
    [10, [1 => 0.0001, 2 => 0.0002, 3 => 0.0003]],
];
foreach ($cases as [$total, $weights]) {
    $split = IDO_League_Share::split($total, $weights);
    check(sprintf('%d across %d shares', $total, count($weights)),
        IDO_League_Share::reconciles($total, $split), implode('+', $split));
}

echo "\n=== a thousand random splits ===\n";
$bad = 0;
for ($i = 0; $i < 1000; $i++) {
    $weights = [];
    $n = random_int(1, 8);
    for ($k = 1; $k <= $n; $k++) $weights[$k] = random_int(0, 100000);
    $total = random_int(0, 1000000);
    $split = IDO_League_Share::split($total, $weights);
    if (!IDO_League_Share::reconciles($total, $split)) { $bad++; continue; }
    foreach ($split as $key => $share) {
        if ($share < 0) $bad++;
        if ($weights[$key] <= 0 && $share > 0) $bad++;   // no claim, no share
    }
}
check('every one reconciles and nobody is paid for nothing', $bad === 0, $bad . ' bad');

echo "\n=== the edges ===\n";
check('nothing to split gives everybody nothing',
    IDO_League_Share::split(0, [1 => 5, 2 => 5]) === [1 => 0, 2 => 0]);
check('no weights at all gives an empty answer', IDO_League_Share::split(10, []) === []);
check('all weights zero distributes nothing',
    IDO_League_Share::split(10, [1 => 0, 2 => 0]) === [1 => 0, 2 => 0]);
check('a negative total is not a windfall',
    IDO_League_Share::split(-50, [1 => 1]) === [1 => 0]);
check('a negative weight is treated as no claim',
    IDO_League_Share::split(10, [1 => -5, 2 => 5]) === [1 => 0, 2 => 10]);
check('one claimant takes the lot', IDO_League_Share::split(97, [7 => 3]) === [7 => 97]);

echo "\n=== it is proportional, not merely exact ===\n";
$split = IDO_League_Share::split(1000, [1 => 90, 2 => 10]);
check('nine tenths of the weight takes about nine tenths', $split[1] === 900 && $split[2] === 100,
    $split[1] . '/' . $split[2]);
$tiny = IDO_League_Share::split(1000000, [1 => 1, 2 => 999999]);
check('a tiny share still gets something when there is enough to go round',
    $tiny[1] >= 1, (string) $tiny[1]);
$one = IDO_League_Share::split(1, [1 => 60, 2 => 40]);
check('a single indivisible unit goes to the larger share',
    $one[1] === 1 && $one[2] === 0, $one[1] . '/' . $one[2]);

echo "\n=== the same answer every time ===\n";
$weights = [5 => 10, 3 => 10, 9 => 10, 1 => 10];
$first = IDO_League_Share::split(7, $weights);
$same = true;
for ($i = 0; $i < 50; $i++) {
    if (IDO_League_Share::split(7, $weights) !== $first) $same = false;
}
check('ties break the same way on every run', $same, implode(',', $first));
// The same weights in a different order must give each key the same answer: a
// split that depended on row order is one two sites could disagree about.
$shuffled = [9 => 10, 1 => 10, 5 => 10, 3 => 10];
$other = IDO_League_Share::split(7, $shuffled);
ksort($first); ksort($other);
check('and do not depend on the order the rows came back in', $first === $other,
    json_encode($first) . ' vs ' . json_encode($other));

echo "\n=== a whole map at once ===\n";
$map = IDO_League_Share::split_map(['gold' => 100, 'grain' => 51], [1 => 1, 2 => 1]);
check('every resource reconciles on its own',
    $map[1]['gold'] + $map[2]['gold'] === 100 && $map[1]['grain'] + $map[2]['grain'] === 51);
check('and each contributor gets an entry for each', isset($map[1]['gold'], $map[1]['grain']));

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
