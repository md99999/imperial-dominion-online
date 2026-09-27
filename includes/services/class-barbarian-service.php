<?php
if (!defined('ABSPATH')) exit;

/**
 * Barbarians: raiders who arrive without warning during a turn, take a share
 * of the treasury and the granaries, and leave.
 *
 * They visit only the leading empires, and only on a board big enough for a
 * leader to have pulled ahead of a field. That is the whole point of them: a
 * brake on a runaway lead rather than a tax on everybody. There is no defence,
 * deliberately, because the moment they can be defended against they become
 * another military calculation and the game already has one of those.
 *
 * Nothing here writes to the database. A raid happens in the middle of a turn,
 * and turns are accumulated in memory and written once at the end, so the
 * economy applies what these methods calculate and stores the result with
 * everything else.
 */
class IDO_Barbarians {

    /**
     * Whether this empire is a candidate at all.
     *
     * Worked out once per order rather than once per turn: it costs a query,
     * and a ruler cannot climb or fall in the standings midway through
     * spending a single handful of turns.
     */
    public static function eligible(object $kingdom): bool {
        if (!IDO_Settings::int('barbarians_enabled')) return false;
        if ((int) $kingdom->is_defeated === 1) return false;

        $min_players = IDO_Settings::int('barbarian_min_players');
        if (IDO_Rankings::kingdom_count((int) $kingdom->round_id) < $min_players) return false;

        $top = max(1, IDO_Settings::int('barbarian_top_ranks'));
        return IDO_Rankings::position($kingdom) <= $top;
    }

    /** Whether they turn up during one particular turn. */
    public static function rolls(): bool {
        $chance = max(0, min(100, IDO_Settings::int('barbarian_chance_percent')));
        return $chance > 0 && wp_rand(1, 100) <= $chance;
    }

    /**
     * What a raid takes from the stores as they stand this turn.
     *
     * A share rather than a fixed amount: it scales with what is there, so it
     * bites a leader harder than somebody merely doing well, and it can never
     * bankrupt anybody, because a tenth of something leaves nine tenths.
     *
     * @return array{gold:int,grain:int}
     */
    public static function take(int $gold, int $grain): array {
        $gold_share  = max(0, min(100, IDO_Settings::int('barbarian_gold_percent')));
        $grain_share = max(0, min(100, IDO_Settings::int('barbarian_grain_percent')));

        return [
            'gold'  => (int) floor(max(0, $gold) * $gold_share / 100),
            'grain' => (int) floor(max(0, $grain) * $grain_share / 100),
        ];
    }

    /** The line a ruler is shown afterwards. */
    public static function report(array $taken, int $raids): string {
        $line = $raids > 1
            ? sprintf('Barbarians raided your empire %d times while your orders were carried out, ', $raids)
            : 'Barbarians have raided your empire, ';

        return $line . sprintf(
            'carrying off %s gold and %s grain. They keep to no treaty and no wall stops them: only the leading empires are worth their trouble.',
            IDO_Game::fmt($taken['gold']), IDO_Game::fmt($taken['grain'])
        );
    }

    /** A leader being humbled is news, and the rest of the board enjoys it. */
    public static function announce(object $kingdom, array $taken): void {
        IDO_Log::news('barbarians', sprintf(
            'Barbarians fell upon %s and carried off %s gold and %s grain.',
            $kingdom->kingdom_name, IDO_Game::fmt($taken['gold']), IDO_Game::fmt($taken['grain'])
        ));
    }
}
