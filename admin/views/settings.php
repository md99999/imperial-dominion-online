<?php
if (!defined('ABSPATH')) exit;
$settings = IDO_Settings::all();

/** Grouped for readability; every key still comes from IDO_Settings::defaults(). */
$groups = [
    'Turns' => ['turns_per_day', 'turn_cap', 'starting_turns', 'attack_turn_cost', 'op_turn_cost'],
    'A new empire' => ['starting_land', 'starting_gold', 'starting_grain', 'starting_iron',
        'starting_peasants', 'starting_pawns', 'starting_legionnaires', 'protection_hours'],
    'Land and building' => ['explore_base_acres', 'build_gold_per_acre', 'build_iron_per_acre', 'build_days', 'demolish_refund_percent'],
    'Siege engines' => ['catapult_gold_cost', 'catapult_iron_cost', 'catapult_capture_percent', 'catapult_destroy_percent'],
    'War' => ['target_min_percent', 'target_max_percent', 'max_hits_per_target', 'conquest_land_percent'],
    'Covert work' => ['agent_gold_cost', 'max_agents'],
    'Market' => ['market_tax_percent', 'listing_days', 'max_listings_per_kingdom'],
    'Rounds and housekeeping' => ['round_days', 'auto_start_next_round', 'news_retention_days', 'allow_new_kingdoms', 'use_wp_cron'],
];

$help = [
    'turns_per_day'          => 'Turns granted to every empire on the daily tick.',
    'turn_cap'               => 'The most turns an empire can have stored at once.',
    'attack_turn_cost'       => 'Turns spent on one march.',
    'op_turn_cost'           => 'Turns spent on one covert mission.',
    'protection_hours'       => 'Hours of crown truce a new empire gets. Marching on someone ends it early.',
    'explore_base_acres'     => 'Acres a small empire finds per exploration; the yield falls as the empire grows.',
    'build_days'             => 'Days before ordered buildings stand. Zero means they finish on the next daily tick.',
    'target_min_percent'     => 'Lowest net worth, as a percentage of your own, that you may attack.',
    'target_max_percent'     => 'Highest net worth, as a percentage of your own, that you may attack.',
    'max_hits_per_target'    => 'Times one empire may attack the same rival in a day. Zero removes the limit.',
    'conquest_land_percent'  => 'Share of the defender land a successful conquest takes, before the strength modifier.',
    'catapult_gold_cost'     => 'Gold to build one catapult. Catapults stand on no acre and cost no peasants.',
    'catapult_iron_cost'     => 'Iron to build one catapult.',
    'catapult_capture_percent' => 'Share of the losing side catapults at stake that the winner drags home.',
    'catapult_destroy_percent' => 'Share of the losing side catapults at stake that is smashed outright. Added to the captured share, this is what a defeat costs in engines.',
    'agent_gold_cost'        => 'Gold to hire an agent. Deliberately steep.',
    'max_agents'             => 'Agents one empire may keep. One is the intended limit.',
    'market_tax_percent'     => 'Cut the crown takes from every sale.',
    'auto_start_next_round'  => '1 opens the next round automatically when one ends, 0 waits for you.',
    'allow_new_kingdoms'       => '1 lets players claim empires, 0 closes the rolls.',
    'use_wp_cron'            => 'Leave at 1 unless a real cron calls the plugin scripts directly. A cron that fetches wp-cron.php by URL still needs this on, because it runs the events that are scheduled, and 0 schedules none.',
];
?>
<div class="wrap">
    <h1>Settings</h1>
    <?php IDO_Admin::notice(); ?>

    <?php echo IDO_Admin::form_open('save_settings', 'ido_settings'); ?>
        <?php foreach ($groups as $title => $keys) : ?>
            <h2><?php echo esc_html($title); ?></h2>
            <table class="form-table" role="presentation">
                <?php foreach ($keys as $key) : ?>
                    <tr>
                        <th scope="row">
                            <label for="ido_<?php echo esc_attr($key); ?>">
                                <?php echo esc_html(ucfirst(str_replace('_', ' ', $key))); ?>
                            </label>
                        </th>
                        <td>
                            <input type="number" min="0" step="1"
                                   id="ido_<?php echo esc_attr($key); ?>"
                                   name="settings[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr((string) $settings[$key]); ?>">
                            <?php if (!empty($help[$key])) : ?>
                                <p class="description"><?php echo esc_html($help[$key]); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>

        <h2>Naming</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ido_dominion_name">World name</label></th>
                <td>
                    <input type="text" id="ido_dominion_name" name="settings[dominion_name]" class="regular-text"
                           value="<?php echo esc_attr((string) $settings['dominion_name']); ?>"
                           placeholder="<?php echo esc_attr(IDO_Game::dominion()); ?>">
                    <p class="description">
                        Every empire on this site belongs to one dominion. Leave this blank and it is named
                        after your WordPress site: currently <strong><?php echo esc_html(IDO_Game::dominion()); ?></strong>.
                        Rename the site and the world follows. When empires on separate sites eventually make
                        war, this is the name yours will be known by.
                    </p>
                </td>
            </tr>
        </table>

        <h2>Footer</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ido_footer_link_text">Credit text</label></th>
                <td>
                    <input type="text" id="ido_footer_link_text" name="settings[footer_link_text]" class="regular-text"
                           value="<?php echo esc_attr((string) $settings['footer_link_text']); ?>">
                    <p class="description">Shown on the right of the bar along the bottom of every game screen. Leave blank to show nothing.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ido_footer_link_url">Credit link</label></th>
                <td>
                    <input type="url" id="ido_footer_link_url" name="settings[footer_link_url]" class="regular-text"
                           value="<?php echo esc_attr((string) $settings['footer_link_url']); ?>"
                           placeholder="https://example.com">
                    <p class="description">Where the credit points. Leave blank and the text is shown without a link.</p>
                </td>
            </tr>
        </table>

        <h2>Site menu</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ido_menu_location">Assign menu to theme location</label></th>
                <td>
                    <?php $locations = IDO_Menu::locations(); ?>
                    <?php if (!$locations) : ?>
                        <p><em>This theme registers no menu locations, so there is nowhere to assign one.</em></p>
                        <input type="hidden" name="settings[menu_location]" value="">
                    <?php else : ?>
                        <select id="ido_menu_location" name="settings[menu_location]">
                            <option value=""><?php echo esc_html('Do not assign'); ?></option>
                            <?php foreach ($locations as $slug => $label) : ?>
                                <option value="<?php echo esc_attr($slug); ?>"
                                    <?php selected($slug, (string) $settings['menu_location']); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <p class="description">
                        Creates a menu called <strong><?php echo esc_html(IDO_Menu::MENU_NAME); ?></strong> holding
                        only the game's front page, and hangs it on the location you choose. The other game pages are
                        never added: the game carries its own navigation across the top of every screen, so the site
                        needs one way in, not nine.
                    </p>
                    <p class="description">
                        Choosing <em>Do not assign</em> releases the location but leaves the menu itself alone, so
                        anything you have since added to it by hand survives. The game's other pages are kept out of
                        menus a theme builds automatically from the page list, whatever you choose here.
                    </p>
                </td>
            </tr>
        </table>

        <h2>Deleting the plugin</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Game data</th>
                <td>
                    <?php /* The hidden field is what lets the box be turned back off: an unchecked box posts nothing. */ ?>
                    <input type="hidden" name="settings[delete_data_on_uninstall]" value="0">
                    <label for="ido_delete_data_on_uninstall">
                        <input type="checkbox" id="ido_delete_data_on_uninstall"
                               name="settings[delete_data_on_uninstall]" value="1"
                               <?php checked(1, (int) $settings['delete_data_on_uninstall']); ?>>
                        Delete every game table when this plugin is deleted
                    </label>
                    <p class="description">
                        Off by default, and deliberately so. Leave it off and deleting the plugin keeps every
                        empire, round, battle and setting, so reinstalling picks the game up exactly where it
                        stopped. Deactivating the plugin never touches the data either way.
                    </p>
                    <p class="description">
                        <strong>Turn it on only when you want the game gone for good.</strong> Deleting then drops
                        <?php echo esc_html((string) count(IDO_DB::TABLES)); ?> tables, including the Hall of Fame
                        of every completed round. There is no undo and no export.
                    </p>
                </td>
            </tr>
        </table>

        <p><button type="submit" class="button button-primary">Save settings</button></p>
    </form>
</div>
