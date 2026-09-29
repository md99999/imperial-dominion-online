<?php
if (!defined('ABSPATH')) exit;

/**
 * The board reset: a whole site beaten flat, started again.
 *
 * A single ruined empire is one player's bad week. This is the other case: every
 * empire on the site in ruins, nothing left to build from, and no realistic way
 * back inside a round. A league site that has been marched on once too often can
 * end up there, and so can a local board that went badly.
 *
 * **It is a new install, with the players kept.** Every empire is restored to
 * exactly the package a new ruler is founded with, and given exactly the truce a
 * new ruler gets. Names, accounts and the Hall of Fame survive; nothing else
 * does. That is the whole of it, and the simplicity is the point: a reset that
 * handed out its own special package would be a third set of starting numbers
 * for somebody to balance, and "like a new install" would mean whatever the reset
 * code happened to say.
 *
 * The truce applies to everybody at once rather than one at a time. Without it
 * the site that flattened them is still strong, still in range, and would do it
 * again on day one, so the reset would achieve nothing but a longer defeat.
 */
class IDO_Board {

    /**
     * Restores every empire in a round to its founding state.
     *
     * Deliberately not a round rollover. A new round retires the empires and
     * makes everybody claim a new one, which loses the players; this keeps them
     * and gives them their starting package back. The Hall of Fame is untouched
     * either way, because a board being beaten flat is part of its history rather
     * than a reason to rewrite it.
     *
     * @return array{empires:int,cleared:int,marches:int}
     */
    public static function reset(int $round_id, string $reason = ''): array {
        global $wpdb;

        // In-flight league business first. An army away when the board resets
        // would otherwise come home to an empire that has already been given its
        // starting troops, and add its survivors on top: a reset that hands out
        // free legions to whoever happened to be marching.
        $marches = self::stand_down_league($round_id);

        $empires = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d', $round_id
        ));

        $package = IDO_Kingdom::starting_package();
        $blank = self::blank_row($package);

        // Counted by what actually changed, not by what was attempted. The first
        // version counted the loop and reported "2 empires refounded" while every
        // single update was failing on an unknown column, which is a worse bug
        // than the failure: a reset that lies about having worked leaves a board
        // everybody believes is new.
        $refounded = 0;
        foreach ($empires as $row) {
            $changed = $wpdb->update(IDO_DB::t('kingdoms'), $blank, ['id' => (int) $row->id]);
            if ($changed === false) {
                IDO_Log::admin('reset', sprintf(
                    'Could not refound empire %d: %s', (int) $row->id, $wpdb->last_error
                ));
                continue;
            }
            $refounded++;
            $kingdom = IDO_Kingdom::find((int) $row->id);
            if ($kingdom) IDO_Kingdom::recalc_networth($kingdom);
        }

        // Work in progress and goods on the market are in-flight commitments
        // against a game that no longer exists.
        $cleared = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . IDO_DB::t('constructions') . ' WHERE round_id = %d', $round_id
        ));
        $cleared += (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . IDO_DB::t('listings') . ' WHERE round_id = %d', $round_id
        ));

        self::begin_grace();

        $hours = IDO_Settings::int('protection_hours');
        IDO_Log::news('reset', sprintf(
            'The board has been swept clean. Every empire begins again with a founding grant and a '
            . 'crown truce of %d hours, and no empire may be attacked until it ends.%s',
            $hours, $reason !== '' ? ' ' . $reason : ''
        ));
        IDO_Log::admin('reset', sprintf(
            'Board reset: %d of %d empires refounded, %d in-flight orders cleared, %d league march(es) stood down.%s',
            $refounded, count($empires), $cleared, $marches, $reason !== '' ? ' ' . $reason : ''
        ));

        return ['empires' => $refounded, 'attempted' => count($empires),
                'cleared' => $cleared, 'marches' => $marches];
    }

    /**
     * Every column an empire has, set to what a founding sets it to.
     *
     * Built from the column list rather than written out, so a column added later
     * is zeroed by a reset instead of quietly surviving it. That is the failure
     * this method is shaped to avoid: a reset that leaves one stale number behind
     * is worse than no reset, because the board looks new and is not.
     */
    private static function blank_row(array $package): array {
        $blank = [];
        foreach (IDO_Kingdom::numeric_columns() as $column) $blank[$column] = 0;

        // Only columns this table actually has. An earlier version set
        // defeated_at, which is in the design notes and not in the schema, and
        // $wpdb->update() fails the whole statement over one unknown column: the
        // reset reported success and changed nothing at all.
        $blank['networth']    = 0;
        $blank['is_defeated'] = 0;
        $blank['last_seen']   = IDO_Game::now();

        // The founding values land last, on top of the zeroes.
        foreach ($package as $column => $value) $blank[$column] = $value;

        return $blank;
    }

    /**
     * Whether a board is finished: not poor, but finished.
     *
     * Measured against what the site was founded with rather than against its
     * best day, because the question is "can these empires still play", not "have
     * they had a setback". A board whose empires together are worth less than a
     * share of their founding grants has nothing left to build from.
     *
     * A site with no empires is not ruined, it is empty, and resetting it every
     * night would be noise.
     */
    public static function is_ruined(int $round_id): bool {
        $share = IDO_Settings::int('board_ruin_percent');
        if ($share < 1) return false;   // 0 switches the automatic reset off

        $empires = self::empire_count($round_id);
        if ($empires < 1) return false;

        $founding = self::founding_worth() * $empires;
        if ($founding < 1) return false;

        return self::board_worth($round_id) < $founding * ($share / 100);
    }

    /**
     * What one freshly founded empire is worth.
     *
     * Computed from the founding package through the same valuation the rankings
     * use, so tuning the starting grant moves the ruin threshold with it and
     * nobody has to remember that the two are related.
     */
    public static function founding_worth(): int {
        $package = IDO_Kingdom::starting_package();

        $sheet = (object) array_merge(
            array_fill_keys(IDO_Kingdom::numeric_columns(), 0),
            array_filter($package, static fn($v) => is_int($v))
        );
        $sheet->b_fortification = 0;

        $worth = (float) $sheet->land * 500
               + (float) $sheet->peasants * 25
               + (float) IDO_Units::networth($sheet)
               + (float) $sheet->gold / 50
               + (float) $sheet->grain / 200
               + (float) $sheet->iron / 20;

        return IDO_Game::clamp($worth);
    }

    public static function board_worth(int $round_id): int {
        global $wpdb;
        return IDO_Game::clamp((float) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(networth), 0) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d',
            $round_id
        )));
    }

    public static function empire_count(int $round_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d', $round_id
        ));
    }

    /** How far a board is from being called finished, for the admin screen. */
    public static function ruin_report(int $round_id): array {
        $empires = self::empire_count($round_id);
        $threshold = (int) round(self::founding_worth() * $empires
            * (IDO_Settings::int('board_ruin_percent') / 100));

        return [
            'empires'   => $empires,
            'worth'     => self::board_worth($round_id),
            'threshold' => $threshold,
            'ruined'    => self::is_ruined($round_id),
        ];
    }

    /** Called by the daily tick. */
    public static function reset_if_ruined(int $round_id): string {
        if (!self::is_ruined($round_id)) return '';

        $result = self::reset($round_id, 'The board could not stand again on its own.');
        return sprintf('The board was beaten flat and has been refounded: %d empires begin again.',
            $result['empires']);
    }

    // -- The league side ---------------------------------------------------

    /**
     * Stands down anything in flight, so nothing settles into a refounded empire.
     *
     * An open muster is returned, which costs nothing: the pledges are about to be
     * overwritten anyway, and returning them keeps one path for undoing an escrow.
     * A march already gone is marked finished so the result cannot credit anybody
     * when it arrives, and its contributions are removed so there is nobody left
     * to credit even if it did.
     */
    private static function stand_down_league(int $round_id): int {
        global $wpdb;

        if (!class_exists('IDO_League') || !IDO_League::league()) return 0;

        $stood_down = 0;

        $open = IDO_League_Muster::open();
        if ($open) {
            IDO_League_Muster::cancel((int) $open->id, 'The board was refounded.');
            $stood_down++;
        }

        $away = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT id FROM ' . IDO_DB::t('league_marches')
            . ' WHERE round_id = %d AND direction = %s AND status IN (%s, %s)',
            $round_id, 'out', IDO_League_Status::MARCHING, IDO_League_Status::IN_BATTLE
        ));
        foreach ($away as $row) {
            $wpdb->delete(IDO_DB::t('league_contributions'), ['march_id' => (int) $row->id], ['%d']);
            $wpdb->update(IDO_DB::t('league_marches'), [
                'status'      => IDO_League_Status::RESOLVED,
                'outcome'     => 'void',
                'resolved_at' => IDO_League::now(),
                'report'      => 'The board was refounded while this army was away. Nothing came back.',
            ], ['id' => (int) $row->id]);
            $stood_down++;
        }

        // And the record goes with the empires.
        //
        // A site that could take a fresh founding grant and keep its league points
        // would have found the best move in the game: win a few exchanges, reset
        // to full strength, and carry the score into a board nobody can touch for
        // the length of the truce. Starting again means starting again.
        //
        // Marked rather than deleted, so the rows survive for an administrator to
        // look at. Scoring ignores them from here.
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('league_marches') . ' SET outcome = %s'
            . ' WHERE round_id = %d AND status = %s AND outcome NOT IN (%s, %s)',
            'forfeited', $round_id, IDO_League_Status::RESOLVED, 'forfeited', 'void'
        ));

        return $stood_down;
    }

    /**
     * Tells the league this site is rebuilding and cannot be marched on.
     *
     * The same hours a new ruler gets, published to the peers in the next news
     * packet. A march already in flight toward us is refused when it lands and the
     * force goes home intact, which is the rule the march code already follows:
     * the attacker committed days before the reset happened and should lose the
     * turns and the time but not the army.
     */
    private static function begin_grace(): void {
        global $wpdb;

        if (!class_exists('IDO_League')) return;
        $league = IDO_League::league();
        if (!$league) return;

        // On the league row, not as a row in the peer table. The peer table holds
        // other sites: a self-row there would turn up in the member list, in the
        // standings and in the member count, and everything that iterates peers
        // would have to remember to skip it. Our own state belongs on our own row.
        $until = gmdate('Y-m-d H:i:s', time() + IDO_Settings::int('protection_hours') * HOUR_IN_SECONDS);
        $wpdb->update(IDO_DB::t('leagues'), ['grace_until' => $until], ['id' => (int) $league->id]);
        IDO_League::forget();
    }

    /** Whether this site is inside its rebuilding grace period. */
    public static function under_grace(): bool {
        $until = self::grace_until();
        return $until !== null && strtotime($until . ' UTC') > time();
    }

    /** When it ends, for a screen to show. */
    public static function grace_until(): ?string {
        if (!class_exists('IDO_League')) return null;
        $league = IDO_League::league();
        if (!$league || empty($league->grace_until)) return null;
        return (string) $league->grace_until;
    }
}
