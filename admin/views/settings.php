<?php
if (!defined('ABSPATH')) exit;
$settings = IDO_Settings::all();
$round    = IDO_Rounds::current();

/** Grouped for readability; every key still comes from IDO_Settings::defaults(). */
$groups = [
    'Turns' => ['turns_per_day', 'turn_cap', 'starting_turns', 'attack_turn_cost', 'op_turn_cost'],
    'A new empire' => ['starting_land', 'starting_gold', 'starting_grain', 'starting_iron',
        'starting_peasants', 'starting_pawns', 'starting_legionnaires', 'protection_hours'],
    'Land and building' => ['explore_base_acres', 'build_gold_per_acre', 'build_iron_per_acre', 'build_days', 'demolish_refund_percent'],
    'Siege weapons' => ['catapult_gold_cost', 'catapult_iron_cost', 'catapult_crew', 'catapult_capture_percent', 'catapult_destroy_percent'],
    'War' => ['target_min_percent', 'target_max_percent', 'max_hits_per_target', 'conquest_land_percent'],
    'Covert work' => ['agent_gold_cost', 'max_agents'],
    'Barbarians' => ['barbarians_enabled', 'barbarian_min_players', 'barbarian_top_ranks',
        'barbarian_chance_percent', 'barbarian_gold_percent', 'barbarian_grain_percent'],
    'Disasters' => ['disasters_enabled', 'disaster_one_in', 'disaster_percent'],
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
    'catapult_crew'          => 'Legionnaires needed to work one catapult. They must be in the force you send, and at home only the catapults you have crews for count towards defence. Never below one.',
    'catapult_capture_percent' => 'Share of the losing side catapults at stake that the winner drags home.',
    'catapult_destroy_percent' => 'Share of the losing side catapults at stake that is smashed outright. Added to the captured share, this is what a defeat costs in weapons.',
    'agent_gold_cost'        => 'Gold to hire an agent. Deliberately steep.',
    'max_agents'             => 'Agents one empire may keep. One is the intended limit.',
    'disasters_enabled'      => 'Whether drought, insects and floods can strike at all.',
    'disaster_one_in'         => 'Odds of a disaster during any one turn, as one in this many. '
        . 'Never during a crown truce, relief or a board grace period, and never two at once.',
    'disaster_percent'       => 'The share a disaster destroys: farmsteads for a drought, '
        . 'stored grain for insects, homesteads for a flood.',
    'market_tax_percent'     => 'Cut the crown takes from every sale.',
    'barbarians_enabled'       => '1 lets barbarians raid the leading empires, 0 turns them off entirely.',
    'barbarian_min_players'    => 'Barbarians stay away until this many empires are playing. On a small board the top three is most of the board.',
    'barbarian_top_ranks'      => 'How far down the standings they will go. Three keeps it a brake on the leaders.',
    'barbarian_chance_percent' => 'Chance per turn spent that they turn up.',
    'barbarian_gold_percent'   => 'Share of the treasury they carry off.',
    'barbarian_grain_percent'  => 'Share of the granaries they carry off.',
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
                <?php foreach ($keys as $key) :
                    // A setting the league governs is shown as the league's, and
                    // is ignored on save as well as disabled here: a disabled
                    // input is a courtesy to the browser, not a rule.
                    $governed = class_exists('IDO_League') && IDO_League::governs($key);
                ?>
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
                                   value="<?php echo esc_attr((string) $settings[$key]); ?>"
                                   <?php disabled($governed); ?>>
                            <?php if ($governed) : ?>
                                <p class="description">
                                    <strong>Set by the league.</strong> Every member plays the same number,
                                    or a site could grant its own rulers more and win without fighting well.
                                </p>
                            <?php endif; ?>
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

        <h2>Starting over</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ido_board_ruin_percent">Ruined board</label></th>
                <td>
                    <input name="settings[board_ruin_percent]" id="ido_board_ruin_percent" type="number"
                           min="0" max="90" value="<?php echo esc_attr((string) (int) $settings['board_ruin_percent']); ?>"
                           class="small-text">%
                    <p class="description">
                        When every empire together is worth less than this share of what they were founded
                        with, the board is finished and refounds itself on the next daily run. Set it to 0
                        to switch that off and decide yourself.
                    </p>
                    <?php $report = IDO_Board::ruin_report((int) ($round->id ?? 0)); ?>
                    <p class="description">
                        <strong>Now:</strong>
                        <?php echo esc_html(sprintf('%d empires worth %s together; the board is called finished below %s.',
                            $report['empires'], number_format_i18n($report['worth']),
                            number_format_i18n($report['threshold']))); ?>
                        <?php if ($report['ruined']) : ?>
                            <br><strong style="color:#b32d2e">This board is below the line and will refound itself
                            on the next daily run.</strong>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2>League play</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Incoming packets</th>
                <td>
                    <?php $locked = defined('IDO_LEAGUE_DISABLE_ENDPOINT') && IDO_LEAGUE_DISABLE_ENDPOINT; ?>
                    <input type="hidden" name="settings[league_endpoint]" value="0">
                    <label for="ido_league_endpoint">
                        <input type="checkbox" id="ido_league_endpoint"
                               name="settings[league_endpoint]" value="1"
                               <?php checked(1, (int) $settings['league_endpoint']); ?>
                               <?php disabled($locked); ?>>
                        Allow other sites in this league to deliver packets to this site
                    </label>
                    <p class="description">
                        <strong>Status: <?php echo esc_html(IDO_League::endpoint_status()); ?></strong>
                    </p>
                    <?php if (IDO_League_URL::dev_notice() !== '') : ?>
                        <p class="description" style="color:#b32d2e">
                            <strong><?php echo esc_html(IDO_League_URL::dev_notice()); ?></strong>
                        </p>
                    <?php endif; ?>
                    <p class="description">
                        <strong>Off by default.</strong> This is the one public, unauthenticated surface
                        league play adds, so nothing opens it on your behalf. Even with it on it is only
                        registered when this site is actually in a league: opting in is not enough, and a
                        site playing locally never exposes anything.
                    </p>
                    <p class="description">
                        <strong>You have to turn it on to play a league.</strong> A march this site sends
                        is resolved by the defending site and the result is delivered back here, so a site
                        that cannot receive cannot get its army home. Leaving it off is a decision not to
                        play, or a way to stop while you investigate something.
                    </p>
                    <p class="description">
                        <?php if ($locked) : ?>
                            <strong>Locked shut in <code>wp-config.php</code>.</strong> This box cannot
                            change it. Remove the constant to allow it again.
                        <?php else : ?>
                            To put it beyond the reach of these screens entirely, add this to
                            <code>wp-config.php</code>:
                            <br><code>define( 'IDO_LEAGUE_DISABLE_ENDPOINT', true );</code>
                            <br>A setting lives in the database, so anything that can write to the database
                            can turn it back on, including somebody who has taken an administrator account.
                            A constant in a file cannot be changed that way.
                        <?php endif; ?>
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

    <hr>

    <h2>DANGER AREA - Refound the Board Now</h2>
    <div class="notice notice-error inline" style="max-width:46em">
        <p><strong>This starts the whole board again, and it cannot be undone.</strong></p>
        <p>
            Every empire keeps its name and its player and loses everything else: land, buildings,
            armies, treasury, siege weapons and agents are replaced by the same founding grant a new
            ruler gets, and every empire is given the same opening truce at the same moment.
        </p>
        <p>
            <strong>All rank and all scores are forfeit.</strong> Net worth goes back to a founding
            figure for everybody, so the standings start from nothing. In a league, the record goes with
            it: every exchange this round stops counting toward this site's score, because a site that
            could take a fresh founding grant and keep its league points would have found the best move
            in the game.
        </p>
        <p>
            The Hall of Fame is not touched. Completed rounds are history and stay that way.
        </p>
        <p>
            Armies away on a league march do not come back, and an open muster is returned before the
            reset. A march already heading for this site is turned away while the truce holds, and the
            attacker gets their force home intact.
        </p>
    </div>
    <?php echo IDO_Admin::form_open('reset_board', 'ido_settings'); ?>
        <p>
            <label>
                <input type="checkbox" name="confirm_reset" value="1" required>
                I understand this cannot be undone, and that all rank and scores are forfeit.
            </label>
        </p>
        <p>
            <label>Type <code>REFOUND</code> to confirm:
                <input type="text" name="confirm_word" class="regular-text" style="max-width:12em" required>
            </label>
        </p>
        <button type="submit" class="button button-link-delete"
                onclick="return confirm('Refound the board? Every empire starts again and all scores are forfeit. This cannot be undone.');">
            Refound the board
        </button>
    </form>

</div>
