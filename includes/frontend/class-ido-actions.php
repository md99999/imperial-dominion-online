<?php
if (!defined('ABSPATH')) exit;

/**
 * Every game form runs through here: POST, verify the nonce, act, redirect,
 * show the result as a flash notice. Services throw IDO_Game_Exception when a
 * command cannot be carried out; nothing else reaches the player.
 */
class IDO_Actions {

    public static function handle(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['ido_action'])) return;
        if (!is_user_logged_in()) return;

        $redirect = self::current_page_url();

        if (!isset($_POST['ido_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ido_nonce'])), 'ido_action')) {
            IDO_UI::flash('error', 'Your session expired. Please try that again.');
            wp_safe_redirect($redirect);
            exit;
        }

        $action = sanitize_key(wp_unslash($_POST['ido_action']));
        $kingdom = IDO_Kingdom::current();

        try {
            if (!$kingdom && $action !== 'found_kingdom') {
                throw new IDO_Game_Exception('Claim an empire before you give orders.');
            }
            if ($kingdom) {
                IDO_Maintenance::catch_up($kingdom);
                $kingdom = IDO_Kingdom::reload($kingdom);
                IDO_Kingdom::touch($kingdom);
            }
            [$messages, $goto] = self::dispatch($action, $kingdom);
            foreach ((array) $messages as $message) {
                if (is_array($message)) {
                    IDO_UI::flash((string) $message[0], (string) $message[1]);
                } else {
                    IDO_UI::flash('success', (string) $message);
                }
            }
            if ($goto) $redirect = IDO_UI::url($goto);
        } catch (IDO_Game_Exception $e) {
            IDO_UI::flash($e->type, $e->getMessage());
        }

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Where an order returns to: the page it was given on.
     *
     * Not wp_get_referer(). Every game form posts to its own page, and
     * wp_get_referer() deliberately returns false when the referer matches the
     * current request, so relying on it sent every order back to the Empire
     * Room instead of leaving the ruler where they were working.
     *
     * The queried page is the reliable answer, since each game screen is an
     * ordinary WordPress page holding one shortcode.
     */
    private static function current_page_url(): string {
        $page_id = get_queried_object_id();
        $url = $page_id ? get_permalink($page_id) : '';
        if (!$url) {
            $url = wp_get_referer() ?: IDO_UI::url('empire');
        }

        // Carry the view the ruler was looking at, so buying from a filtered
        // market or targeting an empire does not reset the screen.
        $carry = [];
        foreach (['item', 'target'] as $key) {
            if (!empty($_GET[$key])) {
                $carry[$key] = sanitize_key(wp_unslash($_GET[$key]));
            }
        }
        return $carry ? add_query_arg($carry, $url) : $url;
    }

    private static function field(string $name, string $default = '') {
        return isset($_POST[$name]) ? wp_unslash($_POST[$name]) : $default;
    }

    private static function text(string $name): string {
        return sanitize_text_field((string) self::field($name));
    }

    private static function key(string $name): string {
        return sanitize_key((string) self::field($name));
    }

    private static function int(string $name): int {
        return (int) self::field($name, '0');
    }

    /** The force committed to an attack, read from one field per unit type. */
    private static function force(): array {
        $force = [];
        foreach (IDO_Units::keys() as $unit_key) {
            $qty = (int) self::field('force_' . $unit_key, '0');
            if ($qty > 0) $force[$unit_key] = $qty;
        }
        return $force;
    }

    /** The siege engines sent with it, read from one field per engine type. */
    private static function train(): array {
        $train = [];
        foreach (IDO_Engines::keys() as $engine_key) {
            $qty = (int) self::field('engine_' . $engine_key, '0');
            if ($qty > 0) $train[$engine_key] = $qty;
        }
        return $train;
    }

    /**
     * @return array [flash messages, page key to redirect to or null to stay put]
     */
    private static function dispatch(string $action, ?object $kingdom): array {
        switch ($action) {
            case 'found_kingdom':
                IDO_Kingdom::create(get_current_user_id(), self::text('kingdom_name'), self::text('ruler_name'));
                return [['Your banner is raised. The empire is yours to rule.'], 'empire'];

            // Land and building
            case 'explore':
                return [IDO_Economy::explore($kingdom), null];
            case 'build':
                return [IDO_Construction::order($kingdom, self::key('building'), self::int('qty')), null];
            case 'demolish':
                return [[IDO_Construction::demolish($kingdom, self::key('building'), self::int('qty'))], null];

            // Siege engines
            case 'build_engine':
                return [IDO_Construction::order_engine($kingdom, self::key('engine'), self::int('qty')), null];
            case 'scrap_engine':
                return [[IDO_Construction::scrap_engine($kingdom, self::key('engine'), self::int('qty'))], null];

            // The army
            case 'train':
                return [IDO_Military::train($kingdom, self::key('unit'), self::int('qty')), null];
            case 'disband':
                return [[IDO_Military::disband($kingdom, self::key('unit'), self::int('qty'))], null];

            // War
            case 'attack':
                return [IDO_Military::attack($kingdom, self::int('target_id'), self::key('attack_type'), self::force(), self::train()), 'war'];

            // The spy court
            case 'hire_agent':
                return [IDO_Covert::hire($kingdom), null];
            case 'run_op':
                return [IDO_Covert::run($kingdom, self::int('target_id'), self::key('op')), 'covert'];

            // Market
            case 'market_post':
                return [[IDO_Market::post($kingdom, self::key('item'), self::int('qty'), self::int('unit_price'))], null];
            case 'market_buy':
                return [[IDO_Market::buy($kingdom, self::int('listing_id'), self::int('qty'))], null];
            case 'market_withdraw':
                return [[IDO_Market::withdraw($kingdom, self::int('listing_id'))], null];
        }
        throw new IDO_Game_Exception('Unknown command.');
    }
}
