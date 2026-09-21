<?php
if (!defined('ABSPATH')) exit;
$settings = IDO_Settings::all();

/** Grouped for readability; every key still comes from IDO_Settings::defaults(). */
$groups = [
    'Turns' => ['turns_per_day', 'turn_cap', 'starting_turns', 'attack_turn_cost', 'op_turn_cost'],
    'A new kingdom' => ['starting_land', 'starting_gold', 'starting_grain', 'starting_iron',
        'starting_peasants', 'starting_levies', 'starting_wardens', 'protection_hours'],
    'Land and building' => ['explore_base_acres', 'build_gold_per_acre', 'build_iron_per_acre', 'build_days', 'raze_refund_percent'],
    'War' => ['target_min_percent', 'target_max_percent', 'max_hits_per_target', 'conquest_land_percent'],
    'Covert work' => ['agent_gold_cost', 'max_agents'],
    'Market' => ['market_tax_percent', 'listing_days', 'max_listings_per_kingdom'],
    'Rounds and housekeeping' => ['round_days', 'auto_start_next_round', 'news_retention_days', 'allow_new_kingdoms'],
];

$help = [
    'turns_per_day'          => 'Turns granted to every kingdom on the daily tick.',
    'turn_cap'               => 'The most turns a kingdom can have stored at once.',
    'attack_turn_cost'       => 'Turns spent on one march.',
    'op_turn_cost'           => 'Turns spent on one covert mission.',
    'protection_hours'       => 'Hours of crown truce a new kingdom gets. Marching on someone ends it early.',
    'explore_base_acres'     => 'Acres a small kingdom finds per exploration; the yield falls as the kingdom grows.',
    'build_days'             => 'Days before ordered buildings stand. Zero means they finish on the next daily tick.',
    'target_min_percent'     => 'Lowest net worth, as a percentage of your own, that you may attack.',
    'target_max_percent'     => 'Highest net worth, as a percentage of your own, that you may attack.',
    'max_hits_per_target'    => 'Times one kingdom may attack the same rival in a day. Zero removes the limit.',
    'conquest_land_percent'  => 'Share of the defender land a successful conquest takes, before the strength modifier.',
    'agent_gold_cost'        => 'Gold to hire an agent. Deliberately steep.',
    'max_agents'             => 'Agents one kingdom may keep. One is the intended limit.',
    'market_tax_percent'     => 'Cut the crown takes from every sale.',
    'auto_start_next_round'  => '1 opens the next round automatically when one ends, 0 waits for you.',
    'allow_new_kingdoms'       => '1 lets players claim kingdoms, 0 closes the rolls.',
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
                        Every kingdom on this site belongs to one dominion. Leave this blank and it is named
                        after your WordPress site: currently <strong><?php echo esc_html(IDO_Game::dominion()); ?></strong>.
                        Rename the site and the world follows. When kingdoms on separate sites eventually make
                        war, this is the name yours will be known by.
                    </p>
                </td>
            </tr>
        </table>

        <p><button type="submit" class="button button-primary">Save settings</button></p>
    </form>
</div>
