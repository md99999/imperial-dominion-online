<?php
if (!defined('ABSPATH')) exit;

/**
 * The three states a march passes through, and nothing else.
 *
 * A league exchange takes up to a fortnight, and for most of that time a ruler
 * has committed an army and can see nothing. Three states is the answer: where
 * the army is, that the day has come, and how it went. Any more and the screen
 * becomes a progress bar for something whose whole point is not knowing.
 *
 *   MARCHING  the army has left and is on its way
 *   IN_BATTLE the wait has elapsed and the battle is being fought
 *   RESOLVED  the result is home: won or lost
 *
 * These are deliberately not database statuses. The packet rows have their own
 * (`staged`, `processed`, `sent`), which describe what the software is doing with
 * a packet; these describe what is happening to an army, which is what a player
 * cares about. One is plumbing and the other is the game, and conflating them
 * would mean a player reading the word "staged" about their legions.
 */
class IDO_League_Status {

    const MUSTERING = 'mustering';
    const MARCHING  = 'marching';
    const IN_BATTLE = 'in_battle';
    const RESOLVED  = 'resolved';

    /**
     * The status is a column on ido_league_marches, not something recomputed
     * wherever it happens to be needed.
     *
     * A march is a row that several things write to over a fortnight: the muster
     * screen, the queue worker when the packet goes out, the worker again when
     * the result lands, and the contributions that settle against it. Deriving
     * the state from timestamps in four places would mean four chances to derive
     * it differently, and no way to query for "every march still out" without
     * scanning. So the row carries its state, derive() says what that state
     * should be, and one place writes it.
     */
    public static function all(): array {
        return [self::MUSTERING, self::MARCHING, self::IN_BATTLE, self::RESOLVED];
    }

    /** What a ruler is told, for each state. */
    public static function label(string $status, bool $won = false): string {
        switch ($status) {
            case self::MUSTERING: return 'Mustering';
            case self::MARCHING:  return 'Marching to the battlefield';
            case self::IN_BATTLE: return 'In battle';
            case self::RESOLVED:  return $won ? 'Victory' : 'Defeat';
        }
        return 'Unknown';
    }

    /** A longer line for the march screen, where there is room to explain. */
    public static function describe(string $status, ?string $due_after = null): string {
        switch ($status) {
            case self::MUSTERING:
                return 'The call has gone out. Rulers are committing their forces, and the army leaves '
                     . 'when the muster closes.';
            case self::MARCHING:
                return 'Your army is on the road. It will reach the enemy within the week, and you will '
                     . 'not know the day until it arrives.';
            case self::IN_BATTLE:
                return 'The army has arrived and the battle is being fought. The dispatches will take '
                     . 'days to reach you.';
            case self::RESOLVED:
                return 'The survivors are home and the dispatches have been read.';
        }
        return '';
    }

    /**
     * What the *defending* side is allowed to see. One state, and only at the end.
     *
     * The defender learns nothing until the battle has been fought, and then
     * learns the outcome. No "a march is inbound", no "it lands on Thursday", no
     * count of packets waiting.
     *
     * This is the surprise, and the surprise is the point. A defender who knew
     * something was coming would reinforce, recall an army of their own, or empty
     * the treasury into the market, and every one of those turns a blind exchange
     * into a scheduling exercise. It would not even be cheating: the information
     * would be sitting on their own screen.
     *
     * The receiving site's software has to know, of course. It drew the delay and
     * holds the packet. The rule is that none of that reaches a person until the
     * daily tick resolves it, and it applies to the administrator as much as to
     * the players, because on most sites those are the same person.
     *
     * @return string|null the outcome once it exists, null while it must stay hidden
     */
    public static function for_defender(object $packet): ?string {
        return (string) $packet->status === 'processed' ? self::RESOLVED : null;
    }

    /**
     * Whether a packet type must stay invisible until it has been processed.
     *
     * News is public information by design and can be seen the moment it lands.
     * A march and its result are the opposite.
     */
    public static function is_secret_until_resolved(string $packet_type): bool {
        return in_array($packet_type, ['war', 'result'], true);
    }

    /**
     * What state a march row should be in, given what has happened to it.
     *
     * The middle state is the interesting one, and it costs nothing. The
     * attacking site cannot calculate when the battle happens, by design: the
     * receiver draws the delay, and an attacker who could see "in battle" appear
     * on the right day would have learned the draw.
     *
     * It does not have to calculate it. The defending site sends the result the
     * moment it fights, and that packet is staged for a day rather than applied.
     * So the attacker holds a sealed dispatch: its arrival says the battle has
     * been fought, and its contents stay shut until the next daily tick. The
     * army is in battle on the day the result lands, and the outcome is read out
     * the morning after.
     *
     * That is one packet doing the work of two, and nothing had to be invented to
     * announce it.
     *
     * @param object $march a row from ido_league_marches
     */
    public static function derive(object $march): string {
        if (!empty($march->resolved_at)) return self::RESOLVED;
        if (!empty($march->joined_at))   return self::IN_BATTLE;
        if (!empty($march->sent_at))     return self::MARCHING;
        return self::MUSTERING;
    }
}
