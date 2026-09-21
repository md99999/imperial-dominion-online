<?php
/** Muster field: raise and disband troops. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
$discount = IDO_Buildings::barracks_discount($kingdom);
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">The muster</h3>
    <p class="ido-dim">
        Every soldier is a peasant taken out of the fields, so an army costs you taxes as well as gold.
        Offence counts only when you march; defence only when you are marched upon.
        <?php if ($discount > 0) : ?>
            Your barracks trim <?php echo esc_html(number_format_i18n($discount * 100, 1)); ?>% from the gold price.
        <?php endif; ?>
        Training an order of troops costs one turn.
    </p>
    <table class="ido-table ido-table-wide">
        <thead>
            <tr>
                <th>Troops</th>
                <th class="ido-right">Offence</th>
                <th class="ido-right">Defence</th>
                <th class="ido-right">Cost each</th>
                <th class="ido-right">Standing</th>
                <th>Train</th>
                <th>Disband</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (IDO_Units::all() as $key => $unit) :
            $each = IDO_Military::training_cost($kingdom, $key, 1); ?>
            <tr>
                <td>
                    <?php echo esc_html($unit['plural']); ?>
                    <div class="ido-dim"><?php echo esc_html($unit['note']); ?></div>
                </td>
                <td class="ido-right"><?php echo esc_html((string) $unit['offence']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $unit['defence']); ?></td>
                <td class="ido-right">
                    <?php echo esc_html(IDO_Game::fmt($each['gold'])); ?>g<br>
                    <span class="ido-dim"><?php echo esc_html(IDO_Game::fmt($each['iron'])); ?> iron,
                    <?php echo esc_html(IDO_Game::fmt($each['peasants'])); ?> peasants</span>
                </td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($kingdom->{IDO_Units::column($key)})); ?></td>
                <td>
                    <?php echo IDO_UI::form_open('train', 'ido-form-inline'); ?>
                        <input type="hidden" name="unit" value="<?php echo esc_attr($key); ?>">
                        <?php echo IDO_UI::number_field('qty', 0, 0); ?>
                        <button type="submit" class="ido-btn ido-btn-small">Train</button>
                    </form>
                </td>
                <td>
                    <?php echo IDO_UI::form_open('disband', 'ido-form-inline'); ?>
                        <input type="hidden" name="unit" value="<?php echo esc_attr($key); ?>">
                        <?php echo IDO_UI::number_field('qty', 0, 0); ?>
                        <button type="submit" class="ido-btn ido-btn-alt ido-btn-small">Disband</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="ido-columns">
    <div class="ido-panel">
        <h3 class="ido-panel-title">Standing defence</h3>
        <table class="ido-table">
            <tbody>
                <tr><th>Defence rating</th><td><?php echo esc_html(IDO_Game::fmt(round(IDO_Units::defence_power($kingdom)))); ?></td></tr>
                <tr><th>Fortifications</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->b_fortification)); ?>
                    <span class="ido-dim">(+<?php echo esc_html(number_format_i18n((IDO_Buildings::fortification_bonus($kingdom) - 1) * 100, 1)); ?>%)</span></td></tr>
                <tr><th>Grain eaten each turn</th><td><?php echo esc_html(IDO_Game::fmt(round(IDO_Units::upkeep($kingdom)))); ?></td></tr>
            </tbody>
        </table>
    </div>
    <div class="ido-panel">
        <h3 class="ido-panel-title">A word on armies</h3>
        <ul class="ido-list">
            <li>Levies are the cheapest way to make an attacker think twice, and useless for anything else.</li>
            <li>Wardens hold ground. A kingdom with no wardens is a larder with the door open.</li>
            <li>Reavers take ground. They are worth almost nothing at home, so never leave them idle.</li>
            <li>Siege trains break fortifications. They cost a fortune and eat like three men each.</li>
        </ul>
    </div>
</div>
