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

        $attacker_won = $offence > $defence;
        $ratio = $defence > 0 ? $offence / $defence : 2.0;

        $plunder = $attacker_won ? self::take_plunder($defenders, $ratio) : ['gold' => 0, 'grain' => 0, 'iron' => 0];
        $weapons_taken = self::take_weapons($defenders, $attacker_won, $weapons);
        $defender_dead = self::apply_defender_losses($defenders,
            $attacker_won ? self::DEFENDER_LOST_LOSS : self::DEFENDER_HELD_LOSS);

        foreach ($defenders as $row) IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($row));

        // What the attacker gets told, and what their site will hand out. Their
        // casualties are computed here because the defender is the one who knows
        // whether the field was carried.
        $survivors = self::survivors($force, $attacker_won ? self::ATTACKER_WON_LOSS : self::ATTACKER_LOST_LOSS);
        $train_home = self::survivors($weapons, $attacker_won ? 0.0 : 0.25);

        return [
            'outcome'        => $attacker_won ? 'won' : 'lost',
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
    private static function take_plunder(array $defenders, float $ratio): array {
        $modifier = max(0.6, min(1.4, $ratio));
        $taken = ['gold' => 0, 'grain' => 0, 'iron' => 0];

        foreach (self::PLUNDER as $resource => $share) {
            $held = [];
            $total = 0;
            foreach ($defenders as $row) {
                $have = max(0, (int) $row->{$resource});
                $held[(int) $row->id] = $have;
                $total += $have;
            }
            if ($total <= 0) continue;

            $wanted = (int) floor($total * $share * $modifier);
            $wanted = min($wanted, $total);
            if ($wanted <= 0) continue;

            $split = IDO_League_Share::split($wanted, $held);
            if (!IDO_League_Share::reconciles($wanted, $split)) continue;   // never apply an approximate split

            foreach ($split as $kingdom_id => $amount) {
                if ($amount <= 0) continue;
                $kingdom = IDO_Kingdom::find((int) $kingdom_id);
                if ($kingdom) IDO_Kingdom::pay($kingdom, [$resource => -$amount]);
            }
            $taken[$resource] = $wanted;
        }
        return $taken;
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
