<?php
if (!defined('ABSPATH')) exit;

/**
 * Sending the agent with the army.
 *
 * A ruler committing to a muster may send their agent along with their legions.
 * The agent rides ahead, and on the night before the battle tries to open the
 * gates from the inside: cut the hoists, fire the stores under the wall, bribe a
 * watch officer. Succeed and the fortifications count for less when the army
 * arrives. Fail and the agent is taken, and an agent taken behind enemy lines is
 * an agent hanged.
 *
 * The rules are the local ones. Same chance-and-risk shape, same two rolls, same
 * record in the same ops table. This is not a second covert system with its own
 * arithmetic; it is the existing one pointed at a wall.
 *
 * Three things make it a real decision rather than a free extra:
 *
 * - **An empire keeps one agent**, and it costs a great deal. Sending them is
 *   spending the only one there is.
 * - **The agent is escrowed with the army.** They are gone for the whole march
 *   and cannot run a local mission meanwhile.
 * - **Failure is total.** There is no partial credit, no consolation, and no
 *   agent coming home to try again. The walls stand and the ruler starts saving
 *   for another agent.
 *
 * Resolution happens on the *defending* site, at the moment of the battle, from
 * the war packet. That matters for more than tidiness: a covert attempt resolved
 * anywhere else would have to be announced somehow, and announcing it would tell
 * a defender that a march was coming. Here the attempt, the battle and the
 * defender's first knowledge of any of it are the same event.
 */
class IDO_League_Covert {

    /** The one mission an agent can fly with an army. */
    const OP = 'undermine_walls';

    /**
     * Harder than any local mission, and it should be.
     *
     * A local agent works a neighbour they could ride to. This one is deep in
     * another realm, among people who do not know him, on a night the garrison is
     * already nervous. Lower odds than the local sabotage missions and a higher
     * chance of the rope, which is what makes sending him a gamble rather than a
     * step in a routine.
     */
    public static function op(): array {
        return [
            'key'    => self::OP,
            'label'  => 'Undermine the walls',
            'note'   => 'Your agent rides ahead of the army. If he opens the way, the enemy '
                      . 'fortifications count for less when the assault comes. If he is caught, he hangs.',
            'gold'   => 60000,
            'chance' => 55,
            'risk'   => 45,
        ];
    }

    /** What sending an agent costs the empire that sends one, before anything is rolled. */
    public static function cost(): int {
        return (int) self::op()['gold'];
    }

    /**
     * The odds for one agent, given how the two armies compare.
     *
     * The local game modifies the chance by net worth, attacker against target.
     * That cannot be used here, because the only figure this site holds about the
     * other one is what that site published about itself, and a mechanic resting
     * on a self-reported number is a mechanic a peer can tune by lying.
     *
     * So the modifier is the ratio of the force that actually arrived to the
     * defence actually standing, both of which the defending site knows first
     * hand at the moment it resolves. A great host at the gates gives an agent
     * cover to work in; a token raid leaves him conspicuous.
     */
    public static function chance(float $attack_power, float $defence_power): int {
        // IDO_Covert::ratio and ::odds, not a copy of them. The local court and a
        // league march run the same arithmetic, so tuning one tunes both and
        // neither can drift into behaving differently from what the other's
        // screen promises.
        $ratio = IDO_Covert::ratio((int) $attack_power, (int) $defence_power);

        // A lower ceiling than a local mission. Deep in another realm there is no
        // such thing as a sure thing, however big the army at the gates.
        return IDO_Covert::odds((int) self::op()['chance'], $ratio, 90);
    }

    /**
     * Rolls every agent that rode with the army.
     *
     * One roll for the mission and, on failure, a second for whether he is taken,
     * which is exactly the local sequence. A failed mission is not automatically a
     * dead agent: most of them get out, and the ones who do not are hanged at the
     * gate.
     *
     * @param int[] $kingdom_ids the empires that sent an agent
     * @return array{successes:int,results:array<int,array{success:bool,lost:bool}>}
     */
    public static function resolve(array $kingdom_ids, float $attack_power, float $defence_power): array {
        $chance  = self::chance($attack_power, $defence_power);
        $risk    = (int) self::op()['risk'];
        $results = [];
        $successes = 0;

        foreach ($kingdom_ids as $kingdom_id) {
            // The same two rolls a local mission makes, from the same method, so
            // "only a failed agent can be taken" is one rule rather than two
            // implementations of it that happen to agree today.
            $attempt = IDO_Covert::attempt($chance, $risk);

            $results[(int) $kingdom_id] = $attempt;
            if ($attempt['success']) $successes++;
        }

        return ['successes' => $successes, 'results' => $results];
    }

    /**
     * How much of the defender's fortification bonus a successful night removes.
     *
     * Half for the first agent through, and diminishing after that: a second and
     * third pair of hands help, and ten agents cannot make a wall stop existing.
     * The cap is deliberate. A siege the attacker wins before arriving is not a
     * siege, and a site able to field a dozen agents should not be able to switch
     * the defender's fortifications off.
     *
     * @return float the share of the fortification bonus to cancel, 0 to 0.75
     */
    public static function wall_reduction(int $successes): float {
        if ($successes < 1) return 0.0;

        $reduction = 0.0;
        $step = 0.5;
        for ($i = 0; $i < $successes; $i++) {
            $reduction += $step;
            $step /= 2;
        }
        return min(0.75, round($reduction, 4));
    }

    /**
     * Records each attempt in the ops table the local game already uses.
     *
     * The target is a site rather than an empire, so `target_kingdom_id` is zero
     * and the peer is named in the report instead. The alternative was a second
     * table holding the same six columns, which would mean a ruler's covert
     * history lived in two places and the Spy Court screen had to merge them.
     */
    public static function record(int $round_id, int $kingdom_id, string $peer_name,
                                  bool $success, bool $lost): void {
        global $wpdb;

        $actor = $success
            ? sprintf('Your agent opened the way into %s. The walls counted for less when the assault came.', $peer_name)
            : ($lost
                ? sprintf('Your agent was taken inside %s and hanged at the gate. He will not be seen again.', $peer_name)
                : sprintf('Your agent found no way into %s and came home with the army.', $peer_name));

        $wpdb->insert(IDO_DB::t('ops'), [
            'round_id'          => $round_id,
            'actor_kingdom_id'  => $kingdom_id,
            'target_kingdom_id' => 0,
            'op_type'           => self::OP,
            'outcome'           => $success ? 'success' : 'failure',
            'agent_lost'        => $lost ? 1 : 0,
            'actor_report'      => $actor,
            'target_report'     => '',
            'created_at'        => IDO_Game::now(),
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']);
    }

    /** The line the whole site reads afterwards, win or lose. */
    public static function summary(int $sent, int $successes, int $lost, string $peer_name): string {
        if ($sent < 1) return '';

        // One agent is the common case, and a line written for the plural reads
        // badly there: "The agent found no way in. One was caught" is two
        // sentences about the same man.
        if ($sent === 1) {
            if ($successes === 1) {
                return sprintf('Your agent got inside %s and the walls counted for less.', $peer_name);
            }
            return $lost > 0
                ? sprintf('Your agent found no way into %s, and was hanged at the gate.', $peer_name)
                : sprintf('Your agent found no way into %s and came home with the army.', $peer_name);
        }

        $line = $successes > 0
            ? sprintf('%d of %d agents sent ahead got inside %s and the walls counted for less.',
                $successes, $sent, $peer_name)
            : sprintf('The %d agents sent ahead found no way into %s.', $sent, $peer_name);

        if ($lost > 0) {
            $line .= sprintf(' %s hanged at the gate.',
                $lost === 1 ? 'One was caught and' : sprintf('%d were caught and', $lost));
        }
        return $line;
    }
}
