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

        $redirect = wp_get_referer() ?: IDO_UI::url('throne');

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
                throw new IDO_Game_Exception('Claim a kingdom before you give orders.');
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

    /**
     * @return array [flash messages, page key to redirect to or null to stay put]
     */
    private static function dispatch(string $action, ?object $kingdom): array {
        switch ($action) {
            case 'found_kingdom':
                IDO_Kingdom::create(get_current_user_id(), self::text('kingdom_name'), self::text('ruler_name'));
                return [['Your banner is raised. The kingdom is yours to rule.'], 'throne'];

            // Land and building
            case 'explore':
                return [IDO_Economy::explore($kingdom), null];
            case 'build':
                return [IDO_Construction::order($kingdom, self::key('building'), self::int('qty')), null];
            case 'demolish':
                return [[IDO_Construction::demolish($kingdom, self::key('building'), self::int('qty'))], null];

            // The army
            case 'train':
                return [IDO_Military::train($kingdom, self::key('unit'), self::int('qty')), null];
            case 'disband':
                return [[IDO_Military::disband($kingdom, self::key('unit'), self::int('qty'))], null];

            // War
            case 'attack':
                return [IDO_Military::attack($kingdom, self::int('target_id'), self::key('attack_type'), self::force()), 'war'];

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
