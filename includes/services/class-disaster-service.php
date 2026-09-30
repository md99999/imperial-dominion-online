<?php
if (!defined('ABSPATH')) exit;

/**
 * Natural disasters: a drought, a plague of insects or a flood that arrives
 * during a turn, takes a share of one thing, and passes.
 *
 * Where barbarians are a brake on a runaway leader, these fall on anybody. That
 * is the point of them. Barbarians only visit the top of the table, so an empire
 * in the middle of the field spends a whole round knowing exactly what its stores
 * will be worth tomorrow. A disaster is the game reminding it otherwise, and it
 * is why a ruler keeps a margin rather than running every granary to the last
 * bushel.
 *
 * Three rules shape the whole thing, and each one is a decision rather than an
 * implementation detail:
 *
 * **Never during a grace period.** Not the crown truce a new empire holds, not
 * the relief a ruined empire is given, and not the board-wide grace after a
 * reset. A grace period is a promise that nothing will happen to you yet, and a
 * flood is something happening to you. An empire that loses 7% of its homesteads
 * on day one has been handed a worse start than the ruler beside it for no reason
 * it could have played around.
 *
 * **Never combined.** One disaster settles per batch of turns, whatever else the
 * dice say. Two at once reads as the game being broken rather than the game being
 * hard, and a player who loses farmsteads and grain in the same order has no way
 * to tell which loss came from where.
 *
 * **No defence.** Deliberately, for the same reason barbarians have none: the
 * moment weather can be defended against it becomes another military calculation,
 * and the game already has one of those.
 *
 * Nothing here writes to the empires table. A disaster lands in the middle of a
 * turn, and turns are accumulated in memory and written once at the end, so the
 * economy applies what these methods work out and stores it with everything else.
 */
class IDO_Disasters {

    /**
     * The three of them. Each names the column it empties and how it reads in
     * the Gazette, so adding a fourth is a matter of adding a row here.
     *
     * 'stock' is what the disaster eats: either a building, which is destroyed
     * and hands its acre back to wilderness exactly as demolishing it would, or
     * a store, which simply goes.
     */
    public static function kinds(): array {
        return [
            'drought' => [
                'label'   => 'Drought',
                'stock'   => 'building',
                'key'     => 'farmstead',
                'report'  => 'A drought has burned across your fields. %s farmsteads are lost, and the acres go back to wilderness.',
                'gazette' => 'A drought burned across the fields of %s, taking %s farmsteads.',
            ],
            'insects' => [
                'label'   => 'Insects',
                'stock'   => 'grain',
                'key'     => 'grain',
                'report'  => 'A plague of insects has come through the granaries. %s grain is eaten where it stood.',
                'gazette' => 'A plague of insects stripped the granaries of %s, eating %s grain.',
            ],
            'flood' => [
                'label'   => 'Flood',
                'stock'   => 'building',
                'key'     => 'homestead',
                'report'  => 'The river has broken its banks. %s homesteads are swept away, and the acres go back to wilderness.',
                'gazette' => 'A flood broke over %s and swept away %s homesteads.',
            ],
        ];
    }

    /**
     * Whether this empire can be struck at all.
     *
     * Worked out once per order rather than once per turn, like the barbarian
     * check beside it: none of these conditions can change midway through
     * spending a single handful of turns.
     */
    public static function eligible(object $kingdom): bool {
        if (!IDO_Settings::int('disasters_enabled')) return false;
        if ((int) $kingdom->is_defeated === 1) return false;

        // Every kind of grace there is. See the note at the top: a grace period
        // is a promise, and this would break it.
        if (IDO_Kingdom::is_protected($kingdom)) return false;
        if (IDO_Board::under_relief($kingdom)) return false;
        if (IDO_Board::under_grace()) return false;

        return true;
    }

    /**
     * Whether one lands during a particular turn.
     *
     * Expressed as one-in-N rather than a percentage, because that is how the
     * odds were set: roughly one disaster in sixty turns, which at ten turns a
     * day is a little under one a week. The barbarian roll beside this one uses
     * a percentage, and 1 in 60 is not a whole number of them.
     */
    public static function rolls(): bool {
        $one_in = self::one_in();
        return $one_in > 0 && wp_rand(1, $one_in) === 1;
    }

    /** The odds, floored so that a misconfigured board cannot make them constant. */
    public static function one_in(): int {
        return max(10, IDO_Settings::int('disaster_one_in'));
    }

    /** The share each disaster takes, as a fraction. */
    public static function share(): float {
        return max(0, min(100, IDO_Settings::int('disaster_percent'))) / 100;
    }

    /**
     * Picks a disaster and works out what it costs, given the empire as it
     * stands this turn.
     *
     * Returns null when there is nothing to take, which is the same answer the
     * barbarians give when a raid would come to nothing: an empire with fourteen
     * farmsteads loses none to a 7% drought, and rather than announce a disaster
     * that destroyed nothing, no disaster happened. Floor, not ceil, so the
     * smallest empires are the last to feel it.
     *
     * @param object $kingdom the empire as it stands
     * @param int    $grain   the running grain total this turn, which is not yet on the row
     * @return array{kind:string,lost:int}|null
     */
    public static function strike(object $kingdom, int $grain): ?array {
        $kinds = self::kinds();
        $names = array_keys($kinds);
        $kind  = $names[wp_rand(0, count($names) - 1)];
        $spec  = $kinds[$kind];

        $standing = $spec['stock'] === 'grain'
            ? max(0, $grain)
            : max(0, (int) $kingdom->{IDO_Buildings::column($spec['key'])});

        $lost = (int) floor($standing * self::share());
        if ($lost < 1) return null;

        return ['kind' => $kind, 'lost' => $lost];
    }

    /** The line the struck ruler is shown. */
    public static function report(array $event): string {
        $spec = self::kinds()[$event['kind']] ?? null;
        if ($spec === null) return '';
        return sprintf($spec['report'], IDO_Game::fmt((int) $event['lost']));
    }

    /**
     * The Gazette line. Every ruler sees it, because a neighbour who has just
     * lost a tenth of their granaries is worth knowing about: it is the one
     * public signal in the game that somebody is weaker this evening than they
     * were this morning, and nobody chose to make them so.
     */
    public static function announce(object $kingdom, array $event): void {
        $spec = self::kinds()[$event['kind']] ?? null;
        if ($spec === null) return;

        IDO_Log::news('disaster', sprintf(
            $spec['gazette'], $kingdom->kingdom_name, IDO_Game::fmt((int) $event['lost'])
        ));
    }
}
