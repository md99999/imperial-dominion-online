<?php
/*
Plugin Name: Imperial Dominion Online
Plugin URI: https://maddogproductions.online/
Author: Bill Mantz
Author URI: https://maddogproductions.online/
Description: Imperial Dominion Online: a turn-based empire building and conquest game for WordPress. Claim land, raise an empire, trade on the open market and make war on rival empires, a few turns at a time each day. Played through ordinary WordPress pages using shortcodes.
Version: 2.22.0
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

/*
 * A second copy of this plugin, loading after another one.
 *
 * This happens for a reason that is nobody's fault: GitHub's Download ZIP button
 * produces a folder called imperial-dominion-online-main, and WordPress installs
 * it under that name. Install the proper release later and the site holds two
 * plugins, both activatable, with different folder names and no obvious sign
 * that they are the same thing.
 *
 * It does not crash, which is the problem. IDO_PATH is a constant, so the second
 * copy's define() is ignored and its require_once calls resolve to the first
 * copy's files, which are already loaded. The second copy quietly becomes inert
 * and whichever WordPress loaded first is the code that runs -- so a game master
 * can install a release to fix something, see the old behaviour continue, and
 * have nothing to go on.
 *
 * So it says so, and stops. Returning here is what keeps it harmless: without it
 * this file would re-register every hook against the other copy's paths.
 */
if (defined('IDO_VERSION')) {
    // A second copy, loading after another one. It cannot simply carry on:
    // IDO_PATH is already defined, so its requires would resolve to the other
    // copy's files and its hooks would register against the other copy's paths.
    //
    // It does not warn here. IDO_Health finds a second copy by looking at
    // wp-content/plugins, which catches one that is merely installed as well as
    // one that is running, and says so where an administrator is already
    // looking. All this has to do is stand down.
    return;
}

define('IDO_VERSION', '2.22.0');
define('IDO_DB_VERSION', '14');   // 14: leagues.resync_required and its note
define('IDO_FILE', __FILE__);
define('IDO_PATH', plugin_dir_path(__FILE__));
define('IDO_URL', plugin_dir_url(__FILE__));

require_once IDO_PATH . 'includes/class-ido-core.php';
require_once IDO_PATH . 'includes/class-ido-health.php';
require_once IDO_PATH . 'includes/class-ido-installer.php';
require_once IDO_PATH . 'includes/class-ido-menu.php';
require_once IDO_PATH . 'includes/data/class-ido-buildings.php';
require_once IDO_PATH . 'includes/data/class-ido-units.php';
require_once IDO_PATH . 'includes/data/class-ido-weapons.php';
require_once IDO_PATH . 'includes/services/class-round-service.php';
require_once IDO_PATH . 'includes/services/class-kingdom-service.php';
require_once IDO_PATH . 'includes/services/class-barbarian-service.php';
require_once IDO_PATH . 'includes/services/class-disaster-service.php';
require_once IDO_PATH . 'includes/services/class-economy-service.php';
require_once IDO_PATH . 'includes/services/class-construction-service.php';
require_once IDO_PATH . 'includes/services/class-military-service.php';
require_once IDO_PATH . 'includes/services/class-covert-service.php';
require_once IDO_PATH . 'includes/services/class-market-service.php';
require_once IDO_PATH . 'includes/services/class-rankings-service.php';
require_once IDO_PATH . 'includes/services/class-board-service.php';
require_once IDO_PATH . 'includes/services/class-maintenance-service.php';
// League play. The classes load always, because the admin screen has to be
// able to offer the opt in; nothing they do runs until a game master turns it
// on, and the REST endpoint is registered only once this site is in a league.
require_once IDO_PATH . 'includes/league/class-ido-league-crypto.php';
require_once IDO_PATH . 'includes/league/class-ido-league-url.php';
require_once IDO_PATH . 'includes/league/class-ido-league-packet.php';
require_once IDO_PATH . 'includes/league/class-ido-league.php';
require_once IDO_PATH . 'includes/league/class-ido-league-setup.php';
require_once IDO_PATH . 'includes/league/class-ido-league-http.php';
require_once IDO_PATH . 'includes/league/class-ido-league-enrol.php';
require_once IDO_PATH . 'includes/league/class-ido-league-covert.php';
require_once IDO_PATH . 'includes/league/class-ido-league-share.php';
require_once IDO_PATH . 'includes/league/class-ido-league-battle.php';
require_once IDO_PATH . 'includes/league/class-ido-league-march.php';
require_once IDO_PATH . 'includes/league/class-ido-league-muster.php';
require_once IDO_PATH . 'includes/league/class-ido-league-table.php';
require_once IDO_PATH . 'includes/league/class-ido-league-status.php';
require_once IDO_PATH . 'includes/league/class-ido-league-queue.php';
require_once IDO_PATH . 'includes/league/class-ido-league-news.php';
require_once IDO_PATH . 'includes/league/class-ido-league-endpoint.php';
require_once IDO_PATH . 'includes/frontend/class-ido-ui.php';
require_once IDO_PATH . 'includes/frontend/class-ido-actions.php';
require_once IDO_PATH . 'includes/frontend/class-ido-shortcodes.php';

register_activation_hook(__FILE__, ['IDO_Installer', 'activate']);
register_deactivation_hook(__FILE__, ['IDO_Installer', 'deactivate']);

add_action('plugins_loaded', ['IDO_Installer', 'maybe_upgrade']);
// Renaming pages writes posts, which is not safe this early: see the note on
// maybe_rename_pages.
add_action('admin_init', ['IDO_Installer', 'maybe_rename_pages']);
add_action('plugins_loaded', ['IDO_Maintenance', 'sync_timezone']);
add_action('init', ['IDO_Shortcodes', 'register']);
add_action('init', ['IDO_Menu', 'init']);
// Registers nothing unless this site is in a league and the game master has
// opened the endpoint: see IDO_League_Endpoint::register().
IDO_League_Endpoint::init();
add_action('template_redirect', ['IDO_Actions', 'handle']);
add_action('wp_enqueue_scripts', ['IDO_UI', 'enqueue_assets']);
add_action(IDO_Maintenance::HOURLY_HOOK, ['IDO_Maintenance', 'hourly']);
add_action(IDO_Maintenance::DAILY_HOOK, ['IDO_Maintenance', 'daily']);

if (is_admin()) {
    require_once IDO_PATH . 'admin/class-ido-admin.php';
    IDO_Admin::init();
}
