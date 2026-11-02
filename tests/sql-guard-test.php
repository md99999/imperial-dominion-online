<?php
/**
 * No value reaches the text of a SQL statement unless a person has looked at it.
 *
 * The defence against SQL injection in this plugin is structural: every value
 * travels as a bound parameter through $wpdb->prepare(), insert(), update() or
 * delete(), so a quote, a semicolon or the word DROP arriving in a packet is
 * stored as text and never read as SQL. A keyword filter would be the weaker
 * of the two defences and would refuse real names ("Union of the North").
 *
 * What prepare() cannot bind is an identifier: a table name, a column name, the
 * width of an IN () list. Those are spliced into the statement text, and each
 * one has to come from this plugin's own code rather than from input. This
 * test reads every $wpdb query in the shipped code and lists each variable
 * that appears in a statement's text, outside the bound values. The list is
 * pinned below. A new one fails the build until somebody has checked where it
 * comes from and added it here, the same rule the include check follows.
 *
 *   php tests/sql-guard-test.php
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-64s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

$root = realpath(__DIR__ . '/..');
$skip = ['/tests/', '/tools/', '/.git/'];

/**
 * Every variable spliced into SQL text, as "file: $name", and why it is safe.
 * Only the first argument of prepare() is statement text; the rest are bound.
 */
$reviewed = [
    // A table name from the plugin's own fixed list, or the migration's.
    'includes/class-ido-installer.php: $table'        => 'IDO_DB::t() of a constant table list',
    'includes/league/class-ido-league.php: $table'    => 'loop over self::TABLES, a constant',
    'uninstall.php: $table'                           => 'loop over a constant table list',
    // Placeholder lists: a string of %s, never a value.
    'includes/services/class-rankings-service.php: $slots' => "implode(',', array_fill(..., '%s'))",
    // A statement assembled from literals and placeholders, then prepared.
    'includes/league/class-ido-league-queue.php: $sql'      => 'literals plus a %s list, prepared',
    'includes/league/class-ido-league-queue.php: $in'       => 'a literal string',
    'includes/league/class-ido-league-queue.php: $out'      => 'a literal string',
    // Column names from the game's own data classes, never from a request.
    'includes/services/class-construction-service.php: $standing_column' => 'IDO_Buildings/IDO_Weapons::column() of a stored order',
    'includes/services/class-construction-service.php: $progress_column' => "IDO_Weapons::progress_column() or a literal",
    // Statements built from literals, IDO_DB::t() and placeholders, then prepared.
    'includes/services/class-kingdom-service.php: $sql' => 'pay(): columns checked against numeric_columns(), values as %d',
    'includes/services/class-market-service.php: $sql'  => 'literals and placeholders; the item filter checked against tradeable()',
    'includes/league/class-ido-league-table.php: $sql'  => 'literals and placeholders',
    // Old and new column names from a constant rename map in the migration.
    'includes/class-ido-installer.php: $old' => 'keys of the constant $troops rename map',
    'includes/class-ido-installer.php: $new' => 'values of the constant $troops rename map',
];

$methods = ['query', 'get_var', 'get_row', 'get_col', 'get_results'];
$found = [];
$calls = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = str_replace('\\', '/', $file->getPathname());
    $rel  = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
    foreach ($skip as $s) if (strpos('/' . $rel, $s) !== false) continue 2;

    $t = token_get_all(file_get_contents($path));
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($t[$i]) || $t[$i][0] !== T_VARIABLE || $t[$i][1] !== '$wpdb') continue;
        $j = $i + 1;
        if (!is_array($t[$j] ?? null) || $t[$j][0] !== T_OBJECT_OPERATOR) continue;
        $m = $t[$j + 1] ?? null;
        if (!is_array($m) || !in_array($m[1], $methods, true) || ($t[$j + 2] ?? null) !== '(') continue;
        $calls++;

        // The statement text: the first argument, or prepare()'s first argument.
        $k = $j + 3;
        while (is_array($t[$k] ?? null) && $t[$k][0] === T_WHITESPACE) $k++;
        if (is_array($t[$k] ?? null) && $t[$k][1] === '$wpdb'
            && is_array($t[$k + 1] ?? null) && $t[$k + 1][0] === T_OBJECT_OPERATOR
            && is_array($t[$k + 2] ?? null) && $t[$k + 2][1] === 'prepare' && ($t[$k + 3] ?? null) === '(') {
            $k += 4;
        }

        $depth = 0;
        for (; $k < $n; $k++) {
            $tok = $t[$k];
            if ($tok === '(' || $tok === '[') { $depth++; continue; }
            if ($tok === ')' || $tok === ']') { if ($depth === 0) break; $depth--; continue; }
            if ($tok === ',' && $depth === 0) break;
            if (!is_array($tok)) continue;
            if ($tok[0] === T_VARIABLE && $tok[1] !== '$wpdb') {
                $found[$rel . ': ' . $tok[1]][] = $tok[2];
            }
            // "... $x ..." and heredocs: a variable inside a string is still text.
            if ($tok[0] === T_ENCAPSED_AND_WHITESPACE || $tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $found[$rel . ': (interpolated string)'][] = $tok[2];
            }
        }
    }
}

echo "=== every query was found ===\n";
check('the scan saw the plugin\'s queries', $calls > 100, $calls . ' calls');

echo "\n=== nothing unreviewed reaches SQL text ===\n";
foreach ($found as $key => $lines) {
    $ok = isset($reviewed[$key]);
    check(($ok ? 'reviewed  ' : 'NEW       ') . $key, $ok, $ok ? '' : 'line ' . implode(', ', array_unique($lines)));
}

echo "\n=== the reviewed list is not stale ===\n";
foreach (array_keys($reviewed) as $key) {
    check('still present  ' . $key, isset($found[$key]));
}

echo "\n=== the identifiers that are spliced are fixed by the code ===\n";
define('ABSPATH', __DIR__ . '/');
class IDO_Game_Exception extends Exception {}
class IDO_Game { public static function fmt($n): string { return (string) $n; } }
class IDO_Settings { public static function int(string $key): int { return 1; } }
require $root . '/includes/data/class-ido-units.php';
require $root . '/includes/data/class-ido-weapons.php';
require $root . '/includes/data/class-ido-buildings.php';
$columns = [];
foreach (IDO_Units::keys() as $k)     $columns[] = IDO_Units::column($k);
foreach (IDO_Weapons::keys() as $k)   { $columns[] = IDO_Weapons::column($k); $columns[] = IDO_Weapons::progress_column($k); }
foreach (IDO_Buildings::keys() as $k) $columns[] = IDO_Buildings::column($k);
$bad = array_filter($columns, static fn($c) => !preg_match('/^[a-z][a-z0-9_]*$/', $c));
check('every unit, weapon and building column is a plain identifier', $bad === [], implode(', ', $bad));

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
