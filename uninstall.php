<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Whether the game's data goes with it is the game master's decision, made in
 * advance under Settings. The default is to keep everything: deleting a plugin
 * is easy to do by accident, and a round in progress is not recoverable once
 * its tables are dropped. Someone who wants a clean slate has to say so first.
 *
 * The plugin's own code is not loaded during uninstall, so the setting is read
 * straight out of the options table rather than through IDO_Settings.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

// Scheduled events always go: they would fire into nothing.
wp_clear_scheduled_hook('ido_hourly_maintenance');
wp_clear_scheduled_hook('ido_daily_maintenance');

$settings = get_option('ido_settings');
$delete_data = is_array($settings) && !empty($settings['delete_data_on_uninstall']);

if (!$delete_data) {
    // Empires, rounds, battles and settings all stay exactly as they are, so
    // reinstalling picks the game up mid-round.
    return;
}

$tables = ['rounds', 'kingdoms', 'constructions', 'listings', 'battles', 'ops', 'news', 'hall', 'admin_log'];
foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ido_' . $table);
}
foreach (['ido_settings', 'ido_db_version', 'ido_page_ids', 'ido_last_hourly', 'ido_last_daily', 'ido_activation_error', 'ido_cron_timezone'] as $option) {
    delete_option($option);
}
