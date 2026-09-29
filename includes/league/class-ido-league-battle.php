<?php
if (!defined('ABSPATH')) exit;

/**
 * A league battle, fought on the defending site.
 *
 * The defender always computes their own outcome. An arriving packet asserts
 * only what left the other site; what it accomplishes is decided here, against
 * numbers this site holds first hand. That is the rule the whole protocol rests
 * on, and it is why a hostile peer can lie about its own strength all it likes
 * without changing what happens when it arrives.
 *
 * The maths is the local game's. Offence and defence power from the same data
 * classes, the same fortification bonus, the same random swing, the same
 * casualty rates and the same plunder percentages. What differs is the scale:
 * one committed army against a whole site.
 *
 * **Attacking is opting in; being attacked is not.** The attacker's losses fall
 * on the empires that committed, in proportion to what they risked. The
 * defender's fall on every empire on the site, in proportion to what each one
 * holds, because a defence is not a decision anybody made. In league mode the
 * site is the team, and a board where most rulers were untouchable would be a
 * board where the correct play is to let the keen ones carry the risk.
 */
class IDO_League_Battle {

    /** The local raid percentages, applied to the defending site's totals. */
    const PLUNDER = ['gold' => 0.09, 'grain' => 0.07, 'iron' => 0.07];

    /** Casualty rates, as the local game sets them. */
    const ATTACKER_WON_LOSS  = 0.07;
    const ATTACKER_LOST_LOSS = 0.18;
    const DEFENDER_LOST_LOSS = 0.06;
    const DEFENDER_HELD_LOSS = 0.03;

    /**
     * How close counts as too close to call: within five percent either way.
     *
     * Without this a dead-even fight is decided by the random swing, which is a
     * coin flip carrying the full consequences of a rout. One side takes plunder
     * and seven percent casualties, the other takes eighteen and nothing, and the
     * difference between those two outcomes was a dice roll on a day nobody could
     * influence. After a week of waiting for an army to arrive, that reads as the
     * game being arbitrary rather than tense.
     *
     * A draw is the honest answer to two armies that could not break each other.
     * Neither side takes anything, both count their dead, and the army comes home.
     * It costs the attacker the turns, the days and a tenth of the force, which is
     * a real price for picking a fight they could not finish, without being the
     * ruin that losing is.
     *
     * Five percent is narrow on purpose. It should be the genuinely even fight,
     * not a consolation for being close.
     */
    const DRAW_BAND = 0.05;

    /** What an inconclusive battle costs each side. */
    const DRAW_LOSS = 0.10;

    /**
     * A victory has to be worth having.
     *
     * The percentages alone do not guarantee that. Nine percent of a poor site's
     * gold can be worth less than the men it cost to take it, and a game where
     * winning can leave you poorer is a game where the sensible move is never to
     * march at all. The wait, the turns and the risk are already the price; the
     * plunder has to clear them.
     *
     * So a won battle takes at least what the attacker's casualties were worth,
     * plus this margin, valued in the coin the game values everything else in:
     * net worth. Troops at half their training cost, gold at a fiftieth, grain at
     * a two-hundredth, iron at a twentieth. Reusing the game's own measure means
     * "that was worth it" means the same thing here as on the rankings screen.
     */
    const VICTORY_MARGIN = 0.07;

    /**
     * And a ceiling, because the floor needs one.
     *
     * Without it a rich attacker beating a poor site would strip it bare to cover
     * casualties that site could never have inflicted. A quarter of any one
     * resource is the most a single march takes, whatever the arithmetic asks
     * for. A site that cannot cover the margin simply does not: you beat a
     * pauper, and the dispatch says as much.
     */
    const MAX_PLUNDER_SHARE = 0.25;

    /**
     * Fights the battle and applies every consequence to this site.
     *
     * @param array<string,int> $force    what arrived, by unit key
     * @param array<string,int> $weapons  the siege train that arrived
     * @param bool              $agent    whether an agent rode ahead
     * @return array the result, ready to be sent home
     */
    public static function resolve(object $peer, array $force, array $weapons, bool $agent): array {
        $round = IDO_Rounds::current();
        $defenders = self::defenders($round ? (int) $round->id : 0);

        $offence_base = IDO_Units::offence_power($force);
        $ballista_share = $offence_base > 0
            ? IDO_Units::offence_power(array_intersect_key($force, ['ballista_legion' => 1])) / $offence_base
            : 0.0;

        // The agent works the night before, so his result is known before a blow
        // is struck and the walls are what the army meets.
        $agent_result = ['success' => false, 'lost' => false];
        if ($agent) {
            $agent_result = IDO_League_Covert::resolve($offence_base, self::defence_power($defenders));
        }
        $wall_reduction = IDO_League_Covert::wall_reduction($agent_result['success']);

        $offence = $offence_base * IDO_Military::swing();
        $defence = self::defended_power($defenders, $ballista_share, $wall_reduction) * IDO_Military::swing();

        $ratio = $defence > 0 ? $offence / $defence : 2.0;

        $outcome = self::outcome_for($offence, $defence);
        $drawn = $outcome === 'drawn';
        $attacker_won = $outcome === 'won';

        // Weapons first, then plunder, because the plunder tops the haul up to
        // what a victory has to be worth and needs to know what the captured
        // engines already covered.
        $weapons_taken = $drawn ? 0 : self::take_weapons($defenders, $attacker_won, $weapons);

        $plunder = ['gold' => 0, 'grain' => 0, 'iron' => 0];
        if ($attacker_won) {
            $casualties = self::casualties($force, self::ATTACKER_WON_LOSS);
            $owed = self::worth_of_troops($casualties) * (1.0 + self::VICTORY_MARGIN)
                  - self::worth_of_weapons($weapons_taken);
            $plunder = self::take_plunder($defenders, $ratio, max(0.0, $owed));
        }

        $defender_rate = $drawn ? self::DRAW_LOSS
            : ($attacker_won ? self::DEFENDER_LOST_LOSS : self::DEFENDER_HELD_LOSS);
        $defender_dead = self::apply_defender_losses($defenders, $defender_rate);

        foreach ($defenders as $row) IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($row));

        // What the attacker gets told, and what their site will hand out. Their
        // casualties are computed here because the defender is the one who knows
        // how the day went.
        $attacker_rate = $drawn ? self::DRAW_LOSS
            : ($attacker_won ? self::ATTACKER_WON_LOSS : self::ATTACKER_LOST_LOSS);
        $survivors = self::survivors($force, $attacker_rate);

        // A drawn field is not a rout, so the siege train is dragged home rather
        // than abandoned where it stood.
        $train_home = self::survivors($weapons, $drawn ? 0.0 : ($attacker_won ? 0.0 : 0.25));

        return [
            'outcome'        => $drawn ? 'drawn' : ($attacker_won ? 'won' : 'lost'),
            'survivors'      => $survivors,
            'weapons_home'   => $train_home,
            'spoils_gold'    => (int) $plunder['gold'],
            'spoils_grain'   => (int) $plunder['grain'],
            'spoils_iron'    => (int) $plunder['iron'],
            'spoils_weapons' => (int) $weapons_taken,
            'defender_dead'  => (int) $defender_dead,
            'agent'          => !$agent ? 'none'
                                : ($agent_result['success'] ? 'success'
                                    : ($agent_result['lost'] ? 'hanged' : 'failed')),
        ];
    }

    /**
     * Who carried the day: won, lost, or neither.
     *
     * Pulled out of the battle so the rule can be read and tested on its own,
     * because it is the rule most likely to be argued about and the one a player
     * will feel hardest. Everything else in a battle is arithmetic on numbers;
     * this is the line between a triumph and a ruin.
     */
    public static function outcome_for(float $offence, float $defence): string {
        if ($defence <= 0) return $offence > 0 ? 'won' : 'drawn';

        // The epsilon is not decoration. In binary floating point 1050/1000 - 1
        // comes out as 0.050000000000000044, so a fight sitting exactly on a five
        // percent band fell just outside it and was scored a win. The band is
        // documented as inclusive and should behave that way rather than
        // depending on which numbers happen to be representable.
        $ratio = $offence / $defence;
        if (abs($ratio - 1.0) <= self::DRAW_BAND + 1e-9) return 'drawn';

        return $offence > $defence ? 'won' : 'lost';
    }

    /** Every empire standing on this site. */
    public static function defenders(int $round_id): array {
        global $wpdb;
        if (!$round_id) return [];
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_defeated = 0 ORDER BY id ASC',
            $round_id
        ));
    }

    /**
     * The whole site as a single empire, for the parts where that is true.
     *
     * In league mode the site really is one empire: nobody on the board is
     * anybody else's rival, the army is pooled, and a march is fought by
     * everybody. So the natural way to run a battle is to add the site up and
     * hand it to the same rules a local empire uses, which is what this does.
     *
     * **It only works for the linear parts, and that limit is measured rather
     * than assumed.** Troop and siege-weapon defence power are sums, so adding
     * the empires and then computing is identical to computing and then adding.
     * Anything with a cap in it is not: ten empires with twenty fortifications
     * each aggregate to two hundred, which maxes a bonus that tops out at fifty
     * percent, and the site comes out **34% stronger** than the same empires
     * defending as themselves. That is not a rounding difference, it is a free
     * upgrade for being numerous.
     *
     * The rule this leaves behind, and it applies to the league table and the
     * board reset as much as here: **aggregate what adds, compute per empire what
     * caps.**
     */
    public static function site_sheet(array $defenders): object {
        $sheet = ['gold' => 0, 'grain' => 0, 'iron' => 0, 'peasants' => 0, 'land' => 0, 'networth' => 0];
        foreach (IDO_Units::keys() as $key)     $sheet[IDO_Units::column($key)] = 0;
        foreach (IDO_Weapons::keys() as $key) {
            $sheet[IDO_Weapons::column($key)] = 0;
            $sheet[IDO_Weapons::progress_column($key)] = 0;
        }

        foreach ($defenders as $row) {
            foreach ($sheet as $column => $_) {
                $sheet[$column] += max(0, (int) ($row->{$column} ?? 0));
            }
        }
        foreach ($sheet as $column => $value) $sheet[$column] = IDO_Game::clamp($value);

        // Deliberately absent: b_fortification and the other capped buildings.
        // A sheet that carried them would invite exactly the mistake above, and
        // leaving them out means the mistake cannot be made by accident.
        return (object) $sheet;
    }

    /**
     * Raw defensive strength, before walls.
     *
     * One call against the site sheet rather than a loop, because troop and
     * weapon power are linear and this is the local rule applied to the site as a
     * single empire, which is what it is.
     */
    public static function defence_power(array $defenders): float {
        $sheet = self::site_sheet($defenders);
        return IDO_Units::defence_power($sheet) + IDO_Weapons::defence_power($sheet);
    }

    /**
     * Defensive strength with each empire's own walls counted.
     *
     * Per empire, and this is the one place the site cannot be treated as a
     * single empire. The fortification bonus caps at fifty percent, so a sheet
     * summing ten empires of twenty fortifications reaches two hundred and maxes
     * it, and the site defends 34% better than the same empires would have
     * individually. Numerous is not the same as fortified.
     *
     * It is also fairer this way round: walls belong to the ruler who built them,
     * and an average would quietly move that advantage from the empire that paid
     * for it to the neighbour who did not.
     */
    public static function defended_power(array $defenders, float $ballista_share, float $wall_reduction): float {
        $power = 0.0;
        foreach ($defenders as $row) {
            $raw = IDO_Units::defence_power($row) + IDO_Weapons::defence_power($row);
            if ($raw <= 0) continue;

            $bonus = IDO_Buildings::fortification_bonus($row) - 1.0;
            // Ballistae blunt the walls, and so does an agent who got inside.
            $bonus *= (1 - 0.75 * $ballista_share);
            $bonus *= (1 - max(0.0, min(1.0, $wall_reduction)));

            $power += $raw * (1.0 + $bonus);
        }
        return $power;
    }

    /**
     * Takes the plunder from the site, apportioned by share of each resource.
     *
     * By share of the resource rather than by net worth, and that is arithmetic
     * rather than sentiment: net worth would bill a land-rich, cash-poor empire
     * for gold it does not hold, and that shortfall has to go somewhere. By
     * share, a ruler with no gold pays no gold, and the large empires still pay
     * most because they hold most.
     */
    private static function take_plunder(array $defenders, float $ratio, float $owed = 0.0): array {
        $modifier = max(0.6, min(1.4, $ratio));
        $taken = ['gold' => 0, 'grain' => 0, 'iron' => 0];

        $pool = [];
        foreach (self::PLUNDER as $resource => $share) {
            $total = 0;
            foreach ($defenders as $row) $total += max(0, (int) $row->{$resource});
            $pool[$resource] = $total;
        }
        $wanted = self::plunder_wanted($pool, $modifier, $owed);

        foreach (self::PLUNDER as $resource => $share) {
            $held = [];
            foreach ($defenders as $row) $held[(int) $row->id] = max(0, (int) $row->{$resource});
            if ($pool[$resource] <= 0) continue;

            $wanted_here = (int) $wanted[$resource];
            if ($wanted_here <= 0) continue;

            $split = IDO_League_Share::split($wanted_here, $held);
            if (!IDO_League_Share::reconciles($wanted_here, $split)) continue;   // never apply an approximate split

            foreach ($split as $kingdom_id => $amount) {
                if ($amount <= 0) continue;
                $kingdom = IDO_Kingdom::find((int) $kingdom_id);
                if ($kingdom) IDO_Kingdom::pay($kingdom, [$resource => -$amount]);
            }
            $taken[$resource] = $wanted_here;
        }
        return $taken;
    }

    /**
     * How much of each resource a victory takes.
     *
     * Two steps. The percentages decide the baseline, scaled by how decisive the
     * day was. Then, if that baseline is worth less than the victory owes, every
     * resource is scaled up in the same proportion until it clears the bar or
     * hits the ceiling, whichever comes first.
     *
     * In the same proportion rather than draining one resource first, because a
     * site stripped of all its iron and none of its gold is a stranger thing to
     * explain than a site that lost a quarter of everything.
     *
     * Pure, and separated from the writing so the arithmetic can be tested
     * without a database: this is the rule that decides whether marching is worth
     * doing at all.
     *
     * @param array<string,int> $pool what the defending site holds
     * @param float $modifier how decisive the victory was, 0.6 to 1.4
     * @param float $owed the value the victory has to clear
     * @return array<string,int>
     */
    public static function plunder_wanted(array $pool, float $modifier, float $owed = 0.0): array {
        $wanted = [];
        foreach (self::PLUNDER as $resource => $share) {
            $held = max(0, (int) ($pool[$resource] ?? 0));
            $wanted[$resource] = min($held, (int) floor($held * $share * $modifier));
        }

        $value = self::worth_of_plunder($wanted);
        if ($owed <= $value || $value <= 0) return $wanted;

        // Rounded up, then clamped. Flooring three resources after scaling loses
        // a fraction of each and lands the haul just under the very bar it was
        // scaled to clear, which is an odd thing to explain to a player who won:
        // the victory owed 11,460 and paid 11,459. Rounding up costs the defender
        // at most one extra unit of each, and the ceiling still holds.
        $scale = $owed / $value;
        foreach ($wanted as $resource => $amount) {
            $held = max(0, (int) ($pool[$resource] ?? 0));
            $ceiling = (int) floor($held * self::MAX_PLUNDER_SHARE);
            $wanted[$resource] = (int) max(0, min($ceiling, $held, ceil($amount * $scale)));
        }
        return $wanted;
    }

    /**
     * What a force is worth, in the coin the game values everything else in.
     *
     * Half the training cost per soldier, which is exactly what the rankings use,
     * so "this victory was worth it" means the same thing as "my net worth went
     * up".
     */
    public static function worth_of_troops(array $force): float {
        $worth = 0.0;
        foreach ($force as $key => $qty) {
            if (!IDO_Units::exists($key)) continue;
            $unit = IDO_Units::get($key);
            $worth += round(((int) $unit['gold']) / 2) * max(0, (int) $qty);
        }
        return $worth;
    }

    /** The same, for captured siege engines. */
    public static function worth_of_weapons(int $count): float {
        if ($count < 1) return 0.0;
        $keys = IDO_Weapons::keys();
        if (!$keys) return 0.0;
        return round(((int) IDO_Weapons::cost($keys[0], 1)['gold']) / 2) * $count;
    }

    /** And for a haul of plunder, at the weights net worth uses. */
    public static function worth_of_plunder(array $plunder): float {
        return ((int) ($plunder['gold'] ?? 0)) / 50
             + ((int) ($plunder['grain'] ?? 0)) / 200
             + ((int) ($plunder['iron'] ?? 0)) / 20;
    }

    /** What a casualty rate removes from a force. */
    private static function casualties(array $force, float $rate): array {
        $out = [];
        foreach ($force as $key => $qty) {
            $out[$key] = (int) round(max(0, (int) $qty) * $rate);
        }
        return $out;
    }

    /**
     * Siege weapons change hands, using the local game's own shares.
     *
     * A winning attacker takes engines from the defending site; a losing one
     * leaves their own train behind. Either way the count is drawn from what was
     * actually standing, and captured weapons arrive as hardware: they need crews
     * the winner has yet to pay for, which is the brake that stops a haul of
     * engines compounding into a runaway lead.
     */
    private static function take_weapons(array $defenders, bool $attacker_won, array $attacker_train): int {
        if (!$attacker_won) return 0;

        $held = [];
        $total = 0;
        foreach ($defenders as $row) {
            $count = 0;
            foreach (IDO_Weapons::keys() as $key) $count += (int) $row->{IDO_Weapons::column($key)};
            $held[(int) $row->id] = $count;
            $total += $count;
        }
        if ($total <= 0) return 0;

        $spoils = IDO_Military::weapon_spoils($total);
        $losing = (int) $spoils['taken'] + (int) $spoils['wrecked'];
        if ($losing <= 0) return 0;

        $split = IDO_League_Share::split($losing, $held);
        if (!IDO_League_Share::reconciles($losing, $split)) return 0;

        foreach ($split as $kingdom_id => $lose) {
            if ($lose <= 0) continue;
            $kingdom = IDO_Kingdom::find((int) $kingdom_id);
            if (!$kingdom) continue;

            // Take them off the biggest stack first, so a mixed yard loses
            // something rather than failing because one type ran out.
            foreach (IDO_Weapons::keys() as $key) {
                if ($lose <= 0) break;
                $have = (int) $kingdom->{IDO_Weapons::column($key)};
                $take = min($have, $lose);
                if ($take > 0) {
                    IDO_Kingdom::pay($kingdom, [IDO_Weapons::column($key) => -$take]);
                    $lose -= $take;
                }
            }
        }
        return (int) $spoils['taken'];
    }

    /** Casualties across the whole defending site, by share of the troops that stood. */
    private static function apply_defender_losses(array $defenders, float $rate): int {
        $dead = 0;
        foreach (IDO_Units::keys() as $key) {
            $column = IDO_Units::column($key);

            $held = [];
            $total = 0;
            foreach ($defenders as $row) {
                $have = max(0, (int) $row->{$column});
                $held[(int) $row->id] = $have;
                $total += $have;
            }
            if ($total <= 0) continue;

            $losing = (int) floor($total * $rate);
            if ($losing <= 0) continue;

            $split = IDO_League_Share::split($losing, $held);
            if (!IDO_League_Share::reconciles($losing, $split)) continue;

            foreach ($split as $kingdom_id => $lost) {
                if ($lost <= 0) continue;
                $kingdom = IDO_Kingdom::find((int) $kingdom_id);
                if ($kingdom) IDO_Kingdom::pay($kingdom, [$column => -$lost]);
            }
            $dead += $losing;
        }
        return $dead;
    }

    /** What is left of a force after a casualty rate. */
    private static function survivors(array $force, float $rate): array {
        $out = [];
        foreach ($force as $key => $qty) {
            $qty = max(0, (int) $qty);
            $lost = (int) round($qty * $rate);
            $out[$key] = max(0, $qty - $lost);
        }
        return $out;
    }
}
