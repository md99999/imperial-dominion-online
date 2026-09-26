<?php
/**
 * The catapult rules, checked away from the database.
 *
 * Two things are worth pinning down. One is the division of spoils: the winner
 * takes a share of what the loser had at stake, a further share burns, and
 * neither rounding nor a silly setting may ever claim more engines than were
 * there to lose. The other is that an engine costs no acre and no peasant,
 * which is the whole reason a captured one never has to find land to stand on.
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
require $base . 'data/class-ido-engines.php';
require $base . 'services/class-military-service.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = '') {
    global $fails;
    if (!$ok) $fails++;
    printf("%-56s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

/** Runs engine_spoils() with a given capture/destroy pair. */
function spoils(int $stake, int $capture = 30, int $destroy = 10): array {
    $GLOBALS['ido_test_settings'] = [
        'catapult_capture_percent' => $capture,
        'catapult_destroy_percent' => $destroy,
    ];
    return IDO_Military::engine_spoils($stake);
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
// With both shares at 60%, only 100 engines exist to divide. The captured
// share is honoured in full and the wrecked share takes what is left, because
// an engine already dragged away cannot also be burned on the field.
$s = spoils(100, 60, 60);
check('the winner still gets its full 60', $s['captured'] === 60, 'got ' . $s['captured']);
check('the wrecked share takes only the remainder', $s['wrecked'] === 40, 'got ' . $s['wrecked']);

echo "\n=== the stake is never fractional ===\n";
foreach ([1, 2, 3, 5, 13, 99, 1001] as $stake) {
    $s = spoils($stake);
    check(sprintf('a stake of %d divides into whole engines', $stake),
        is_int($s['captured']) && is_int($s['wrecked']) && $s['captured'] + $s['wrecked'] <= $stake);
}

echo "\n=== an engine is not a building and not a troop ===\n";
check('catapults are not a building type', !IDO_Buildings::exists('catapult'));
check('catapults are not a troop type', !IDO_Units::exists('catapult'));
check('catapults are an engine type', IDO_Engines::exists('catapult'));
check('an engine costs no peasants', !array_key_exists('peasants', IDO_Engines::get('catapult')));
check('the engine column is not prefixed like a building',
    IDO_Engines::column('catapult') === 'catapults'
    && IDO_Engines::column('catapult') !== IDO_Buildings::column('catapult'));
check('an engine under construction has its own counter',
    IDO_Engines::progress_column('catapult') === 'catapults_in_progress');

echo "\n=== an engine fights on both sides of a war ===\n";
$engine = IDO_Engines::get('catapult');
check('it carries an attack', $engine['offence'] > 0);
check('it holds a wall', $engine['defence'] > 0);
check('its crews eat', $engine['upkeep'] > 0);

$train = ['catapult' => 40];
check('a train adds to the offence',
    IDO_Engines::offence_power($train) === (float) (40 * $engine['offence']),
    'got ' . IDO_Engines::offence_power($train));
check('an unknown engine adds nothing', IDO_Engines::offence_power(['trebuchet' => 9999]) === 0.0);
check('a negative count adds nothing', IDO_Engines::offence_power(['catapult' => -50]) === 0.0);

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
