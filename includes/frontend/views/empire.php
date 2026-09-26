<?php
/** The empire at a glance: its holdings, its income, and what is being built. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
$rate      = IDO_Economy::per_turn($kingdom);
$position  = IDO_Rankings::position($kingdom);
$kingdoms    = IDO_Rankings::kingdom_count((int) $kingdom->round_id);
$unread    = IDO_UI::unread_counts($kingdom);
$pending   = IDO_Construction::pending($kingdom);
$wilderness = IDO_Buildings::wilderness($kingdom);
?>
<?php if ($unread['battles'] || $unread['ops']) : ?>
    <div class="ido-flash ido-flash-warning">
        <?php if ($unread['battles']) : ?>
            <?php echo esc_html(sprintf(_n('%d battle report is waiting in the war room.', '%d battle reports are waiting in the war room.', $unread['battles'], 'imperial-dominion-online'), $unread['battles'])); ?>
        <?php endif; ?>
        <?php if ($unread['ops']) : ?>
            <?php echo esc_html(sprintf(_n('%d report from the spy court is waiting.', '%d reports from the spy court are waiting.', $unread['ops'], 'imperial-dominion-online'), $unread['ops'])); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="ido-columns">
    <div class="ido-panel">
        <h3 class="ido-panel-title">The empire</h3>
        <table class="ido-table">
            <tbody>
                <tr><th>Standing</th><td><?php echo esc_html(sprintf('%d of %d empires', $position, $kingdoms)); ?></td></tr>
                <tr><th>Title</th><td><?php echo esc_html(IDO_Game::title((int) $kingdom->networth)); ?></td></tr>
                <tr><th>Land</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->land)); ?> acres</td></tr>
                <tr><th>Built</th><td><?php echo esc_html(IDO_Game::fmt(IDO_Buildings::total($kingdom))); ?></td></tr>
                <tr><th>Under construction</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->land_in_progress)); ?></td></tr>
                <tr><th>Wilderness</th><td><?php echo esc_html(IDO_Game::fmt($wilderness)); ?></td></tr>
                <tr><th>Peasants</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->peasants)); ?> of <?php echo esc_html(IDO_Game::fmt($rate['capacity'])); ?> housed</td></tr>
                <tr><th>Under arms</th><td><?php echo esc_html(IDO_Game::fmt(IDO_Units::total($kingdom))); ?></td></tr>
                <tr><th>Siege weapons</th><td><?php echo esc_html(IDO_Game::fmt(IDO_Weapons::total($kingdom))); ?></td></tr>
                <tr><th>Defence rating</th><td><?php echo esc_html(IDO_Game::fmt(round(IDO_Military::defence_power($kingdom)))); ?></td></tr>
                <tr><th>Agents</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->agents)); ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="ido-panel">
        <h3 class="ido-panel-title">What one turn yields</h3>
        <table class="ido-table">
            <tbody>
                <tr><th>Gold</th><td><?php echo esc_html(IDO_Game::fmt($rate['gold'])); ?>
                    <span class="ido-dim">(<?php echo esc_html(IDO_Game::fmt($rate['gold_in'])); ?> in, <?php echo esc_html(IDO_Game::fmt($rate['gold_out'])); ?> upkeep)</span></td></tr>
                <tr><th>Grain</th><td><?php echo esc_html(IDO_Game::fmt($rate['grain'])); ?>
                    <span class="ido-dim">(<?php echo esc_html(IDO_Game::fmt($rate['grain_in'])); ?> harvested, <?php echo esc_html(IDO_Game::fmt($rate['grain_out'])); ?> eaten)</span></td></tr>
                <tr><th>Iron</th><td><?php echo esc_html(IDO_Game::fmt($rate['iron'])); ?></td></tr>
                <tr><th>Peasants</th><td><?php echo esc_html(IDO_Game::fmt($rate['peasants'])); ?></td></tr>
            </tbody>
        </table>
        <?php if ($rate['grain'] < 0) : ?>
            <p class="ido-warning">Your empire eats more grain than it grows. Raise farmsteads or buy grain before the stores run dry.</p>
        <?php endif; ?>
        <p class="ido-dim">Income is paid out as turns are spent, never while they sit idle.</p>
    </div>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Standing buildings</h3>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Building</th><th class="ido-right">Standing</th><th>What it does</th></tr></thead>
        <tbody>
        <?php foreach (IDO_Buildings::all() as $key => $building) : ?>
            <tr>
                <td><?php echo esc_html($building['plural']); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($kingdom->{IDO_Buildings::column($key)})); ?></td>
                <td class="ido-dim"><?php echo esc_html($building['effect']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pending) : ?>
    <div class="ido-panel">
        <h3 class="ido-panel-title">Work in progress</h3>
        <table class="ido-table">
            <thead><tr><th>Under way</th><th class="ido-right">Ordered</th><th>Ready on</th></tr></thead>
            <tbody>
            <?php foreach ($pending as $order) :
                $is_weapon = IDO_Construction::is_weapon_order($order); ?>
                <tr>
                    <td><?php echo esc_html($is_weapon
                        ? IDO_Weapons::plural($order->building)
                        : IDO_Buildings::plural($order->building)); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($order->qty)); ?></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($order->ready_on))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
