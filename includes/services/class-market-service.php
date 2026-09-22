<?php
if (!defined('ABSPATH')) exit;

/**
 * The open market. Rulers set their own prices, which makes grain and iron
 * worth as much as the war does. Goods are escrowed the moment a lot is posted,
 * so a seller can never sell stock they have already spent.
 */
class IDO_Market {

    /** Everything that may be traded, and the column it lives in. */
    public static function tradeable(): array {
        $items = [
            'grain'      => ['kind' => 'resource', 'label' => 'Grain',      'column' => 'grain'],
            'iron'       => ['kind' => 'resource', 'label' => 'Iron',       'column' => 'iron'],
        ];
        foreach (IDO_Units::all() as $key => $unit) {
            $items[$key] = ['kind' => 'unit', 'label' => $unit['plural'], 'column' => IDO_Units::column($key)];
        }
        return $items;
    }

    public static function item(string $key): array {
        $items = self::tradeable();
        if (!isset($items[$key])) {
            throw new IDO_Game_Exception('That cannot be traded on the market.');
        }
        return $items[$key];
    }

    /**
     * What a unit of something is roughly worth, in gold.
     *
     * Derived from what the kingdom gives up to have it, using the counting
     * house as the yardstick: a counting house earns 60 gold a turn, so a
     * farmstead's 85 grain a turn is worth about 60 gold, and a foundry's 25
     * iron about the same. Troops are priced at what training them costs,
     * before any barracks discount, with their iron valued the same way.
     *
     * This is a reference point for a seller, not a price the game enforces.
     * Rulers set their own prices and always have.
     */
    public static function reference_price(string $key): float {
        $gold_per_turn = 60.0;                      // one counting house
        $iron_value = $gold_per_turn / 25.0;        // one foundry

        if ($key === 'grain')      return $gold_per_turn / 85.0;
        if ($key === 'iron')       return $iron_value;
        if ($key === 'runestones') return 0.0;      // no longer traded

        if (IDO_Units::exists($key)) {
            $unit = IDO_Units::get($key);
            return (float) $unit['gold'] + ((float) $unit['iron'] * $iron_value);
        }
        return 0.0;
    }

    /**
     * A suggested asking range for one item, plus what the market is already
     * charging, so a seller can price to sell rather than guess.
     *
     * @return array{floor:int,ceiling:int,cheapest:?int,lots:int}
     */
    public static function price_guide(int $round_id, string $key): array {
        global $wpdb;

        $reference = self::reference_price($key);
        $floor   = max(1, (int) round($reference));
        $ceiling = max($floor + 1, (int) round($reference * 1.75));

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT MIN(unit_price) AS cheapest, COUNT(*) AS lots FROM ' . IDO_DB::t('listings')
            . ' WHERE round_id = %d AND status = %s AND item_key = %s AND qty > 0',
            $round_id, 'open', $key
        ));

        return [
            'floor'    => $floor,
            'ceiling'  => $ceiling,
            'cheapest' => $row && $row->cheapest !== null ? (int) $row->cheapest : null,
            'lots'     => $row ? (int) $row->lots : 0,
        ];
    }

    /** The price guide as one short line for the posting form. */
    public static function price_hint(int $round_id, string $key): string {
        $guide = self::price_guide($round_id, $key);
        $line = sprintf('Worth about %s to %s gold each.',
            IDO_Game::fmt($guide['floor']), IDO_Game::fmt($guide['ceiling']));

        if ($guide['cheapest'] !== null) {
            $line .= sprintf(' Cheapest open lot: %s gold, so undercut that to sell first.',
                IDO_Game::fmt($guide['cheapest']));
        } else {
            $line .= ' Nothing else is on sale, so you set the price.';
        }
        return $line;
    }

    /** Open lots, newest first, optionally filtered to one item. */
    public static function listings(int $round_id, string $filter = '', int $limit = 100): array {
        global $wpdb;
        $sql = 'SELECT l.*, r.kingdom_name, r.ruler_name FROM ' . IDO_DB::t('listings') . ' l'
            . ' LEFT JOIN ' . IDO_DB::t('kingdoms') . ' r ON r.id = l.seller_kingdom_id'
            . ' WHERE l.round_id = %d AND l.status = %s';
        $args = [$round_id, 'open'];
        if ($filter !== '' && isset(self::tradeable()[$filter])) {
            $sql .= ' AND l.item_key = %s';
            $args[] = $filter;
        }
        $sql .= ' ORDER BY l.unit_price ASC, l.id ASC LIMIT %d';
        $args[] = $limit;
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    public static function my_listings(object $kingdom): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('listings') . ' WHERE seller_kingdom_id = %d AND status = %s ORDER BY id DESC',
            (int) $kingdom->id, 'open'
        ));
    }

    /** Posts a lot for sale, escrowing the goods immediately. */
    public static function post(object $kingdom, string $item_key, int $qty, int $unit_price): string {
        global $wpdb;

        $item = self::item($item_key);
        $qty = IDO_Game::qty($qty, 1000000000);
        $unit_price = IDO_Game::qty($unit_price, 100000000);
        if ($qty < 1) {
            throw new IDO_Game_Exception('Offer at least one of something.');
        }
        if ($unit_price < 1) {
            throw new IDO_Game_Exception('Set a price of at least one gold.');
        }

        $open = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('listings') . ' WHERE seller_kingdom_id = %d AND status = %s',
            (int) $kingdom->id, 'open'
        ));
        $max = IDO_Settings::int('max_listings_per_kingdom');
        if ($max > 0 && $open >= $max) {
            throw new IDO_Game_Exception(sprintf('You may keep only %d lots on the market at once.', $max));
        }

        if ((int) $kingdom->{$item['column']} < $qty) {
            throw new IDO_Game_Exception(sprintf(
                'You have only %s %s to sell.',
                IDO_Game::fmt($kingdom->{$item['column']}), strtolower($item['label'])
            ));
        }

        IDO_Kingdom::pay($kingdom, [$item['column'] => -$qty], 'You no longer hold that stock.');
        $days = max(1, IDO_Settings::int('listing_days'));
        $wpdb->insert(IDO_DB::t('listings'), [
            'round_id'        => (int) $kingdom->round_id,
            'seller_kingdom_id' => (int) $kingdom->id,
            'item_kind'       => $item['kind'],
            'item_key'        => $item_key,
            'qty'             => $qty,
            'unit_price'      => $unit_price,
            'status'          => 'open',
            'created_at'      => IDO_Game::now(),
            'expires_at'      => date('Y-m-d H:i:s', current_time('timestamp') + $days * DAY_IN_SECONDS),
        ], ['%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        return sprintf(
            '%s %s posted at %s gold each. The lot expires in %d %s if unsold.',
            IDO_Game::fmt($qty), strtolower($item['label']), IDO_Game::fmt($unit_price),
            $days, $days === 1 ? 'day' : 'days'
        );
    }

    /** Buys part or all of a lot. Gold moves, less the market tax. */
    public static function buy(object $kingdom, int $listing_id, int $qty): string {
        global $wpdb;

        $qty = IDO_Game::qty($qty, 1000000000);
        if ($qty < 1) {
            throw new IDO_Game_Exception('Buy at least one of something.');
        }
        if (!IDO_Lock::acquire('listing_' . $listing_id, 8)) {
            throw new IDO_Game_Exception('Another buyer is at that stall. Try again in a moment.');
        }

        try {
            $listing = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . IDO_DB::t('listings') . ' WHERE id = %d', $listing_id
            ));
            if (!$listing || $listing->status !== 'open' || (int) $listing->round_id !== (int) $kingdom->round_id) {
                throw new IDO_Game_Exception('That lot is no longer on the market.');
            }
            if ((int) $listing->seller_kingdom_id === (int) $kingdom->id) {
                throw new IDO_Game_Exception('You cannot buy your own lot. Withdraw it instead.');
            }
            if ($qty > (int) $listing->qty) {
                throw new IDO_Game_Exception(sprintf('Only %s remain in that lot.', IDO_Game::fmt($listing->qty)));
            }

            $item = self::item($listing->item_key);
            $total = $qty * (int) $listing->unit_price;
            if ((int) $kingdom->gold < $total) {
                throw new IDO_Game_Exception(sprintf(
                    'That costs %s gold and you have %s.', IDO_Game::fmt($total), IDO_Game::fmt($kingdom->gold)
                ));
            }

            // Take the buyer gold and hand over the goods.
            IDO_Kingdom::pay($kingdom, ['gold' => -$total, $item['column'] => $qty], 'Your treasury cannot cover that.');

            $remaining = (int) $listing->qty - $qty;
            $wpdb->update(IDO_DB::t('listings'),
                ['qty' => $remaining, 'status' => $remaining > 0 ? 'open' : 'sold'],
                ['id' => (int) $listing->id, 'status' => 'open'],
                ['%d', '%s'], ['%d', '%s']
            );

            $tax = (int) round($total * IDO_Settings::int('market_tax_percent') / 100);
            $seller = IDO_Kingdom::find((int) $listing->seller_kingdom_id);
            if ($seller) {
                IDO_Kingdom::pay($seller, ['gold' => $total - $tax]);
                IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($seller));
            }
            IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

            return sprintf(
                'Bought %s %s for %s gold.',
                IDO_Game::fmt($qty), strtolower($item['label']), IDO_Game::fmt($total)
            );
        } finally {
            IDO_Lock::release('listing_' . $listing_id);
        }
    }

    /** Withdraws an unsold lot and returns the escrowed goods. */
    public static function withdraw(object $kingdom, int $listing_id): string {
        global $wpdb;

        $listing = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('listings') . ' WHERE id = %d AND seller_kingdom_id = %d',
            $listing_id, (int) $kingdom->id
        ));
        if (!$listing || $listing->status !== 'open') {
            throw new IDO_Game_Exception('That lot is not yours to withdraw.');
        }

        // Only return the goods if this request is the one that closed the lot.
        $closed = $wpdb->update(IDO_DB::t('listings'),
            ['status' => 'withdrawn'],
            ['id' => (int) $listing->id, 'status' => 'open'],
            ['%s'], ['%d', '%s']
        );
        if (!$closed) {
            throw new IDO_Game_Exception('That lot has just been sold.');
        }

        $item = self::item($listing->item_key);
        IDO_Kingdom::pay($kingdom, [$item['column'] => (int) $listing->qty]);
        IDO_Kingdom::recalc_networth(IDO_Kingdom::reload($kingdom));

        return sprintf('%s %s returned to your stores.', IDO_Game::fmt($listing->qty), strtolower($item['label']));
    }

    /**
     * Returns the goods from every lot that has run out of time.
     * Called from the hourly tick.
     */
    public static function expire(int $round_id): int {
        global $wpdb;
        $due = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('listings')
            . ' WHERE round_id = %d AND status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
            $round_id, 'open', IDO_Game::now()
        ));
        $count = 0;
        foreach ($due as $listing) {
            $closed = $wpdb->update(IDO_DB::t('listings'),
                ['status' => 'expired'],
                ['id' => (int) $listing->id, 'status' => 'open'],
                ['%s'], ['%d', '%s']
            );
            if (!$closed) continue;
            $seller = IDO_Kingdom::find((int) $listing->seller_kingdom_id);
            if ($seller && isset(self::tradeable()[$listing->item_key])) {
                $item = self::item($listing->item_key);
                IDO_Kingdom::pay($seller, [$item['column'] => (int) $listing->qty]);
            }
            $count++;
        }
        return $count;
    }

    /**
     * Returns escrowed goods for every open lot belonging to a kingdom. Used when
     * a round is wound up so nothing is left in limbo.
     */
    public static function close_round(int $round_id): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . IDO_DB::t('listings') . ' SET status = %s WHERE round_id = %d AND status = %s',
            'expired', $round_id, 'open'
        ));
    }
}
