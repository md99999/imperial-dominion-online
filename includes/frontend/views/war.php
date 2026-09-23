<?php
/** War room: choose a target, commit a force, read the reports. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
IDO_UI::mark_reports_seen($kingdom);

$targets   = IDO_Military::targets($kingdom);
$battles   = IDO_Military::history($kingdom, 20);
$types     = IDO_Military::attack_types();
$turn_cost = IDO_Settings::int('attack_turn_cost');
$selected  = isset($_GET['target']) ? (int) $_GET['target'] : 0;
$band_min  = IDO_Settings::int('target_min_percent');
$band_max  = IDO_Settings::int('target_max_percent');
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">Order a march</h3>
    <?php if (IDO_Kingdom::is_protected($kingdom)) : ?>
        <p class="ido-warning">You are under the crown truce. Marching on another kingdom ends it at once and leaves you open to attack.</p>
    <?php endif; ?>
    <p class="ido-dim">
        A march costs <?php echo esc_html((string) $turn_cost); ?> turns and resolves the moment you commit.
        You may attack kingdoms worth between <?php echo esc_html((string) $band_min); ?>% and
        <?php echo esc_html((string) $band_max); ?>% of your net worth, at most
        <?php echo esc_html((string) IDO_Settings::int('max_hits_per_target')); ?> times each a day.
    </p>

    <?php if (!$targets) : ?>
        <p>No kingdom is within reach of your banners. Grow, or wait for your rivals to.</p>
    <?php else : ?>
        <?php echo IDO_UI::form_open('attack'); ?>
            <label class="ido-field">
                <span>Target</span>
                <select name="target_id" class="ido-select" required>
                    <option value="">Choose a kingdom</option>
                    <?php foreach ($targets as $target) :
                        $is_protected = IDO_Kingdom::is_protected($target); ?>
                        <option value="<?php echo esc_attr((string) $target->id); ?>"
                            <?php selected($selected, (int) $target->id); ?>
                            <?php disabled($is_protected); ?>>
                            <?php echo esc_html(sprintf(
                                '%s (%s) - %s acres, net worth %s%s',
                                $target->kingdom_name, $target->ruler_name,
                                IDO_Game::fmt($target->land), IDO_Game::fmt($target->networth),
                                $is_protected ? ' - under truce' : ''
                            )); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="ido-field">
                <span>Kind of attack</span>
                <select name="attack_type" class="ido-select">
                    <?php foreach ($types as $key => $type) : ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($type['label'] . ' - ' . $type['note']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="ido-force">
                <?php foreach (IDO_Units::all() as $key => $unit) : ?>
                    <label class="ido-field ido-field-small">
                        <span><?php echo esc_html($unit['plural']); ?>
                            <span class="ido-dim">(<?php echo esc_html(IDO_Game::fmt($kingdom->{IDO_Units::column($key)})); ?> available, offence <?php echo esc_html((string) $unit['offence']); ?>)</span>
                        </span>
                        <?php echo IDO_UI::number_field('force_' . $key, 0, 0); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <p><button type="submit" class="ido-btn ido-btn-danger">Sound the horns</button> <?php echo IDO_UI::turn_cost($turn_cost); ?></p>
        </form>
    <?php endif; ?>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Kingdoms within reach</h3>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Kingdom</th><th>Ruler</th><th class="ido-right">Acres</th><th class="ido-right">Net worth</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($targets as $target) : ?>
            <tr>
                <td><?php echo esc_html($target->kingdom_name); ?></td>
                <td><?php echo esc_html($target->ruler_name); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($target->land)); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($target->networth)); ?></td>
                <td>
                    <?php if (IDO_Kingdom::is_protected($target)) : ?>
                        <span class="ido-dim">Under truce</span>
                    <?php else : ?>
                        <a class="ido-btn ido-btn-small" href="<?php echo esc_url(IDO_UI::url('war', ['target' => (int) $target->id])); ?>">Target</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Dispatches</h3>
    <?php if (!$battles) : ?>
        <p class="ido-dim">No blood has been spilled yet.</p>
    <?php else : ?>
        <?php foreach ($battles as $battle) :
            $mine = (int) $battle->attacker_kingdom_id === (int) $kingdom->id;
            $report = $mine ? $battle->attacker_report : $battle->defender_report;
            $won = $battle->outcome === 'victory';
            $good = $mine ? $won : !$won; ?>
            <div class="ido-report <?php echo $good ? 'ido-report-good' : 'ido-report-bad'; ?>">
                <div class="ido-report-head">
                    <?php echo esc_html(sprintf(
                        '%s  %s vs %s',
                        date_i18n(get_option('date_format') . ' H:i', strtotime($battle->created_at)),
                        (string) $battle->attacker_name,
                        (string) $battle->defender_name
                    )); ?>
                </div>
                <div class="ido-report-body"><?php echo nl2br(esc_html((string) $report)); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
