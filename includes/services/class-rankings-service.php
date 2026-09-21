<?php
if (!defined('ABSPATH')) exit;

/**
 * Standings, the gazette and the Hall of Fame.
 */
class IDO_Rankings {

    /** Kingdoms in a round, strongest first. */
    public static function standings(int $round_id, int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, user_id, kingdom_name, ruler_name, land, networth, attacks_won, attacks_suffered, is_defeated'
            . ' FROM ' . IDO_DB::t('kingdoms')
            . ' WHERE round_id = %d ORDER BY networth DESC, land DESC, id ASC LIMIT %d',
            $round_id, $limit
        ));
    }

    /** Where a kingdom sits in the standings, counting from one. */
    public static function position(object $kingdom): int {
        global $wpdb;
        $above = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND networth > %d',
            (int) $kingdom->round_id, (int) $kingdom->networth
        ));
        return $above + 1;
    }

    public static function kingdom_count(int $round_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d', $round_id
        ));
    }

    /** Gazette items for a round, newest first. */
    public static function news(int $round_id, int $limit = 50): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d ORDER BY id DESC LIMIT %d',
            $round_id, $limit
        ));
    }

    /** Archived champions from past rounds. */
    public static function hall(int $limit = 50, int $positions = 3): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('hall') . ' WHERE position <= %d ORDER BY round_id DESC, position ASC LIMIT %d',
            $positions, $limit
        ));
    }
}
