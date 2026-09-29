<?php
if (!defined('ABSPATH')) exit;

/**
 * Raising an army from several empires, and sending it.
 *
 * A muster is the days between one ruler deciding to fight and an army actually
 * leaving. The original games gave you one nightly tick to join a group attack;
 * this stretches that to days, because the rulers contributing are not all
 * logged in on the same evening.
 *
 * The rules that shape it:
 *
 * - **One open muster to a site.** A site cannot assemble two armies at once,
 *   which makes calling one a real act rather than a tactic to spam.
 * - **The caller commits first.** Nobody starts a war they are not in.
 * - **Committing escrows immediately.** Troops leave the empire the moment they
 *   are pledged, not when the packet is sent, so the same army cannot stand at
 *   home and march at once, and the cost is obvious to the ruler who paid it.
 * - **A pledge can be withdrawn while the window is open**, and not after. Five
 *   days is a long time to be held to a decision made on the first morning, and
 *   a site attacked at home during its own muster needs an answer that is not
 *   "wait a fortnight".
 * - **A muster that raises too little does not march.** Everything returns, the
 *   turns stay spent, and the gazette records a war called and not raised.
 */
class IDO_League_Muster {

    /** Calling one costs more than any local order: it spends the site's exchange. */
    const CALL_TURN_COST = 5;

    /** Joining one costs a turn, like any other order. */
    const JOIN_TURN_COST = 1;

    /**
     * The least an army may be and still march, as a share of the site's own
     * standing strength.
     *
     * Measured against **this** site rather than the target, deliberately. The
     * only figure held about a peer is what that peer published about itself, and
     * a threshold resting on a self-reported number is a threshold a target can
     * move by lying about how big it is. What a site knows for certain is its own
     * army.
     *
     * Low on purpose. This is a floor against a token march that would feed the
     * target and teach the site nothing, not a quality bar for a good one. Set it
     * high and a board of twenty-five where only three rulers want to fight could
     * never march at all, which punishes the keen for their neighbours being
     * quiet. A twentieth of the site's standing army is enough to mean somebody
     * meant it.
     */
    const MIN_FORCE_PERCENT = 5;

    // -- Reading -----------------------------------------------------------

    /** The muster this site currently has open, or null. */
    public static function open(): ?object {
        global $wpdb;
        $league = IDO_League::league();
        if (!$league) return null;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('league_marches')
            . ' WHERE league_id = %d AND direction = %s AND status = %s ORDER BY id DESC LIMIT 1',
            (int) $league->id, 'out', IDO_League_Status::MUSTERING
        ));
        return $row ?: null;
    }

    /** @return object[] every pledge made to a march */
    public static function contributions(int $march_id): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, k.kingdom_name FROM ' . IDO_DB::t('league_contributions') . ' c'
            . ' LEFT JOIN ' . IDO_DB::t('kingdoms') . ' k ON k.id = c.kingdom_id'
            . ' WHERE c.march_id = %d ORDER BY c.id ASC',
            $march_id
        ));
    }

    /** What one ruler has pledged to a march, or null. */
    public static function contribution(int $march_id, int $kingdom_id): ?object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('league_contributions') . ' WHERE march_id = %d AND kingdom_id = %d',
            $march_id, $kingdom_id
        ));
        return $row ?: null;
    }

    /** The whole army as it stands, summed across every pledge. */
    public static function assembled(int $march_id): array {
        $force = [];
        $weapons = [];
        foreach (self::contributions($march_id) as $row) {
            $committed = json_decode((string) $row->committed_json, true);
            if (!is_array($committed)) continue;
            foreach ((array) ($committed['force'] ?? []) as $key => $qty) {
                $force[$key] = ($force[$key] ?? 0) + (int) $qty;
            }
            foreach ((array) ($committed['weapons'] ?? []) as $key => $qty) {
                $weapons[$key] = ($weapons[$key] ?? 0) + (int) $qty;
            }
        }
        return ['force' => $force, 'weapons' => $weapons];
    }

    /**
     * The standing strength of this whole site, for the minimum-force test.
     *
     * Counts what is at home plus what is already pledged, so pledging does not
     * lower the bar it is being measured against. Without that, each pledge would
     * shrink the site's apparent strength and the threshold would chase the army
     * downwards until almost anything qualified.
     */
    public static function site_strength(int $round_id): float {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_defeated = 0',
            $round_id
        ));

        $power = 0.0;
        foreach ($rows as $row) {
            $force = [];
            foreach (IDO_Units::keys() as $key) {
                $force[$key] = (int) $row->{IDO_Units::column($key)};
            }
            $power += IDO_Units::offence_power($force);
        }

        $march = self::open();
        if ($march) {
            $power += IDO_Units::offence_power(self::assembled((int) $march->id)['force']);
        }
        return $power;
    }

    /** What the army must reach before it will march. */
    public static function minimum_force(int $round_id): float {
        return self::site_strength($round_id) * (self::MIN_FORCE_PERCENT / 100);
    }

    // -- Calling -----------------------------------------------------------

    /**
     * Calls a muster against a chosen peer, and commits the caller's own force.
     *
     * One act, not two. A muster called and then abandoned by its own caller is
     * a site's whole exchange wasted on somebody who changed their mind between
     * two clicks.
     */
    public static function call(object $kingdom, int $peer_id, array $force, array $weapons = [],
                                bool $send_agent = false): array {
        global $wpdb;

        $league = IDO_League::league();
        if (!$league || !IDO_League::active()) {
            throw new IDO_Game_Exception('This site is not playing a league.');
        }
        if (self::open()) {
            throw new IDO_Game_Exception(
                'A muster is already open. A site raises one army at a time, and this one has to '
                . 'march or fail before another can be called.'
            );
        }

        $peer = IDO_League_Queue::peer($peer_id);
        if (!$peer || (int) $peer->league_id !== (int) $league->id
            || (string) $peer->status !== 'active' || (string) $peer->secret === '') {
            throw new IDO_Game_Exception('That site is not a member of this league.');
        }
        if ((int) $peer->is_hub === 1 && (string) $peer->site_uuid === (string) $league->site_uuid) {
            throw new IDO_Game_Exception('You cannot march on your own site.');
        }
        if ($peer->grace_until && strtotime((string) $peer->grace_until . ' UTC') > time()) {
            throw new IDO_Game_Exception(sprintf(
                '%s was beaten flat and is rebuilding under a grace period until %s. It cannot be marched on.',
                $peer->site_name, IDO_League::when($peer->grace_until)
            ));
        }

        // A site rebuilding under its opening truce cannot march either.
        // Protection is not a shield to attack from behind, which is the same
        // rule the local crown truce follows.
        if (IDO_Board::under_grace()) {
            throw new IDO_Game_Exception(sprintf(
                'This site is rebuilding under a truce until %s and cannot march while it holds. '
                . 'Protection is not a shield to attack from behind.',
                IDO_League::when(IDO_Board::grace_until())
            ));
        }

        $round = IDO_Rounds::current();
        if (!$round) throw new IDO_Game_Exception('No round is running.');

        // The calendar refuses a march that cannot finish inside the season.
        //
        // Measured against the league's season rather than this round's own end
        // date, because in a league the season is the deadline everybody shares
        // and the local date is whatever it happened to be when the site joined.
        $cutoff = IDO_League::longest_exchange_days((int) $league->muster_days);
        $ends = IDO_League::season_ends_at();
        if ($ends === null) {
            $ends = $round->ends_at ? strtotime((string) $round->ends_at) : 0;
        }
        if ($ends && $ends - time() < $cutoff * DAY_IN_SECONDS) {
            throw new IDO_Game_Exception(sprintf(
                'Too late in the season. A muster takes up to %d days to call, march and come home, and '
                . 'the season ends before that. The last stretch is for settling the standings.',
                $cutoff
            ));
        }

        $messages = IDO_Kingdom::spend_turns($kingdom, self::CALL_TURN_COST);
        $kingdom = IDO_Kingdom::reload($kingdom);

        $closes = gmdate('Y-m-d H:i:s', time() + max(1, (int) $league->muster_days) * DAY_IN_SECONDS);
        $wpdb->insert(IDO_DB::t('league_marches'), [
            'league_id'        => (int) $league->id,
            'peer_id'          => (int) $peer->id,
            'round_id'         => (int) $round->id,
            'direction'        => 'out',
            'status'           => IDO_League_Status::MUSTERING,
            'called_by'        => (int) $kingdom->id,
            'muster_closes_at' => $closes,
            'created_at'       => IDO_League::now(),
        ]);
        $march_id = (int) $wpdb->insert_id;
        if (!$march_id) throw new IDO_Game_Exception('The muster could not be opened.');

        try {
            // The caller pays no second turn for their own first pledge: calling
            // and committing are one act and are charged once.
            $messages = array_merge($messages, self::commit($kingdom, $march_id, $force, $weapons, $send_agent, false));
        } catch (IDO_Game_Exception $e) {
            // A muster nobody is in should not exist. Undo it rather than leave a
            // site unable to call another because of a failed first pledge.
            $wpdb->delete(IDO_DB::t('league_marches'), ['id' => $march_id], ['%d']);
            throw $e;
        }

        IDO_Log::news('league', sprintf(
            '%s has called a muster against %s. The army leaves on %s.',
            $kingdom->kingdom_name, $peer->site_name, IDO_League::when($closes)
        ));

        return $messages;
    }

    // -- Contributing ------------------------------------------------------

    /** A ruler joining a muster somebody else called. */
    public static function join(object $kingdom, int $march_id, array $force, array $weapons = [],
                                bool $send_agent = false): array {
        return self::commit($kingdom, $march_id, $force, $weapons, $send_agent, true);
    }

    /**
     * Pledges a force, and takes it out of the empire at once.
     *
     * The escrow is the whole point. Troops move out of the empire on the
     * guarded path everything else uses, so the same legion cannot be pledged
     * twice or stand in defence while it is pledged, and a ruler who has
     * committed can see that they have.
     */
    private static function commit(object $kingdom, int $march_id, array $force, array $weapons,
                                   bool $send_agent, bool $charge_turn): array {
        global $wpdb;

        $march = self::march($march_id);
        if ((string) $march->status !== IDO_League_Status::MUSTERING) {
            throw new IDO_Game_Exception('That muster has closed. The army has already left.');
        }
        // An empire rebuilding under relief joins the league when its truce ends.
        // The grant exists to get a ruined ruler playing again, and shipping it
        // off to somebody else's war for a fractional share of the spoils is the
        // fastest way to be ruined twice.
        if (IDO_Board::under_relief($kingdom)) {
            throw new IDO_Game_Exception(sprintf(
                'Your empire is rebuilding under relief until %s and cannot send anything to a muster '
                . 'until then. Build with what you have been given first.',
                IDO_League::when((string) $kingdom->relief_until)
            ));
        }
        if ((int) $kingdom->is_defeated === 1) {
            throw new IDO_Game_Exception(
                'Your empire is in ruins and awaiting relief. There is nothing to send and nothing is '
                . 'expected of you until it arrives.'
            );
        }
        if (self::contribution($march_id, (int) $kingdom->id)) {
            throw new IDO_Game_Exception(
                'You have already pledged to this muster. Withdraw first if you want to change what you send.'
            );
        }

        // Everything that can refuse this pledge runs before anything is spent.
        // The first version charged the turn and then found the agent slot
        // taken, which left a ruler a turn poorer for an order that never
        // happened. Validate, claim, and only then spend.
        [$committed, $train] = self::validate_force($kingdom, $force, $weapons);

        $agent_sent = false;
        if ($send_agent) {
            $refusal = IDO_League_Covert::refusal($kingdom, $march_id);
            if ($refusal !== '') throw new IDO_Game_Exception($refusal);

            if (!IDO_League_Covert::claim_slot($march_id, (int) $kingdom->id)) {
                throw new IDO_Game_Exception(
                    'Another ruler took the agent slot a moment before you. Only one goes with the army. '
                    . 'Pledge again without the agent if you still want to send your force.'
                );
            }
            $agent_sent = true;
        }

        $messages = [];
        try {
            if ($charge_turn) {
                $messages = IDO_Kingdom::spend_turns($kingdom, self::JOIN_TURN_COST);
                $kingdom = IDO_Kingdom::reload($kingdom);
            }
        } catch (IDO_Game_Exception $e) {
            // Out of turns after taking the slot: give it back rather than
            // holding it against a pledge that is not going to happen.
            if ($agent_sent) IDO_League_Covert::release_slot($march_id, (int) $kingdom->id);
            throw $e;
        }

        // Everything leaves at once: troops, weapons, and the agent's bribes.
        $deltas = [];
        foreach ($committed as $key => $qty) $deltas[IDO_Units::column($key)] = -$qty;
        foreach ($train as $key => $qty)     $deltas[IDO_Weapons::column($key)] = -$qty;
        if ($agent_sent) {
            $deltas['agents'] = -1;
            $deltas['gold']   = -IDO_League_Covert::cost();
        }

        try {
            IDO_Kingdom::pay($kingdom, $deltas, 'Your army no longer holds what you pledged.');
        } catch (IDO_Game_Exception $e) {
            if ($agent_sent) IDO_League_Covert::release_slot($march_id, (int) $kingdom->id);
            throw $e;
        }

        $wpdb->insert(IDO_DB::t('league_contributions'), [
            'march_id'       => $march_id,
            'kingdom_id'     => (int) $kingdom->id,
            'committed_json' => wp_json_encode([
                'force'   => $committed,
                'weapons' => $train,
                'agent'   => $agent_sent,
            ]),
            'committed_at'   => IDO_League::now(),
        ]);

        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        $messages[] = sprintf(
            'Your force joins the muster: %s. They are gone from your empire until the army returns.%s',
            self::describe_force($committed, $train),
            $agent_sent ? ' Your agent rides ahead of them.' : ''
        );
        return $messages;
    }

    /**
     * Takes a pledge back while the window is open.
     *
     * Everything committed returns and the turns stay spent, which is the right
     * price: the turns bought a decision, and the decision was made.
     */
    public static function withdraw(object $kingdom, int $march_id): string {
        global $wpdb;

        $march = self::march($march_id);
        if ((string) $march->status !== IDO_League_Status::MUSTERING) {
            throw new IDO_Game_Exception(
                'The army has already marched. Nothing comes back until the dispatches do.'
            );
        }
        $row = self::contribution($march_id, (int) $kingdom->id);
        if (!$row) throw new IDO_Game_Exception('You have pledged nothing to this muster.');

        if ((int) $march->called_by === (int) $kingdom->id) {
            throw new IDO_Game_Exception(
                'You called this muster. Cancel it with the game master rather than leaving it with no caller.'
            );
        }

        self::return_pledge($row);
        $wpdb->delete(IDO_DB::t('league_contributions'), ['id' => (int) $row->id], ['%d']);

        return 'Your force has been recalled and stands at home again. The turns you spent stay spent.';
    }

    /**
     * Puts one pledge back where it came from.
     *
     * Used by withdrawal, by a failed muster, and by a game master cancelling
     * one, so there is a single place that knows how to undo an escrow. The
     * agent's bribes are not refunded: that gold was spent on people, not held.
     */
    private static function return_pledge(object $row): void {
        $kingdom = IDO_Kingdom::find((int) $row->kingdom_id);
        if (!$kingdom) return;   // an empire deleted mid-muster: nothing to return it to

        $committed = json_decode((string) $row->committed_json, true);
        if (!is_array($committed)) return;

        $deltas = [];
        foreach ((array) ($committed['force'] ?? []) as $key => $qty) {
            if (IDO_Units::exists($key) && (int) $qty > 0) $deltas[IDO_Units::column($key)] = (int) $qty;
        }
        foreach ((array) ($committed['weapons'] ?? []) as $key => $qty) {
            if (IDO_Weapons::exists($key) && (int) $qty > 0) $deltas[IDO_Weapons::column($key)] = (int) $qty;
        }
        if (!empty($committed['agent'])) $deltas['agents'] = 1;

        if ($deltas) IDO_Kingdom::pay($kingdom, $deltas);
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));
    }

    /** Returns every pledge on a march, for a failure or a cancellation. */
    public static function return_all(int $march_id): int {
        global $wpdb;

        $returned = 0;
        foreach (self::contributions($march_id) as $row) {
            self::return_pledge($row);
            $returned++;
        }
        $wpdb->delete(IDO_DB::t('league_contributions'), ['march_id' => $march_id], ['%d']);
        return $returned;
    }

    // -- Closing -----------------------------------------------------------

    /**
     * Called by the daily tick: closes a muster whose window has run out.
     *
     * Either the army marches or the muster fails, and a failure returns
     * everything. A token force sent because the clock ran out would feed the
     * target and teach the site nothing.
     */
    public static function close_due(): string {
        $march = self::open();
        if (!$march) return '';
        if (!$march->muster_closes_at) return '';
        if (strtotime((string) $march->muster_closes_at . ' UTC') > time()) return '';

        return self::close($march);
    }

    /** @param object $march a row in the mustering state */
    public static function close(object $march): string {
        global $wpdb;

        $assembled = self::assembled((int) $march->id);
        $power     = IDO_Units::offence_power($assembled['force']);
        $minimum   = self::minimum_force((int) $march->round_id);
        $peer      = IDO_League_Queue::peer((int) $march->peer_id);
        $peer_name = $peer ? (string) $peer->site_name : 'the enemy';

        if ($power < $minimum || !$peer) {
            $returned = self::return_all((int) $march->id);
            $wpdb->update(IDO_DB::t('league_marches'),
                ['status' => IDO_League_Status::RESOLVED, 'outcome' => 'failed',
                 'resolved_at' => IDO_League::now(),
                 'report' => 'The muster failed: too few rulers answered the call.'],
                ['id' => (int) $march->id]
            );
            IDO_Log::news('league', sprintf(
                'The muster against %s failed. Too few answered the call, and %d force(s) stood down.',
                $peer_name, $returned
            ));
            return sprintf('The muster against %s failed and %d pledge(s) were returned.', $peer_name, $returned);
        }

        return IDO_League_March::send($march, $assembled);
    }

    /** The game master's veto, which returns everything. */
    public static function cancel(int $march_id, string $reason = ''): string {
        global $wpdb;

        $march = self::march($march_id);
        if ((string) $march->status !== IDO_League_Status::MUSTERING) {
            throw new IDO_Game_Exception('That army has already marched and cannot be recalled.');
        }

        $returned = self::return_all($march_id);
        $wpdb->update(IDO_DB::t('league_marches'),
            ['status' => IDO_League_Status::RESOLVED, 'outcome' => 'cancelled',
             'resolved_at' => IDO_League::now(),
             'report' => 'Cancelled by the game master.' . ($reason !== '' ? ' ' . $reason : '')],
            ['id' => $march_id]
        );

        IDO_Log::news('league', 'The muster has been cancelled by the game master and every force stood down.');
        IDO_Log::admin('league', sprintf('Cancelled a muster and returned %d pledge(s).', $returned));

        return sprintf('The muster is cancelled and %d pledge(s) returned.', $returned);
    }

    // -- Shared ------------------------------------------------------------

    public static function march(int $march_id): object {
        global $wpdb;
        $league = IDO_League::league();
        $row = $league ? $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('league_marches') . ' WHERE id = %d AND league_id = %d',
            $march_id, (int) $league->id
        )) : null;
        if (!$row) throw new IDO_Game_Exception('No such muster.');
        return $row;
    }

    /**
     * Checks a pledged force against what the empire actually holds.
     *
     * The same shape as a local attack: unknown keys are dropped rather than
     * trusted, quantities are clamped, and a weapon without its crew in the same
     * force is refused rather than quietly sent unmanned.
     *
     * @return array{0:array<string,int>,1:array<string,int>}
     */
    private static function validate_force(object $kingdom, array $force, array $weapons): array {
        $committed = [];
        foreach ($force as $key => $qty) {
            if (!IDO_Units::exists($key)) continue;
            $qty = IDO_Game::qty($qty);
            if ($qty < 1) continue;
            $have = (int) $kingdom->{IDO_Units::column($key)};
            if ($qty > $have) {
                throw new IDO_Game_Exception(sprintf(
                    'You have only %s %s under arms.', IDO_Game::fmt($have), strtolower(IDO_Units::plural($key))
                ));
            }
            $committed[$key] = $qty;
        }
        if (!$committed) throw new IDO_Game_Exception('Pledge at least one soldier.');

        $train = [];
        foreach ($weapons as $key => $qty) {
            if (!IDO_Weapons::exists($key)) continue;
            $qty = IDO_Game::qty($qty);
            if ($qty < 1) continue;
            $have = (int) $kingdom->{IDO_Weapons::column($key)};
            if ($qty > $have) {
                throw new IDO_Game_Exception(sprintf(
                    'You have only %s %s standing.', IDO_Game::fmt($have), strtolower(IDO_Weapons::plural($key))
                ));
            }
            $train[$key] = $qty;
        }

        foreach (IDO_Weapons::crew_needed($train) as $unit => $required) {
            $sending = (int) ($committed[$unit] ?? 0);
            if ($sending >= $required) continue;
            throw new IDO_Game_Exception(sprintf(
                'Your siege train needs %s %s to work it and you are pledging %s.',
                IDO_Game::fmt($required), strtolower(IDO_Units::plural($unit)), IDO_Game::fmt($sending)
            ));
        }

        return [$committed, $train];
    }

    /** A force in words, for a flash message or the gazette. */
    public static function describe_force(array $force, array $weapons = []): string {
        $parts = [];
        foreach ($force as $key => $qty) {
            if ((int) $qty > 0) $parts[] = IDO_Game::fmt($qty) . ' ' . strtolower(IDO_Units::plural($key));
        }
        foreach ($weapons as $key => $qty) {
            if ((int) $qty > 0) $parts[] = IDO_Game::fmt($qty) . ' ' . strtolower(IDO_Weapons::plural($key));
        }
        return $parts ? implode(', ', $parts) : 'nothing';
    }
}
