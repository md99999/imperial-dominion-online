<?php
if (!defined('ABSPATH')) exit;

/**
 * Sending the agent with the army.
 *
 * A ruler committing to a muster may send their agent along with their legions.
 * The agent rides ahead, and on the night before the battle tries to open the
 * gates from the inside: cut the hoists, fire the stores under the wall, bribe a
 * watch officer. Succeed and the fortifications count for less when the army
 * arrives. Fail and the agent may be taken, and an agent taken behind enemy
 * lines is an agent hanged.
 *
 * The rules are the local ones. Same chance bent by the same ratio, the same two
 * rolls, the same record in the same ops table, read from IDO_Covert rather than
 * copied into a second file. Two versions of one piece of arithmetic is how they
 * end up disagreeing: somebody tunes one, and a mission that reads identically on
 * screen behaves differently depending on where it was ordered.
 *
 * **One agent to a march, and only one.** The first ruler to offer theirs takes
 * the slot and everybody else is turned away. That is not a balance tweak. A wall
 * has one night and one weak point, and a queue of spies tripping over each other
 * in the same cellar is a worse story than one man with one chance. It also stops
 * the mechanic scaling with the size of the site, which is the failure mode the
 * rest of league play is built to avoid, and it makes the slot worth racing for.
 *
 * Three things make it a decision rather than a free extra:
 *
 * - **An empire keeps one agent**, and he costs a great deal. Sending him is
 *   spending the only one there is.
 * - **The agent is escrowed with the army.** He is gone for the whole march and
 *   cannot run a local mission meanwhile.
 * - **Failure is total.** No partial credit, no consolation, and no agent coming
 *   home to try again. The walls stand and the ruler starts saving.
 *
 * Resolution happens on the *defending* site, at the moment of the battle, out of
 * the war packet. That matters for more than tidiness: a covert attempt resolved
 * anywhere else would have to be announced somehow, and announcing it would tell
 * a defender that a march was coming. Here the attempt, the battle, and the
 * defender's first knowledge of any of it are the same event.
 */
class IDO_League_Covert {

    /** The one mission an agent can fly with an army. */
    const OP = 'undermine_walls';

    /**
     * Harder than any local mission, and it should be.
     *
     * A local agent works a neighbour he could ride to. This one is deep in
     * another realm, among people who do not know him, on a night the garrison is
     * already nervous. Lower odds than local sabotage and a higher chance of the
     * rope, which is what makes sending him a gamble rather than a routine step.
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
     * The odds, given how the two armies compare.
     *
     * The local game bends the chance by net worth, attacker against target. That
     * cannot be used here, because the only figure this site holds about the other
     * one is what that site published about itself, and a mechanic resting on a
     * self-reported number is a mechanic a peer can tune by lying.
     *
     * So the modifier is the ratio of the force that actually arrived to the
     * defence actually standing, both of which the defending site knows first hand
     * at the moment it resolves. A great host at the gates gives an agent cover to
     * work in; a token raid leaves him conspicuous.
     */
    public static function chance(float $attack_power, float $defence_power): int {
        // IDO_Covert::ratio and ::odds, not a copy of them, so tuning the local
        // court tunes this too and neither can drift into behaving differently
        // from what the other screen promises.
        $ratio = IDO_Covert::ratio((int) $attack_power, (int) $defence_power);

        // A lower ceiling than a local mission. Deep in another realm there is no
        // such thing as a sure thing, however large the army at the gates.
        return IDO_Covert::odds((int) self::op()['chance'], $ratio, 90);
    }

    /**
     * Rolls the one agent who rode with the army.
     *
     * One roll for the mission and, on failure, a second for whether he is taken,
     * which is exactly the local sequence. A failed mission is not automatically a
     * dead agent: most of them get out, and the ones who do not are hanged.
     *
     * @return array{success:bool,lost:bool}
     */
    public static function resolve(float $attack_power, float $defence_power): array {
        // The same two rolls a local mission makes, from the same method, so
        // "only a failed agent can be taken" is one rule rather than two
        // implementations of it that happen to agree today.
        return IDO_Covert::attempt(
            self::chance($attack_power, $defence_power),
            (int) self::op()['risk']
        );
    }

    /**
     * Takes the march's single agent slot, or fails because somebody has it.
     *
     * A guarded write, and it has to be. Two rulers pressing the button in the
     * same second would both read an empty slot, both believe they had it, and the
     * loser would find out a week later when the report named somebody else's
     * agent. `WHERE agent_kingdom_id = 0` makes the race unwinnable: exactly one
     * UPDATE can affect the row, the other affects nothing and is told so at once.
     *
     * The same pattern the rest of the game spends gold with, for the same reason.
     */
    public static function claim_slot(int $march_id, int $kingdom_id): bool {
        global $wpdb;

        $taken = $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('league_marches')
            . ' SET agent_kingdom_id = %d WHERE id = %d AND agent_kingdom_id = 0',
            $kingdom_id, $march_id
        ));
        return $taken === 1;
    }

    /**
     * Gives the slot back when a ruler withdraws from a muster still open.
     *
     * Guarded on the claimant and on the state, so withdrawing cannot release
     * somebody else's agent and nothing can be recalled once the army has left.
     */
    public static function release_slot(int $march_id, int $kingdom_id): bool {
        global $wpdb;

        $freed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('league_marches')
            . ' SET agent_kingdom_id = 0 WHERE id = %d AND agent_kingdom_id = %d AND status = %s',
            $march_id, $kingdom_id, IDO_League_Status::MUSTERING
        ));
        return $freed === 1;
    }

    /** Which empire holds the slot, or 0 while it is free. */
    public static function slot_holder(int $march_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT agent_kingdom_id FROM ' . IDO_DB::t('league_marches') . ' WHERE id = %d',
            $march_id
        ));
    }

    /** Why a ruler cannot send theirs, in words, or empty if they can. */
    public static function refusal(object $kingdom, int $march_id): string {
        if ((int) $kingdom->agents < 1) {
            return 'You keep no agent. Hire one at the Spy Court before you can send one with the army.';
        }
        if ((int) $kingdom->gold < self::cost()) {
            return sprintf('Sending your agent costs %s gold in bribes and you have %s.',
                IDO_Game::fmt(self::cost()), IDO_Game::fmt($kingdom->gold));
        }
        $holder = self::slot_holder($march_id);
        if ($holder !== 0 && $holder !== (int) $kingdom->id) {
            return 'Another ruler has already sent their agent ahead. Only one goes with the army.';
        }
        return '';
    }

    /**
     * How much of the defender's fortification bonus one successful night removes.
     *
     * Half, and never more, because there is only ever one agent. An earlier
     * version stacked several with diminishing returns and a cap, which was
     * careful arithmetic solving a problem that no longer exists: a mechanic that
     * cannot be stacked needs no ceiling to stop it being stacked.
     *
     * Half rather than all of it, so the walls still count for something, the
     * defender is not disarmed by one man, and an attacker who spends their only
     * agent buys an advantage rather than a result.
     */
    public static function wall_reduction(bool $succeeded): float {
        return $succeeded ? 0.5 : 0.0;
    }

    /**
     * Records the attempt in the ops table the local game already uses.
     *
     * The target is a site rather than an empire, so `target_kingdom_id` is zero
     * and the peer is named in the report instead. The alternative was a second
     * table holding the same six columns, which would mean a ruler's covert
     * history lived in two places and the Spy Court screen had to merge them.
     */
    public static function record(int $round_id, int $kingdom_id, string $peer_name,
                                  bool $success, bool $lost): void {
        global $wpdb;

        $wpdb->insert(IDO_DB::t('ops'), [
            'round_id'          => $round_id,
            'actor_kingdom_id'  => $kingdom_id,
            'target_kingdom_id' => 0,
            'op_type'           => self::OP,
            'outcome'           => $success ? 'success' : 'failure',
            'agent_lost'        => $lost ? 1 : 0,
            'actor_report'      => self::summary(true, $success, $lost, $peer_name),
            'target_report'     => '',
            'created_at'        => IDO_Game::now(),
        ], ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']);
    }

    /** The line the ruler who sent him reads afterwards, win or lose. */
    public static function summary(bool $sent, bool $success, bool $lost, string $peer_name): string {
        if (!$sent) return '';

        if ($success) {
            return sprintf('Your agent got inside %s and the walls counted for less.', $peer_name);
        }
        return $lost
            ? sprintf('Your agent found no way into %s, and was hanged at the gate.', $peer_name)
            : sprintf('Your agent found no way into %s and came home with the army.', $peer_name);
    }
}
