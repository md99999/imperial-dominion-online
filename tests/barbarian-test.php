<?php
/**
 * Barbarians visit the leaders, and only on a board with a field to lead.
 *
 * The arithmetic is the easy part. What matters is who qualifies, so most of
 * this is about the two gates: standing, and the size of the board.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', __DIR__ . '/');

function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function wp_rand($min = 0, $max = 1) { return $GLOBALS['ido_forced_roll'] ?? random_int($min, $max); }

$GLOBALS['ido_settings'] = [
    'barbarians_enabled'       => 1,
    'barbarian_min_players'    => 10,
    'barbarian_top_ranks'      => 3,
    'barbarian_chance_percent' => 5,
    'barbarian_gold_percent'   => 10,
    'barbarian_grain_percent'  => 10,
];

class IDO_Settings {
    public static function int(string $key): int { return (int) ($GLOBALS['ido_settings'][$key] ?? 0); }
}
class IDO_Game {
    public static function fmt($n): string { return number_format((float) $n); }
}
class IDO_Log {
    public static function news(string $type, string $message, int $round = 0): void {
        $GLOBALS['ido_news'][] = $message;
    }
}
class IDO_Rankings {
    public static function kingdom_count(int $round_id): int { return $GLOBALS['ido_player_count']; }
    public static function position(object $k): int { return $GLOBALS['ido_position']; }
}

require __DIR__ . '/../includes/services/class-barbarian-service.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-58s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

$empire = (object) ['round_id' => 1, 'is_defeated' => 0, 'kingdom_name' => 'Vaelmark',
                    'gold' => 100000, 'grain' => 50000];

echo "=== who they visit ===\n";
$GLOBALS['ido_player_count'] = 12;
foreach ([1, 2, 3] as $rank) {
    $GLOBALS['ido_position'] = $rank;
    check("rank $rank on a 12-empire board is raidable", IDO_Barbarians::eligible($empire));
}
foreach ([4, 7, 12] as $rank) {
    $GLOBALS['ido_position'] = $rank;
    check("rank $rank is left alone", !IDO_Barbarians::eligible($empire));
}

echo "\n=== the small-board gate ===\n";
$GLOBALS['ido_position'] = 1;
foreach ([9, 5, 2] as $count) {
    $GLOBALS['ido_player_count'] = $count;
    check("the leader of a $count-empire board is left alone", !IDO_Barbarians::eligible($empire));
}
$GLOBALS['ido_player_count'] = 10;
check('exactly ten empires is enough', IDO_Barbarians::eligible($empire));

echo "\n=== switched off entirely ===\n";
$GLOBALS['ido_settings']['barbarians_enabled'] = 0;
check('nobody is raided when disabled', !IDO_Barbarians::eligible($empire));
$GLOBALS['ido_settings']['barbarians_enabled'] = 1;

echo "\n=== a defeated empire is not worth robbing ===\n";
$ruined = (object) ['round_id' => 1, 'is_defeated' => 1, 'kingdom_name' => 'Ruin', 'gold' => 0, 'grain' => 0];
check('an empire awaiting relief is skipped', !IDO_Barbarians::eligible($ruined));

echo "\n=== what they take ===\n";
$taken = IDO_Barbarians::take(100000, 50000);
check('a tenth of the gold', $taken['gold'] === 10000, (string) $taken['gold']);
check('a tenth of the grain', $taken['grain'] === 5000, (string) $taken['grain']);

$empty = IDO_Barbarians::take(0, 0);
check('an empty treasury yields nothing', $empty['gold'] === 0 && $empty['grain'] === 0);

$small = IDO_Barbarians::take(5, 5);
check('a share never rounds up into more than there is', $small['gold'] === 0 && $small['grain'] === 0);

$huge = IDO_Barbarians::take(PHP_INT_MAX, PHP_INT_MAX);
check('an absurd treasury still yields a positive number', $huge['gold'] > 0 && $huge['grain'] > 0);
check('and never more than was there', $huge['gold'] <= PHP_INT_MAX && $huge['grain'] <= PHP_INT_MAX);

echo "\n=== the roll ===\n";
$GLOBALS['ido_forced_roll'] = 1;
check('a roll of 1 against a 5% chance is a raid', IDO_Barbarians::rolls());
$GLOBALS['ido_forced_roll'] = 6;
check('a roll of 6 against a 5% chance is not', !IDO_Barbarians::rolls());
$GLOBALS['ido_settings']['barbarian_chance_percent'] = 0;
$GLOBALS['ido_forced_roll'] = 1;
check('a chance of zero never raids', !IDO_Barbarians::rolls());
unset($GLOBALS['ido_forced_roll']);

echo "\n=== what the ruler is told ===\n";
$line = IDO_Barbarians::report(['gold' => 12480, 'grain' => 3100], 1);
check('the report names both amounts', strpos($line, '12,480') !== false && strpos($line, '3,100') !== false);
$many = IDO_Barbarians::report(['gold' => 100, 'grain' => 100], 3);
check('repeated raids are reported as one line', strpos($many, '3 times') !== false, $many);

echo "\n=== the gazette ===\n";
$GLOBALS['ido_news'] = [];
IDO_Barbarians::announce($empire, ['gold' => 500, 'grain' => 250]);
check('the board hears about it', count($GLOBALS['ido_news']) === 1
    && strpos($GLOBALS['ido_news'][0], 'Vaelmark') !== false);

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
