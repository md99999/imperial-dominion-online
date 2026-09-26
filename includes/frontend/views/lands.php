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
        <p class="ido-dim">The larger your empire grows, the fewer acres a party finds and the more each one costs. Past a point it is cheaper to take land from a neighbour than to settle it.</p>
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
        <thead><tr><th>Building</th><th class="ido-right">Owned</th><th>What it does</th><th>Order</th><th>Demolish</th></tr></thead>
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

<div class="ido-panel">
    <h3 class="ido-panel-title">The siege yards</h3>
    <p class="ido-dim">
        Weapons are built, not trained: they stand on no acre and take no peasant out of the fields.
        An order costs one turn, however many weapons it covers, and the yards finish on the same
        daily tick your builders do. What they cost you instead is troops and risk. Every weapon
        needs men to work it, and a weapon nobody is working is timber, whether it sits in the yards
        or on your wall. The losing side of a battle gives up a share of whatever was being worked.
    </p>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Weapon</th><th class="ido-right">Owned</th><th class="ido-right">Manned</th><th class="ido-right">In the yards</th><th class="ido-right">Cost each</th><th>What it does</th><th>Build</th><th>Scrap</th></tr></thead>
        <tbody>
        <?php $manned = IDO_Weapons::crewed($kingdom); ?>
        <?php foreach (IDO_Weapons::all() as $key => $weapon) :
            $cost = IDO_Weapons::cost($key);
            $standing = (int) $kingdom->{IDO_Weapons::column($key)};
            $worked = (int) ($manned[$key] ?? 0); ?>
            <tr>
                <td>
                    <?php echo esc_html($weapon['plural']); ?>
                    <div class="ido-dim">Offence <?php echo esc_html((string) $weapon['offence']); ?>,
                        defence <?php echo esc_html((string) $weapon['defence']); ?>,
                        <?php echo esc_html(number_format_i18n($weapon['upkeep'], 1)); ?> grain a turn</div>
                    <div class="ido-dim">Crewed by <?php echo esc_html((string) IDO_Weapons::crew_each($key)); ?>
                        <?php echo esc_html(strtolower(IDO_Units::plural(IDO_Weapons::crew_unit($key)))); ?> each</div>
                </td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($standing)); ?></td>
                <td class="ido-right">
                    <?php echo esc_html(IDO_Game::fmt($worked)); ?>
                    <?php if ($worked < $standing) : ?>
                        <div class="ido-warning"><?php echo esc_html(IDO_Game::fmt($standing - $worked)); ?> idle</div>
                    <?php endif; ?>
                </td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($kingdom->{IDO_Weapons::progress_column($key)})); ?></td>
                <td class="ido-right">
                    <?php echo esc_html(IDO_Game::fmt($cost['gold'])); ?>g<br>
                    <span class="ido-dim"><?php echo esc_html(IDO_Game::fmt($cost['iron'])); ?> iron</span>
                </td>
                <td class="ido-dim"><?php echo esc_html($weapon['effect']); ?></td>
                <td>
                    <?php echo IDO_UI::form_open('build_weapon', 'ido-form-inline'); ?>
                        <input type="hidden" name="weapon" value="<?php echo esc_attr($key); ?>">
                        <?php echo IDO_UI::number_field('qty', 0, 0); ?>
                        <button type="submit" class="ido-btn ido-btn-small">Build</button>
                    </form>
                </td>
                <td>
                    <?php echo IDO_UI::form_open('scrap_weapon', 'ido-form-inline'); ?>
                        <input type="hidden" name="weapon" value="<?php echo esc_attr($key); ?>">
                        <?php echo IDO_UI::number_field('qty', 0, 0); ?>
                        <button type="submit" class="ido-btn ido-btn-alt ido-btn-small">Scrap</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="ido-dim">
        A battle hands <?php echo esc_html((string) IDO_Settings::int('catapult_capture_percent')); ?>% of the losing side's
        worked weapons to the winner and smashes a further <?php echo esc_html((string) IDO_Settings::int('catapult_destroy_percent')); ?>%,
        so losing one costs <?php echo esc_html((string) (IDO_Settings::int('catapult_capture_percent') + IDO_Settings::int('catapult_destroy_percent'))); ?>% of what was in the fight.
        Idle weapons take no part and so are never lost, and never help either.
        Scrapping returns <?php echo esc_html((string) IDO_Settings::int('demolish_refund_percent')); ?>% of the build cost.
    </p>
</div>
