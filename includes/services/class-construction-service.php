<?php
if (!defined('ABSPATH')) exit;

/**
 * Construction. Building orders are placed against wilderness acres, paid for
 * up front, and finish on the daily tick: what you order today defends you
 * tomorrow, never tonight.
 */
class IDO_Construction {

    /** Orders $qty of a building. Costs one turn plus gold and iron per acre. */
    public static function order(object $kingdom, string $building, int $qty): array {
        global $wpdb;

        if (!IDO_Buildings::exists($building)) {
            throw new IDO_Game_Exception('No such building.');
        }
        $qty = IDO_Game::qty($qty, 1000000);
        if ($qty < 1) {
            throw new IDO_Game_Exception('Order at least one building.');
        }
        $free = IDO_Buildings::wilderness($kingdom);
        if ($qty > $free) {
            throw new IDO_Game_Exception(sprintf(
                'You have %s acres of wilderness free and ordered %s. Settle more land first.',
                IDO_Game::fmt($free), IDO_Game::fmt($qty)
            ));
        }

        $gold = $qty * IDO_Settings::int('build_gold_per_acre');
        $iron = $qty * IDO_Settings::int('build_iron_per_acre');
        if ((int) $kingdom->gold < $gold || (int) $kingdom->iron < $iron) {
            throw new IDO_Game_Exception(sprintf(
                'That order costs %s gold and %s iron. You have %s gold and %s iron.',
                IDO_Game::fmt($gold), IDO_Game::fmt($iron),
                IDO_Game::fmt($kingdom->gold), IDO_Game::fmt($kingdom->iron)
            ));
        }

        $messages = IDO_Kingdom::spend_turns($kingdom, 1);
        $kingdom = IDO_Kingdom::reload($kingdom);
        IDO_Kingdom::pay($kingdom, [
            'gold' => -$gold,
            'iron' => -$iron,
            'land_in_progress' => $qty,
        ], 'Your treasury and forges cannot cover that order.');

        $days = max(0, IDO_Settings::int('build_days'));
        $ready = date('Y-m-d', current_time('timestamp') + $days * DAY_IN_SECONDS);
        $wpdb->insert(IDO_DB::t('constructions'), [
            'round_id'   => (int) $kingdom->round_id,
            'kingdom_id'   => (int) $kingdom->id,
            'building'   => $building,
            'qty'        => $qty,
            'ready_on'   => $ready,
            'created_at' => IDO_Game::now(),
        ], ['%d', '%d', '%s', '%d', '%s', '%s']);

        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));
        $messages[] = sprintf(
            'Work begins on %s %s, at a cost of %s gold and %s iron. They stand on %s.',
            IDO_Game::fmt($qty), strtolower(IDO_Buildings::plural($building)),
            IDO_Game::fmt($gold), IDO_Game::fmt($iron),
            date_i18n(get_option('date_format'), strtotime($ready))
        );
        return $messages;
    }

    /** Tears down standing buildings, returning the acres to wilderness. */
    public static function demolish(object $kingdom, string $building, int $qty): string {
        if (!IDO_Buildings::exists($building)) {
            throw new IDO_Game_Exception('No such building.');
        }
        $column = IDO_Buildings::column($building);
        $standing = (int) $kingdom->{$column};
        $qty = IDO_Game::qty($qty, $standing);
        if ($qty < 1) {
            throw new IDO_Game_Exception('You have none of those standing.');
        }

        $refund = (int) round($qty * IDO_Settings::int('build_gold_per_acre') * IDO_Settings::int('demolish_refund_percent') / 100);
        IDO_Kingdom::pay($kingdom, [$column => -$qty, 'gold' => $refund], 'Those buildings are no longer standing.');
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        return sprintf(
            '%s %s are pulled down. The salvage fetches %s gold and the acres return to wilderness.',
            IDO_Game::fmt($qty), strtolower(IDO_Buildings::plural($building)), IDO_Game::fmt($refund)
        );
    }

    /** Outstanding orders for a kingdom, soonest first. */
    public static function pending(object $kingdom): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('constructions') . ' WHERE kingdom_id = %d ORDER BY ready_on ASC, id ASC',
            (int) $kingdom->id
        ));
    }

    /**
     * Completes every order whose day has come. Called from the daily tick.
     *
     * @return int number of buildings finished
     */
    public static function complete_due(int $round_id): int {
        global $wpdb;
        $today = IDO_Game::today();
        $due = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('constructions') . ' WHERE round_id = %d AND ready_on <= %s ORDER BY id ASC',
            $round_id, $today
        ));
        $finished = 0;
        foreach ($due as $order) {
            if (!IDO_Buildings::exists($order->building)) {
                $wpdb->delete(IDO_DB::t('constructions'), ['id' => (int) $order->id], ['%d']);
                continue;
            }
            $kingdom = IDO_Kingdom::find((int) $order->kingdom_id);
            if (!$kingdom) {
                $wpdb->delete(IDO_DB::t('constructions'), ['id' => (int) $order->id], ['%d']);
                continue;
            }
            $qty = min((int) $order->qty, (int) $kingdom->land_in_progress);
            if ($qty > 0) {
                $wpdb->query($wpdb->prepare(
                    'UPDATE ' . IDO_DB::t('kingdoms')
                    . ' SET `' . IDO_Buildings::column($order->building) . '` = `' . IDO_Buildings::column($order->building) . '` + %d,'
                    . ' land_in_progress = GREATEST(0, land_in_progress - %d) WHERE id = %d',
                    $qty, $qty, (int) $kingdom->id
                ));
                $finished += $qty;
            }
            $wpdb->delete(IDO_DB::t('constructions'), ['id' => (int) $order->id], ['%d']);
        }
        return $finished;
    }

    /**
     * Cancels outstanding orders when land is lost to an invader, so a kingdom can
     * never hold more buildings plus orders than it has acres.
     */
    public static function trim_to_land(object $kingdom): void {
        global $wpdb;
        $kingdom = IDO_Kingdom::reload($kingdom);
        $over = IDO_Buildings::total($kingdom) + (int) $kingdom->land_in_progress - (int) $kingdom->land;
        if ($over <= 0) return;

        $orders = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('constructions') . ' WHERE kingdom_id = %d ORDER BY ready_on DESC, id DESC',
            (int) $kingdom->id
        ));
        foreach ($orders as $order) {
            if ($over <= 0) break;
            $cut = min($over, (int) $order->qty);
            if ($cut >= (int) $order->qty) {
                $wpdb->delete(IDO_DB::t('constructions'), ['id' => (int) $order->id], ['%d']);
            } else {
                $wpdb->update(IDO_DB::t('constructions'), ['qty' => (int) $order->qty - $cut], ['id' => (int) $order->id], ['%d'], ['%d']);
            }
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . IDO_DB::t('kingdoms') . ' SET land_in_progress = GREATEST(0, land_in_progress - %d) WHERE id = %d',
                $cut, (int) $kingdom->id
            ));
            $over -= $cut;
        }
        unset($orders);
    }
}
