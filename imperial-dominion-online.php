<?php
/*
Plugin Name: Imperial Dominion Online
Plugin URI: https://maddogproductions.online/
Author: Bill Mantz
Author URI: https://maddogproductions.online/
Description: Imperial Dominion Online: a turn-based empire building and conquest game for WordPress. Claim land, raise an empire, trade on the open market and make war on rival empires, a few turns at a time each day. Played through ordinary WordPress pages using shortcodes.
Version: 2.19.1
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
    $GLOBALS['ido_extra_copies'][] = plugin_dir_path(__FILE__);

    if (!function_exists('ido_notice_extra_copies')) {
        function ido_notice_extra_copies(): void {
            if (!current_user_can('activate_plugins')) return;

            $copies = array_unique((array) ($GLOBALS['ido_extra_copies'] ?? []));
            if (!$copies) return;

            echo '<div class="notice notice-error"><p><strong>'
                . 'Imperial Dominion Online is installed more than once.</strong></p>';
            echo '<p>The copy actually running is the one in <code>'
                . esc_html(IDO_PATH) . '</code>, version ' . esc_html(IDO_VERSION)
                . '. These are also active and are doing nothing:</p><ul style="list-style:disc;margin-left:22px">';
            foreach ($copies as $copy) {
                echo '<li><code>' . esc_html($copy) . '</code></li>';
            }
            echo '</ul><p>Deactivate and delete the ones you do not want, under '
                . '<a href="' . esc_url(admin_url('plugins.php')) . '">Plugins</a>. '
                . 'Until then, changes you install may appear to do nothing, because the '
                . 'copy you updated may not be the copy that is running.</p></div>';
        }
        add_action('admin_notices', 'ido_notice_extra_copies');
    }
    return;
}

define('IDO_VERSION', '2.19.1');
define('IDO_DB_VERSION', '14');   // 14: leagues.resync_required and its note
define('IDO_FILE', __FILE__);
define('IDO_PATH', plugin_dir_path(__FILE__));
define('IDO_URL', plugin_dir_url(__FILE__));

/*
 * Installed from a repository archive rather than a release.
 *
 * Said plainly in the admin, on every screen, because the people most likely to
 * install a source archive are the ones least likely to have read the page that
 * explains why not to.
 *
 * Every marker is looked for rather than assumed from one of them. An earlier
 * version keyed the whole notice on tests/ and only *mentioned* .git in the
 * wording, which left the most dangerous directory of the four as the one thing
 * nobody actually checked for -- and it is the one that can be there on its own,
 * because deleting the test suite by hand is exactly what a tidy-minded person
 * does before thinking about the dot-directory they cannot see in their file
 * manager.
 */
if (is_admin()) {
    $ido_strays = array_values(array_filter(
        ['.git', 'tests', 'tools', 'docs'],
        static fn($dir) => is_dir(IDO_PATH . $dir)
    ));

    if ($ido_strays) {
        add_action('admin_notices', static function () use ($ido_strays): void {
            if (!current_user_can('activate_plugins')) return;

            // .git is a different order of problem from a stray test suite: it
            // holds every version of every file ever committed, including any
            // that were committed by mistake and removed later.
            $has_git = in_array('.git', $ido_strays, true);

            echo '<div class="notice notice-' . ($has_git ? 'error' : 'warning') . '"><p><strong>'
                . 'Imperial Dominion Online was installed from a source archive, not a release.'
                . '</strong></p>';

            echo '<p>No part of the plugin needs these, and they are sitting in your site at '
                . '<code>' . esc_html(IDO_PATH) . '</code>: <code>'
                . implode('</code>, <code>', array_map('esc_html', $ido_strays))
                . '</code>.</p>';

            if ($has_git) {
                echo '<p><strong>Delete <code>.git</code> first.</strong> It holds every version of '
                    . 'every file the project has ever had, and on a web server that is not '
                    . 'configured to refuse it, anybody can read the lot.</p>';
            }

            // "The rest" only means something when there is a rest: .git can be
            // the only stray, and usually is once somebody has tidied by hand.
            if (count($ido_strays) > ($has_git ? 1 : 0)) {
                echo '<p>The others will not run &mdash; every file in them refuses to execute '
                    . 'outside a command line &mdash; but they are readable and they are dead '
                    . 'weight.</p>';
            }

            echo '<p>Delete them where they sit, or replace this install with a built release, '
                . 'which contains none of them.</p></div>';
        });
    }
}

require_once IDO_PATH . 'includes/class-ido-core.php';
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
