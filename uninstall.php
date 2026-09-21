<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Removes every Imperial Dominion Online table and option.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;
$tables = ['rounds', 'kingdoms', 'constructions', 'listings', 'battles', 'ops', 'news', 'hall', 'admin_log'];
foreach ($tables as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ido_' . $table);
}
foreach (['ido_settings', 'ido_db_version', 'ido_page_ids', 'ido_last_hourly', 'ido_last_daily', 'ido_activation_error'] as $option) {
    delete_option($option);
}
wp_clear_scheduled_hook('ido_hourly_maintenance');
wp_clear_scheduled_hook('ido_daily_maintenance');
