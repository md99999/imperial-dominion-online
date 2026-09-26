<?php
/**
 * The catapult rules, checked away from the database.
 *
 * Three things are worth pinning down. The division of spoils: the winner takes
 * a share of what the loser had at stake, a further share burns, and neither
 * rounding nor a silly setting may ever claim more weapons than were there to
 * lose. That a weapon costs no acre and no peasant, which is the whole reason a
 * captured one never has to find land to stand on. And the crew rule: a
 * catapult is worked by legionnaires, so one with nobody on it counts for
 * nothing, however many an empire has paid for.
 */
define('ABSPATH', __DIR__ . '/');

function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function esc_html($s) { return $s; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }

// The settings the spoils rule reads. Each case sets these before it runs.
$GLOBALS['ido_test_settings'] = [];
function get_option($name, $default = false) {
    if ($name === IDO_Settings::OPTION) return $GLOBALS['ido_test_settings'];
    return $default;
}

$base = __DIR__ . '/../includes/';
require $base . 'class-ido-core.php';
require $base . 'data/class-ido-buildings.php';
require $base . 'data/class-ido-units.php';
require $base . 'data/class-ido-weapons.php';
require $base . 'services/class-military-service.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fails;
    if (!$ok) $fails++;
    printf("%-56s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

/** Runs weapon_spoils() with a given capture/destroy pair. */
function spoils(int $stake, int $capture = 30, int $destroy = 10): array {
    $GLOBALS['ido_test_settings'] = [
        'catapult_capture_percent' => $capture,
        'catapult_destroy_percent' => $destroy,
    ];
    return IDO_Military::weapon_spoils($stake);
}

echo "=== the default 30/10 split ===\n";
$s = spoils(100);
check('winner takes 30 of 100', $s['captured'] === 30, 'got ' . $s['captured']);
check('10 of 100 are smashed', $s['wrecked'] === 10, 'got ' . $s['wrecked']);
check('the loser is out 40 in total', $s['captured'] + $s['wrecked'] === 40);

$s = spoils(7);
check('a small stake still rounds to something', $s['captured'] + $s['wrecked'] > 0,
    sprintf('%d taken, %d wrecked', $s['captured'], $s['wrecked']));
check('a small stake never loses more than it had', $s['captured'] + $s['wrecked'] <= 7);

echo "\n=== nothing at stake ===\n";
foreach ([0, -1, -5000] as $stake) {
    $s = spoils($stake);
    check(sprintf('a stake of %d costs nothing', $stake), $s['captured'] === 0 && $s['wrecked'] === 0);
}

echo "\n=== settings that would take more than was there ===\n";
$cases = [
    'capture alone at 100%'       => [100, 0],
    'capture and destroy at 100%' => [100, 100],
    'destroy at 100%'             => [0, 100],
    'both at 80%'                 => [80, 80],
    'a negative capture'          => [-50, 10],
    'a capture above 100'         => [400, 400],
];
foreach ($cases as $label => [$capture, $destroy]) {
    $s = spoils(250, $capture, $destroy);
    $total = $s['captured'] + $s['wrecked'];
    check($label . ' never exceeds the stake', $total <= 250, sprintf(
        '%d taken, %d wrecked, %d of 250', $s['captured'], $s['wrecked'], $total
    ));
    check($label . ' never goes negative', $s['captured'] >= 0 && $s['wrecked'] >= 0);
}

echo "\n=== capture comes before the torch ===\n";
// With both shares at 60%, only 100 weapons exist to divide. The captured
// share is honoured in full and the wrecked share takes what is left, because
// a weapon already dragged away cannot also be burned on the field.
$s = spoils(100, 60, 60);
check('the winner still gets its full 60', $s['captured'] === 60, 'got ' . $s['captured']);
check('the wrecked share takes only the remainder', $s['wrecked'] === 40, 'got ' . $s['wrecked']);

echo "\n=== the stake is never fractional ===\n";
foreach ([1, 2, 3, 5, 13, 99, 1001] as $stake) {
    $s = spoils($stake);
    check(sprintf('a stake of %d divides into whole weapons', $stake),
        is_int($s['captured']) && is_int($s['wrecked']) && $s['captured'] + $s['wrecked'] <= $stake);
}

echo "\n=== a weapon is not a building and not a troop ===\n";
check('catapults are not a building type', !IDO_Buildings::exists('catapult'));
check('catapults are not a troop type', !IDO_Units::exists('catapult'));
check('catapults are a weapon type', IDO_Weapons::exists('catapult'));
check('a weapon costs no peasants', !array_key_exists('peasants', IDO_Weapons::get('catapult')));
check('the weapon column is not prefixed like a building',
    IDO_Weapons::column('catapult') === 'catapults'
    && IDO_Weapons::column('catapult') !== IDO_Buildings::column('catapult'));
check('a weapon under construction has its own counter',
    IDO_Weapons::progress_column('catapult') === 'catapults_in_progress');

echo "\n=== a weapon fights on both sides of a war ===\n";
$weapon = IDO_Weapons::get('catapult');
check('it carries an attack', $weapon['offence'] > 0);
check('it holds a wall', $weapon['defence'] > 0);
check('its crews eat', $weapon['upkeep'] > 0);

$train = ['catapult' => 40];
check('a train adds to the offence',
    IDO_Weapons::offence_power($train) === (float) (40 * $weapon['offence']),
    'got ' . IDO_Weapons::offence_power($train));
check('an unknown weapon adds nothing', IDO_Weapons::offence_power(['trebuchet' => 9999]) === 0.0);
check('a negative count adds nothing', IDO_Weapons::offence_power(['catapult' => -50]) === 0.0);

echo "\n=== a catapult needs men to work it ===\n";
check('catapults are crewed by legionnaires', IDO_Weapons::crew_unit('catapult') === 'legionnaire');
check('the crew is a positive number', IDO_Weapons::crew_each('catapult') > 0);

$per = IDO_Weapons::crew_each('catapult');
$needed = IDO_Weapons::crew_needed(['catapult' => 20]);
check('20 catapults ask for 20 crews',
    ($needed['legionnaire'] ?? 0) === 20 * $per, 'got ' . var_export($needed, true));
check('an empty train asks for nobody', IDO_Weapons::crew_needed([]) === []);
check('an unknown weapon asks for nobody', IDO_Weapons::crew_needed(['trebuchet' => 50]) === []);
check('a zero count asks for nobody', IDO_Weapons::crew_needed(['catapult' => 0]) === []);

/** An empire row with the given catapults and legionnaires standing. */
function standing(int $catapults, int $legionnaires): object {
    return (object) [
        'catapults' => $catapults, 'catapults_in_progress' => 0,
        'u_pawn' => 0, 'u_legionnaire' => $legionnaires, 'u_centurion' => 0, 'u_ballista_legion' => 0,
        'b_homestead' => 0, 'b_farmstead' => 0, 'b_mint' => 0, 'b_foundry' => 0,
        'b_barracks' => 0, 'b_fortification' => 0,
    ];
}

echo "\n=== only manned catapults count at home ===\n";
$cases = [
    // catapults, legionnaires, how many should be manned
    [10, 0,          0],
    [10, $per - 1,   0],
    [10, $per,       1],
    [10, $per * 4,   4],
    [10, $per * 10, 10],
    [10, $per * 99, 10],   // more crew than weapons changes nothing
    [0,  $per * 50,  0],
];
foreach ($cases as [$catapults, $legionnaires, $expected]) {
    $manned = IDO_Weapons::crewed(standing($catapults, $legionnaires));
    check(sprintf('%d catapults and %d legionnaires man %d', $catapults, $legionnaires, $expected),
        $manned['catapult'] === $expected, 'got ' . $manned['catapult']);
}

$weapon = IDO_Weapons::get('catapult');
check('an unmanned wall of catapults defends nothing',
    IDO_Weapons::defence_power(standing(500, 0)) === 0.0,
    'got ' . IDO_Weapons::defence_power(standing(500, 0)));
check('a manned catapult defends',
    IDO_Weapons::defence_power(standing(1, $per)) === (float) $weapon['defence'],
    'got ' . IDO_Weapons::defence_power(standing(1, $per)));
check('defence counts the manned ones only',
    IDO_Weapons::defence_power(standing(100, $per * 3)) === (float) (3 * $weapon['defence']),
    'got ' . IDO_Weapons::defence_power(standing(100, $per * 3)));
check('net worth still counts every catapult owned, manned or not',
    IDO_Weapons::networth(standing(100, 0)) === IDO_Weapons::networth(standing(100, $per * 100)));

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
