<?php
/**
 * A league ruleset is data from another site, and is refused whole when any of
 * it is out of range.
 *
 * Before this existed a hub's values were cast to int and applied as they came:
 * a negative market tax paid sellers eleven times the price, a negative build
 * cost paid rulers to build, and a million turns a day was obeyed. The design
 * document always said those were refused; this proves that they now are, and
 * that the game master's own Settings screen is held to the same table.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', __DIR__ . '/');

$GLOBALS['ido_options'] = [];
function get_option($name, $default = false) { return $GLOBALS['ido_options'][$name] ?? $default; }
function update_option($name, $value) { $GLOBALS['ido_options'][$name] = $value; return true; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, (array) $args); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
// No league tables on this fake site, so nothing is governed and every
// setting is the game master's to save.
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) { return $q; }
    public function get_var($q) { return null; }
};

require __DIR__ . '/../includes/class-ido-core.php';
require __DIR__ . '/../includes/league/class-ido-league.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-66s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

// A ruleset built the way a hub builds its own: every governed key at its default.
$defaults = IDO_Settings::defaults();
$good = [];
foreach (IDO_League::governed_keys() as $key) {
    if (array_key_exists($key, $defaults)) $good[$key] = (int) $defaults[$key];
}

echo "=== every default is inside its own bounds ===\n";
$outside = [];
foreach ($defaults as $key => $value) {
    if (in_array($key, IDO_Settings::text_keys(), true)) continue;
    if (!IDO_Settings::in_bounds($key, $value)) $outside[] = $key;
}
check('no shipped default is out of range', $outside === [], implode(', ', $outside));
check('every governed key has a bound of its own',
    array_diff(IDO_League::governed_keys(), array_keys(IDO_Settings::bounds())) === []);

echo "\n=== a sound ruleset is accepted ===\n";
check('the defaults pass', IDO_League::ruleset_problem($good) === null,
    (string) IDO_League::ruleset_problem($good));
check('an empty ruleset passes (nothing governed)', IDO_League::ruleset_problem([]) === null);
check('numeric strings from JSON pass', IDO_League::ruleset_problem(['turns_per_day' => '30']) === null);

echo "\n=== what a hostile hub might send ===\n";
$refused = static function (array $patch) use ($good): bool {
    return IDO_League::ruleset_problem(array_merge($good, $patch)) !== null;
};
check('a negative market tax', $refused(['market_tax_percent' => -1000]));
check('a market tax over 50%', $refused(['market_tax_percent' => 51]));
check('a negative build cost', $refused(['build_gold_per_acre' => -300]));
check('a million turns a day', $refused(['turns_per_day' => 1000000]));
check('zero turns a day', $refused(['turns_per_day' => 0]));
check('a capture share over 100%', $refused(['catapult_capture_percent' => 500]));
check('a turn cap below a day of turns', $refused(['turns_per_day' => 50, 'turn_cap' => 10]));
check('a target band upside down', $refused(['target_min_percent' => 80, 'target_max_percent' => 40]));
check('a float', $refused(['starting_gold' => 1.5]));
check('a numeric string with junk', $refused(['starting_gold' => '75000abc']));
check('scientific notation', $refused(['starting_gold' => '1e18']));
check('a boolean', $refused(['barbarians_enabled' => true]));
check('a nested array', $refused(['starting_gold' => [1]]));
check('null', $refused(['starting_gold' => null]));
check('a key the league does not govern', $refused(['league_endpoint' => 1]));
check('a key that is not a setting at all', $refused(['<script>' => 1]));
check('an integer key', IDO_League::ruleset_problem([0 => 1]) !== null);

$message = (string) IDO_League::ruleset_problem(['<script>alert(1)</script>' => 1]);
check('the refusal message carries no markup', strpbrk($message, '<>') === false, $message);

echo "\n=== the game master's own screen uses the same table ===\n";
$saved = IDO_Settings::update([
    'market_tax_percent' => '-5',
    'turns_per_day'      => '99999999',
    'catapult_capture_percent' => '500',
    'starting_gold'      => '75000',
]);
check('a negative tax is pulled up to 0', $saved['market_tax_percent'] === 0);
check('turns per day is pulled down to its ceiling', $saved['turns_per_day'] === 1000);
check('a percentage is pulled down to 100', $saved['catapult_capture_percent'] === 100);
check('an in-range value is kept as typed', $saved['starting_gold'] === 75000);

echo "\n" . ($fails === 0 ? 'All checks passed.' : $fails . ' check(s) FAILED.') . "\n";
exit($fails === 0 ? 0 : 1);
