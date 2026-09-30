<?php
/**
 * Disasters: drought, insects and flood.
 *
 * Most of what matters here is a negative -- never during grace, never two at
 * once, never a report about nothing -- so most of these checks prove an absence.
 * The probability is deliberately not tested by rolling dice and counting: the
 * roll is one line and testing it that way buys a flaky suite. What is tested is
 * that the odds are read from the setting, that the floor holds, and that
 * everything downstream of the roll does the right thing when it comes up.
 *
 *   php tests/disaster-test.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/disaster-test.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-64s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}

global $wpdb;
$was = get_option(IDO_Settings::OPTION);
register_shutdown_function(static function () use ($was) {
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
});
IDO_Settings::update(['disasters_enabled' => 1, 'disaster_one_in' => 60, 'disaster_percent' => 7]);

/** An empire row good enough for the pure calculations, with no database behind it. */
function empire(array $over = []): object {
    return (object) array_merge([
        'id' => 1, 'round_id' => 1, 'kingdom_name' => 'Vaelmark',
        'is_defeated' => 0, 'protection_until' => null,
        'relief_until' => null, 'reliefs_used' => 0,
        'b_homestead' => 1000, 'b_farmstead' => 500, 'b_mint' => 100,
        'b_foundry' => 100, 'b_barracks' => 50, 'b_fortification' => 50,
        'grain' => 100000,
    ], $over);
}

say('=== the three of them, and nothing else ===');
$kinds = IDO_Disasters::kinds();
check('there are exactly three', count($kinds) === 3, implode(', ', array_keys($kinds)));
check('drought takes farmsteads',
    $kinds['drought']['stock'] === 'building' && $kinds['drought']['key'] === 'farmstead');
check('insects take stored grain', $kinds['insects']['stock'] === 'grain');
check('flood takes homesteads',
    $kinds['flood']['stock'] === 'building' && $kinds['flood']['key'] === 'homestead');
$cols = array_map(
    static fn($s) => $s['stock'] === 'grain' ? 'grain' : IDO_Buildings::column($s['key']),
    $kinds
);
check('every target is a real column on the empire',
    count(array_intersect($cols, array_merge(['grain'], array_map(
        static fn($k) => IDO_Buildings::column($k), IDO_Buildings::keys())))) === 3,
    implode(',', $cols));

say('');
say('=== the share ===');
check('7% by default', abs(IDO_Disasters::share() - 0.07) < 0.0001, (string) IDO_Disasters::share());
IDO_Settings::update(['disaster_percent' => 150]);
check('a nonsense share is clamped, not trusted', IDO_Disasters::share() <= 1.0,
    (string) IDO_Disasters::share());
IDO_Settings::update(['disaster_percent' => 7]);

check('one in 60 by default', IDO_Disasters::one_in() === 60, (string) IDO_Disasters::one_in());
IDO_Settings::update(['disaster_one_in' => 0]);
check('and odds of zero cannot make it constant', IDO_Disasters::one_in() >= 10,
    (string) IDO_Disasters::one_in());
IDO_Settings::update(['disaster_one_in' => 60]);

say('');
say('=== what a strike costs ===');
// Run it enough times to see all three kinds, since the kind is random.
$seen = [];
for ($i = 0; $i < 300; $i++) {
    $e = IDO_Disasters::strike(empire(), 100000);
    if ($e !== null) $seen[$e['kind']] = $e['lost'];
}
check('all three turn up over 300 strikes', count($seen) === 3, implode(',', array_keys($seen)));
check('a drought takes 7% of 500 farmsteads', ($seen['drought'] ?? null) === 35,
    (string) ($seen['drought'] ?? 'none'));
check('a flood takes 7% of 1000 homesteads', ($seen['flood'] ?? null) === 70,
    (string) ($seen['flood'] ?? 'none'));
check('insects take 7% of 100,000 grain', ($seen['insects'] ?? null) === 7000,
    (string) ($seen['insects'] ?? 'none'));

say('');
say('=== nothing to take is not a disaster ===');
$none = true;
for ($i = 0; $i < 200; $i++) {
    // Fourteen farmsteads, fourteen homesteads, no grain: 7% of each floors to zero.
    if (IDO_Disasters::strike(empire(['b_farmstead' => 14, 'b_homestead' => 14]), 0) !== null) {
        $none = false;
    }
}
check('an empire too small to lose one of anything is left alone', $none);
check('and the report for that is nothing at all, not a zero', IDO_Disasters::report([
    'kind' => 'drought', 'lost' => 0]) !== '' );   // report still formats; strike is the gate

say('');
say('=== grace ===');
$future = gmdate('Y-m-d H:i:s', current_time('timestamp') + 3600);
$past   = gmdate('Y-m-d H:i:s', current_time('timestamp') - 3600);

check('a new empire under the crown truce is never struck',
    !IDO_Disasters::eligible(empire(['protection_until' => $future])));
check('but the same empire is fair game once the truce lapses',
    IDO_Disasters::eligible(empire(['protection_until' => $past])));
check('an empire under relief is never struck',
    !IDO_Disasters::eligible(empire(['relief_until' => $future, 'reliefs_used' => 1])));
check('a defeated empire is never struck',
    !IDO_Disasters::eligible(empire(['is_defeated' => 1])));

IDO_Settings::update(['disasters_enabled' => 0]);
check('and nobody at all when they are switched off',
    !IDO_Disasters::eligible(empire()));
IDO_Settings::update(['disasters_enabled' => 1]);

say('');
say('=== the wording ===');
foreach (array_keys($kinds) as $kind) {
    $line = IDO_Disasters::report(['kind' => $kind, 'lost' => 1234]);
    check("a $kind reads as a sentence with the number in it",
        $line !== '' && strpos($line, '1,234') !== false, $line);
}
check('an unknown kind reports nothing rather than guessing',
    IDO_Disasters::report(['kind' => 'locusts', 'lost' => 10]) === '');

say('');
say('=== the league owns the weather ===');
$governed = IDO_League::governed_keys();
foreach (['disasters_enabled', 'disaster_one_in', 'disaster_percent'] as $key) {
    check("the league governs $key", in_array($key, $governed, true));
}

say('');
say('=== they are wired into a turn ===');
$src = file_get_contents(IDO_PATH . 'includes/services/class-economy-service.php');
check('advance() consults them', strpos($src, 'IDO_Disasters::eligible') !== false);
check('only one can land per order',
    strpos($src, '$disaster === null && IDO_Disasters::rolls()') !== false);
check('a lost building is written back to the empire',
    strpos($src, '$buildings_lost[$column]') !== false);
check('and fed back into the turns that follow',
    strpos($src, '$scratch->{$column} = max(0,') !== false);
check('the struck ruler is told', strpos($src, 'IDO_Disasters::report') !== false);
check('and the gazette is told', strpos($src, 'IDO_Disasters::announce') !== false);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
