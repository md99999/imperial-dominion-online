<?php
/** Lands: settle wilderness, raise buildings on it, pull them down again. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
$preview    = IDO_Economy::explore_preview($kingdom);
$wilderness = IDO_Buildings::wilderness($kingdom);
$gold_each  = IDO_Settings::int('build_gold_per_acre');
$iron_each  = IDO_Settings::int('build_iron_per_acre');
$build_days = IDO_Settings::int('build_days');
?>
<div class="ido-columns">
    <div class="ido-panel">
        <h3 class="ido-panel-title">Settle new land</h3>
        <p>
            Your scouts report room for about <strong><?php echo esc_html(IDO_Game::fmt($preview['acres'])); ?></strong> acres,
            at <strong><?php echo esc_html(IDO_Game::fmt($preview['gold'])); ?></strong> gold and one turn.
        </p>
        <p class="ido-dim">The larger your kingdom grows, the fewer acres a party finds and the more each one costs. Past a point it is cheaper to take land from a neighbour than to settle it.</p>
        <?php echo IDO_UI::form_open('explore'); ?>
            <button type="submit" class="ido-btn">Send settlers</button> <?php echo IDO_UI::turn_cost(1); ?>
        </form>
    </div>

    <div class="ido-panel">
        <h3 class="ido-panel-title">Land ledger</h3>
        <table class="ido-table">
            <tbody>
                <tr><th>Total acres</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->land)); ?></td></tr>
                <tr><th>Built</th><td><?php echo esc_html(IDO_Game::fmt(IDO_Buildings::total($kingdom))); ?></td></tr>
                <tr><th>Under construction</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->land_in_progress)); ?></td></tr>
                <tr><th>Wilderness</th><td><?php echo esc_html(IDO_Game::fmt($wilderness)); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Raise buildings</h3>
    <p class="ido-dim">
        Each building costs <?php echo esc_html(IDO_Game::fmt($gold_each)); ?> gold and
        <?php echo esc_html(IDO_Game::fmt($iron_each)); ?> iron per acre, plus one turn for the order.
        <?php if ($build_days > 0) : ?>
            Each order costs one turn, however many buildings it covers, so place large orders. Work finishes on the daily tick, <?php echo esc_html(sprintf(_n('%d day from the order', '%d days from the order', $build_days, 'imperial-dominion-online'), $build_days)); ?>.
        <?php endif; ?>
    </p>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Building</th><th class="ido-right">Standing</th><th>What it does</th><th>Order</th><th>Demolish</th></tr></thead>
        <tbody>
        <?php foreach (IDO_Buildings::all() as $key => $building) : ?>
            <tr>
                <td><?php echo esc_html($building['plural']); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($kingdom->{IDO_Buildings::column($key)})); ?></td>
                <td class="ido-dim"><?php echo esc_html($building['effect']); ?></td>
                <td>
                    <?php echo IDO_UI::form_open('build', 'ido-form-inline'); ?>
                        <input type="hidden" name="building" value="<?php echo esc_attr($key); ?>">
                        <?php echo IDO_UI::number_field('qty', 0, 0); ?>
                        <button type="submit" class="ido-btn ido-btn-small">Build</button>
                    </form>
                </td>
                <td>
                    <?php echo IDO_UI::form_open('demolish', 'ido-form-inline'); ?>
                        <input type="hidden" name="building" value="<?php echo esc_attr($key); ?>">
                        <?php echo IDO_UI::number_field('qty', 0, 0); ?>
                        <button type="submit" class="ido-btn ido-btn-alt ido-btn-small">Demolish</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="ido-dim">
        Demolishing returns <?php echo esc_html((string) IDO_Settings::int('demolish_refund_percent')); ?>% of the building cost as salvage
        and hands the acres back to wilderness.
    </p>
</div>
