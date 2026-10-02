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
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', __DIR__ . '/');

class IDO_Game { const MAX_VALUE = 9000000000000000;
    public static function clamp($n): int { return (int) min(self::MAX_VALUE, max(0, $n)); }
    public static function fmt($n): string { return number_format((float) $n); } }
class IDO_Game_Exception extends Exception {}
// The real data classes, because the victory floor values troops the way the
// rankings do and a stub would let the two drift apart.
class IDO_Settings { public static function int(string $k): int { return 0; } }
class IDO_Buildings { const MAX_BARRACKS_DISCOUNT = 0.35;
    public static function barracks_discount(object $k): float { return 0.0; } }
require __DIR__ . '/../includes/data/class-ido-units.php';
require __DIR__ . '/../includes/data/class-ido-weapons.php';

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

echo "\n=== a victory has to be worth having ===\n";
// Sending 900 legionnaires and winning costs 63 of them. The plunder has to clear
// what they were worth, plus the margin, or marching is a bad bet and the
// sensible play is never to march at all.
$lost = ['legionnaire' => (int) round(900 * IDO_League_Battle::ATTACKER_WON_LOSS)];
$cost = IDO_League_Battle::worth_of_troops($lost);
$owed = $cost * (1 + IDO_League_Battle::VICTORY_MARGIN);
check('the dead are valued as the rankings value them',
    abs($cost - 63 * round(IDO_Units::get('legionnaire')['gold'] / 2)) < 0.001, (string) $cost);
check('and the victory owes that plus the margin', $owed > $cost,
    sprintf('%.0f vs %.0f', $owed, $cost));

$rich     = ['gold' => 25000000, 'grain' => 5000000, 'iron' => 2000000];
$middling = ['gold' => 3000000,  'grain' => 600000,  'iron' => 300000];
$poor     = ['gold' => 200000,   'grain' => 50000,   'iron' => 20000];

$from_rich = IDO_League_Battle::plunder_wanted($rich, 1.0, $owed);
check('a rich site already pays more than it owes',
    IDO_League_Battle::worth_of_plunder($from_rich) >= $owed,
    number_format(IDO_League_Battle::worth_of_plunder($from_rich)));
check('so the percentages are left alone',
    $from_rich === IDO_League_Battle::plunder_wanted($rich, 1.0, 0.0));

$base_middling = IDO_League_Battle::plunder_wanted($middling, 1.0, 0.0);
$from_middling = IDO_League_Battle::plunder_wanted($middling, 1.0, $owed);
check('a middling site would not have covered it on the percentages alone',
    IDO_League_Battle::worth_of_plunder($base_middling) < $owed,
    number_format(IDO_League_Battle::worth_of_plunder($base_middling)));
check('so the haul is topped up until it does',
    IDO_League_Battle::worth_of_plunder($from_middling) >= $owed,
    number_format(IDO_League_Battle::worth_of_plunder($from_middling)));
check('and every resource grew, not just the cheapest',
    $from_middling['gold'] > $base_middling['gold']
    && $from_middling['grain'] > $base_middling['grain']
    && $from_middling['iron'] > $base_middling['iron']);

$from_poor = IDO_League_Battle::plunder_wanted($poor, 1.0, $owed);
check('a poor site cannot cover it and is not stripped trying',
    IDO_League_Battle::worth_of_plunder($from_poor) < $owed);
foreach (['gold', 'grain', 'iron'] as $resource) {
    check("  and never loses more than a quarter of its $resource",
        $from_poor[$resource] <= (int) floor($poor[$resource] * IDO_League_Battle::MAX_PLUNDER_SHARE),
        $from_poor[$resource] . ' of ' . $poor[$resource]);
}

echo "\n=== the ceiling holds whatever is asked of it ===\n";
$absurd = IDO_League_Battle::plunder_wanted($middling, 1.4, 1000000000.0);
foreach (['gold', 'grain', 'iron'] as $resource) {
    check("a vast debt still takes only a quarter of the $resource",
        $absurd[$resource] === (int) floor($middling[$resource] * IDO_League_Battle::MAX_PLUNDER_SHARE),
        $absurd[$resource] . ' of ' . $middling[$resource]);
}
check('an empty site yields nothing rather than dividing by zero',
    IDO_League_Battle::plunder_wanted(['gold' => 0, 'grain' => 0, 'iron' => 0], 1.0, $owed)
    === ['gold' => 0, 'grain' => 0, 'iron' => 0]);
check('and a battle that owes nothing takes the plain percentages',
    IDO_League_Battle::plunder_wanted($middling, 1.0, 0.0) === $base_middling);

echo "\n=== a decisive win still takes more than a narrow one ===\n";
check('the modifier has not been flattened by the floor',
    IDO_League_Battle::plunder_wanted($rich, 1.4, 0.0)['gold']
    > IDO_League_Battle::plunder_wanted($rich, 0.6, 0.0)['gold']);

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
