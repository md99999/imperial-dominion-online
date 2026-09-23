<?php
/*
Plugin Name: Imperial Dominion Online
Plugin URI: https://maddogproductions.online/
Author: Bill Mantz
Author URI: https://maddogproductions.online/
Description: Imperial Dominion Online: a turn-based empire building and conquest game for WordPress. Claim land, raise a kingdom, trade on the open market and make war on rival kingdoms, a few turns at a time each day. Played through ordinary WordPress pages using shortcodes.
Version: 1.13.0
Requires PHP: 8.0
Requires at least: 7.0
Text Domain: imperial-dominion-online
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copyright (C) 2026 Bill Mantz

Imperial Dominion Online is free software: you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software Foundation, either
version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License for more details. A copy is included in LICENSE.
*/
if (!defined('ABSPATH')) exit;

define('IDO_VERSION', '1.13.0');
define('IDO_DB_VERSION', '8');
define('IDO_FILE', __FILE__);
define('IDO_PATH', plugin_dir_path(__FILE__));
define('IDO_URL', plugin_dir_url(__FILE__));

require_once IDO_PATH . 'includes/class-ido-core.php';
require_once IDO_PATH . 'includes/class-ido-installer.php';
require_once IDO_PATH . 'includes/class-ido-menu.php';
require_once IDO_PATH . 'includes/data/class-ido-buildings.php';
require_once IDO_PATH . 'includes/data/class-ido-units.php';
require_once IDO_PATH . 'includes/services/class-round-service.php';
require_once IDO_PATH . 'includes/services/class-kingdom-service.php';
require_once IDO_PATH . 'includes/services/class-economy-service.php';
require_once IDO_PATH . 'includes/services/class-construction-service.php';
require_once IDO_PATH . 'includes/services/class-military-service.php';
require_once IDO_PATH . 'includes/services/class-covert-service.php';
require_once IDO_PATH . 'includes/services/class-market-service.php';
require_once IDO_PATH . 'includes/services/class-rankings-service.php';
require_once IDO_PATH . 'includes/services/class-maintenance-service.php';
require_once IDO_PATH . 'includes/frontend/class-ido-ui.php';
require_once IDO_PATH . 'includes/frontend/class-ido-actions.php';
require_once IDO_PATH . 'includes/frontend/class-ido-shortcodes.php';

register_activation_hook(__FILE__, ['IDO_Installer', 'activate']);
register_deactivation_hook(__FILE__, ['IDO_Installer', 'deactivate']);

add_action('plugins_loaded', ['IDO_Installer', 'maybe_upgrade']);
add_action('init', ['IDO_Shortcodes', 'register']);
add_action('init', ['IDO_Menu', 'init']);
add_action('template_redirect', ['IDO_Actions', 'handle']);
add_action('wp_enqueue_scripts', ['IDO_UI', 'enqueue_assets']);
add_action(IDO_Maintenance::HOURLY_HOOK, ['IDO_Maintenance', 'hourly']);
add_action(IDO_Maintenance::DAILY_HOOK, ['IDO_Maintenance', 'daily']);

if (is_admin()) {
    require_once IDO_PATH . 'admin/class-ido-admin.php';
    IDO_Admin::init();
}
