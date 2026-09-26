<?php
if (!defined('ABSPATH')) exit;

/**
 * Covert work. An agent is the most expensive thing an empire can own, no ruler
 * may keep more than the crown allows (one, by default), and every mission
 * risks losing them for good. That is deliberate: intelligence should be a
 * considered investment, not a habit.
 */
class IDO_Covert {

    public static function ops(): array {
        return [
            'recon' => [
                'label'  => 'Reconnaissance',
                'note'   => 'Count a rival army, stores and buildings before you commit to a march.',
                'gold'   => 15000,
                'chance' => 85,
                'risk'   => 8,
            ],
            'burn_granary' => [
                'label'  => 'Burn the granaries',
                'note'   => 'Puts a torch to a share of a rival grain store. A starving empire loses troops on its own.',
                'gold'   => 45000,
                'chance' => 65,
                'risk'   => 25,
            ],
            'sabotage_forge' => [
                'label'  => 'Sabotage the forges',
                'note'   => 'Wrecks foundry stock, slowing construction and training.',
                'gold'   => 45000,
                'chance' => 65,
                'risk'   => 25,
            ],
            'incite_revolt' => [
                'label'  => 'Incite revolt',
                'note'   => 'Turns peasants against their lord. The tax base falls with them.',
                'gold'   => 70000,
                'chance' => 55,
                'risk'   => 35,
            ],
        ];
    }

    public static function op(string $key): array {
        $ops = self::ops();
        if (!isset($ops[$key])) {
            throw new IDO_Game_Exception('No such mission.');
        }
        return $ops[$key];
    }

    public static function agent_cost(): array {
        return ['gold' => IDO_Settings::int('agent_gold_cost')];
    }

    /** Hires an agent. Costs a fortune in gold, and one turn. */
    public static function hire(object $kingdom): array {
        $max = max(1, IDO_Settings::int('max_agents'));
        if ((int) $kingdom->agents >= $max) {
            throw new IDO_Game_Exception(sprintf(
                'No ruler may keep more than %d %s in their service.',
                $max, $max === 1 ? 'agent' : 'agents'
            ));
        }
        $cost = self::agent_cost();
        if ((int) $kingdom->gold < $cost['gold']) {
            throw new IDO_Game_Exception(sprintf(
                'An agent asks %s gold and you have %s.',
                IDO_Game::fmt($cost['gold']), IDO_Game::fmt($kingdom->gold)
            ));
        }

        $messages = IDO_Kingdom::spend_turns($kingdom, 1);
        $kingdom = IDO_Kingdom::reload($kingdom);
        IDO_Kingdom::pay($kingdom, [
            'gold'       => -$cost['gold'],
            'agents'     => 1,
        ], 'Your treasury cannot meet the price.');
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        $messages[] = sprintf(
            'A nameless agent enters your service for %s gold. Spend them carefully: you may keep only %d.',
            IDO_Game::fmt($cost['gold']), $max
        );
        return $messages;
    }

    /**
     * Sends the agent against another empire. Failure often costs the agent,
     * and a lost agent means paying the full hiring price again.
     *
     * @return array flash lines
     */
    public static function run(object $kingdom, int $target_id, string $op_key): array {
        global $wpdb;

        $op = self::op($op_key);
        if ((int) $kingdom->agents < 1) {
            throw new IDO_Game_Exception('You keep no agent. Hire one before ordering a mission.');
        }
        if ($target_id === (int) $kingdom->id) {
            throw new IDO_Game_Exception('Your agent will not spy on your own court.');
        }

        $target = IDO_Kingdom::find($target_id);
        if (!$target || (int) $target->round_id !== (int) $kingdom->round_id || (int) $target->is_defeated === 1) {
            throw new IDO_Game_Exception('No such empire stands in this round.');
        }
        if (IDO_Kingdom::is_protected($target)) {
            throw new IDO_Game_Exception(sprintf('%s is still under the crown truce.', $target->kingdom_name));
        }
        if ((int) $kingdom->gold < (int) $op['gold']) {
            throw new IDO_Game_Exception(sprintf(
                'That mission costs %s gold in bribes and you have %s.',
                IDO_Game::fmt($op['gold']), IDO_Game::fmt($kingdom->gold)
            ));
        }

        $turn_cost = max(1, IDO_Settings::int('op_turn_cost'));
        $messages = IDO_Kingdom::spend_turns($kingdom, $turn_cost);
        $kingdom = IDO_Kingdom::reload($kingdom);
        IDO_Kingdom::pay($kingdom, ['gold' => -(int) $op['gold']], 'Your treasury cannot cover the bribes.');
        $kingdom = IDO_Kingdom::reload($kingdom);

        // Larger empires are harder to move against, smaller ones easier.
        $size_ratio = max(0.5, min(2.0, ((int) $kingdom->networth + 1) / max(1, (int) $target->networth)));
        $chance = (int) round(min(95, max(10, $op['chance'] * (0.75 + 0.25 * $size_ratio))));
        $success = wp_rand(1, 100) <= $chance;

        $agent_lost = false;
        $actor_report = [];
        $target_report = [];

        if ($success) {
            switch ($op_key) {
                case 'recon':
                    $actor_report = self::recon_report($target);
                    $target_report[] = sprintf('A stranger was seen counting your granaries. %s knows your strength.', $kingdom->kingdom_name);
                    break;
                case 'burn_granary':
                    $burned = (int) round((int) $target->grain * 0.15);
                    $burned = min($burned, (int) $target->grain);
                    if ($burned > 0) IDO_Kingdom::pay($target, ['grain' => -$burned]);
                    $actor_report[] = sprintf('Fire takes %s grain from the stores of %s.', IDO_Game::fmt($burned), $target->kingdom_name);
                    $target_report[] = sprintf('Your granaries burned in the night. %s grain is lost.', IDO_Game::fmt($burned));
                    break;
                case 'sabotage_forge':
                    $wrecked = (int) round((int) $target->iron * 0.18);
                    $wrecked = min($wrecked, (int) $target->iron);
                    if ($wrecked > 0) IDO_Kingdom::pay($target, ['iron' => -$wrecked]);
                    $actor_report[] = sprintf('The forges of %s are wrecked and %s iron with them.', $target->kingdom_name, IDO_Game::fmt($wrecked));
                    $target_report[] = sprintf('Someone got into your foundries. %s iron is ruined.', IDO_Game::fmt($wrecked));
                    break;
                case 'incite_revolt':
                    $fled = (int) round((int) $target->peasants * 0.10);
                    $fled = min($fled, (int) $target->peasants);
                    if ($fled > 0) IDO_Kingdom::pay($target, ['peasants' => -$fled]);
                    $actor_report[] = sprintf('Your coin sets the villages of %s against their lord. %s peasants scatter.', $target->kingdom_name, IDO_Game::fmt($fled));
                    $target_report[] = sprintf('Agitators emptied your villages. %s peasants have fled.', IDO_Game::fmt($fled));
                    break;
            }
        } else {
            $agent_lost = wp_rand(1, 100) <= (int) $op['risk'];
            $actor_report[] = $agent_lost
                ? sprintf('The mission against %s failed and your agent was taken. They will not be seen again.', $target->kingdom_name)
                : sprintf('The mission against %s failed. Your agent slipped away unrecognised.', $target->kingdom_name);
            $target_report[] = $agent_lost
                ? sprintf('Your guards caught an agent of %s and hanged them at the gate.', $kingdom->kingdom_name)
                : sprintf('An intruder was driven off your grounds. %s is watching you.', $kingdom->kingdom_name);
        }

        if ($agent_lost) {
            IDO_Kingdom::pay($kingdom, ['agents' => -1], 'Your agent is already gone.');
        }
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($target));

        $wpdb->insert(IDO_DB::t('ops'), [
            'round_id'        => (int) $kingdom->round_id,
            'actor_kingdom_id'  => (int) $kingdom->id,
            'target_kingdom_id' => (int) $target->id,
            'op_type'         => $op_key,
            'outcome'         => $success ? 'success' : 'failure',
            'agent_lost'      => $agent_lost ? 1 : 0,
            'actor_report'    => implode("\n", $actor_report),
            'target_report'   => implode("\n", $target_report),
            'created_at'      => IDO_Game::now(),
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']);

        if ($op_key !== 'recon' && $success) {
            IDO_Log::news('covert', sprintf('Word spreads of foul play in %s.', $target->kingdom_name));
        }

        $messages[] = [$success ? 'success' : ($agent_lost ? 'error' : 'warning'), implode("\n", $actor_report)];
        return $messages;
    }

    /** The intelligence a successful reconnaissance brings home. */
    private static function recon_report(object $target): array {
        $lines = [sprintf('Report on %s, seat of %s:', $target->kingdom_name, $target->ruler_name)];
        $lines[] = sprintf('Land %s acres, net worth %s.', IDO_Game::fmt($target->land), IDO_Game::fmt($target->networth));
        $lines[] = sprintf('Stores: %s gold, %s grain and %s iron.',
            IDO_Game::fmt($target->gold), IDO_Game::fmt($target->grain),
            IDO_Game::fmt($target->iron));
        $army = [];
        foreach (IDO_Units::keys() as $key) {
            $army[] = sprintf('%s %s', IDO_Game::fmt($target->{IDO_Units::column($key)}), strtolower(IDO_Units::plural($key)));
        }
        $lines[] = 'Under arms: ' . implode(', ', $army) . '.';

        $manned = IDO_Weapons::crewed($target);
        $yards = [];
        foreach (IDO_Weapons::keys() as $key) {
            $standing = (int) $target->{IDO_Weapons::column($key)};
            if ($standing < 1) continue;
            $worked = (int) ($manned[$key] ?? 0);
            $yards[] = $worked < $standing
                ? sprintf('%s %s, only %s of them manned',
                    IDO_Game::fmt($standing), strtolower(IDO_Weapons::plural($key)), IDO_Game::fmt($worked))
                : sprintf('%s %s, all manned',
                    IDO_Game::fmt($standing), strtolower(IDO_Weapons::plural($key)));
        }
        $lines[] = $yards
            ? 'Siege weapons: ' . implode('; ', $yards) . '.'
            : 'Siege weapons: none standing.';

        $lines[] = sprintf('Defence rating %s, with %s fortifications standing.',
            IDO_Game::fmt(round(IDO_Military::defence_power($target))), IDO_Game::fmt($target->b_fortification));
        return $lines;
    }

    /** Missions run by or against an empire, newest first. */
    public static function history(object $kingdom, int $limit = 25): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT o.*, a.kingdom_name AS actor_name, t.kingdom_name AS target_name'
            . ' FROM ' . IDO_DB::t('ops') . ' o'
            . ' LEFT JOIN ' . IDO_DB::t('kingdoms') . ' a ON a.id = o.actor_kingdom_id'
            . ' LEFT JOIN ' . IDO_DB::t('kingdoms') . ' t ON t.id = o.target_kingdom_id'
            . ' WHERE o.actor_kingdom_id = %d OR o.target_kingdom_id = %d'
            . ' ORDER BY o.id DESC LIMIT %d',
            (int) $kingdom->id, (int) $kingdom->id, $limit
        ));
    }
}
