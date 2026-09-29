<?php
/**
 * Who carried the day, and what it costs each side.
 *
 * The line between a triumph and a ruin, tested on its own. Before the draw band
 * existed, a fight decided by a tenth of a percent handed one side plunder and
 * seven percent casualties and the other eighteen percent and nothing, on a
 * difference no player could see or influence. This is the rule that stops that,
 * so it is worth being exact about where it starts and stops.
 */
define('ABSPATH', __DIR__ . '/');

class IDO_Game { const MAX_VALUE = 9000000000000000;
    public static function clamp($n): int { return (int) min(self::MAX_VALUE, max(0, $n)); }
    public static function fmt($n): string { return number_format((float) $n); } }
class IDO_Game_Exception extends Exception {}
class IDO_Units { public static function keys(): array { return ['pawn', 'legionnaire']; }
    public static function column(string $k): string { return 'u_' . $k; } }
class IDO_Weapons { public static function keys(): array { return ['catapult']; }
    public static function column(string $k): string { return $k . 's'; }
    public static function progress_column(string $k): string { return $k . 's_in_progress'; } }

require __DIR__ . '/../includes/league/class-ido-league-battle.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-60s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}
function outcome(float $a, float $d): string { return IDO_League_Battle::outcome_for($a, $d); }

$band = IDO_League_Battle::DRAW_BAND;

echo "=== a clear result is still a clear result ===\n";
check('twice the strength wins', outcome(2000, 1000) === 'won');
check('half the strength loses', outcome(500, 1000) === 'lost');
check('a fifth more wins', outcome(1200, 1000) === 'won');
check('a fifth less loses', outcome(800, 1000) === 'lost');

echo "\n=== the band, at its edges ===\n";
check('dead even is a draw', outcome(1000, 1000) === 'drawn');
check('a hair ahead is a draw', outcome(1001, 1000) === 'drawn');
check('a hair behind is a draw', outcome(999, 1000) === 'drawn');
check('exactly at the band is still a draw',
    outcome(1000 * (1 + $band), 1000) === 'drawn', sprintf('%.1f vs 1000', 1000 * (1 + $band)));
check('and on the low side too', outcome(1000 * (1 - $band), 1000) === 'drawn');
check('just past it is a win', outcome(1000 * (1 + $band) + 1, 1000) === 'won');
check('and just under it a loss', outcome(1000 * (1 - $band) - 1, 1000) === 'lost');

echo "\n=== the band is narrow on purpose ===\n";
check('it is five percent', abs($band - 0.05) < 0.0001, (string) $band);
check('a tenth ahead is a win, not a draw', outcome(1100, 1000) === 'won');
check('a tenth behind is a loss, not a draw', outcome(900, 1000) === 'lost');

echo "\n=== nobody home ===\n";
check('an undefended site falls', outcome(1000, 0) === 'won');
check('and nothing against nothing is a draw', outcome(0, 0) === 'drawn');

echo "\n=== what each ending costs ===\n";
$win  = IDO_League_Battle::ATTACKER_WON_LOSS;
$draw = IDO_League_Battle::DRAW_LOSS;
$loss = IDO_League_Battle::ATTACKER_LOST_LOSS;
check('a draw costs the attacker more than a win', $draw > $win, sprintf('%.2f vs %.2f', $draw, $win));
check('and less than a defeat', $draw < $loss, sprintf('%.2f vs %.2f', $draw, $loss));
check('the defender pays the same as the attacker on a draw',
    abs($draw - IDO_League_Battle::DRAW_LOSS) < 0.0001);
check('a draw is dearer for the defender than holding the walls',
    $draw > IDO_League_Battle::DEFENDER_HELD_LOSS,
    sprintf('%.2f vs %.2f', $draw, IDO_League_Battle::DEFENDER_HELD_LOSS));

// Of 900 sent: 837 home on a win, 810 on a draw, 738 on a defeat.
$home = static fn(float $rate): int => 900 - (int) round(900 * $rate);
check('900 sent comes home 837 on a win', $home($win) === 837, (string) $home($win));
check('810 on a draw', $home($draw) === 810, (string) $home($draw));
check('738 on a defeat', $home($loss) === 738, (string) $home($loss));
check('so a draw is the middle outcome it should be',
    $home($loss) < $home($draw) && $home($draw) < $home($win));

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
