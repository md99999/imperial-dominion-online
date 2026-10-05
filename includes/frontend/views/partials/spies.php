<?php
/** Spy court: hire an agent, send them out, read what they found. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
IDO_UI::mark_reports_seen($kingdom);

$cost      = IDO_Covert::agent_cost();
$max       = max(1, IDO_Settings::int('max_agents'));
$ops       = IDO_Covert::ops();
$history   = IDO_Covert::history($kingdom, 20);
$targets   = IDO_Military::targets($kingdom, 200);
$has_agent = (int) $kingdom->agents > 0;
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">The spy court</h3>
    <p>
        An agent is the most expensive servant a crown can keep, and no ruler may keep more than
        <strong><?php echo esc_html((string) $max); ?></strong>. Hiring one costs
        <strong><?php echo esc_html(IDO_Game::fmt($cost['gold'])); ?></strong> gold, which is a
        fortune no young empire can raise: the trade in secrets belongs to those who have already
        built something worth protecting.
    </p>
    <p class="ido-dim">
        Missions can fail, and a failed mission often ends with your agent on a rope. Replacing them costs the full
        price again, which is why the wise send an agent only when the answer is worth a fortune.
    </p>

    <table class="ido-table">
        <tbody>
            <tr><th>Agents in your service</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->agents)); ?> of <?php echo esc_html((string) $max); ?></td></tr>
            <tr><th>Your gold</th><td><?php echo esc_html(IDO_Game::fmt($kingdom->gold)); ?></td></tr>
        </tbody>
    </table>

    <?php if ((int) $kingdom->agents < $max) : ?>
        <?php echo IDO_UI::form_open('hire_agent'); ?>
            <button type="submit" class="ido-btn">Hire an agent</button> <?php echo IDO_UI::turn_cost(1); ?>
        </form>
    <?php else : ?>
        <p class="ido-dim">You keep as many agents as the crown allows.</p>
    <?php endif; ?>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Send a mission</h3>
    <?php if (!$has_agent) : ?>
        <p class="ido-warning">You keep no agent. Nothing can be sent until one is hired.</p>
    <?php elseif (!$targets) : ?>
        <p class="ido-dim">There is no one within reach worth watching.</p>
    <?php else : ?>
        <?php echo IDO_UI::form_open('run_op'); ?>
            <label class="ido-field">
                <span>Target</span>
                <select name="target_id" class="ido-select" required>
                    <option value="">Choose an empire</option>
                    <?php foreach ($targets as $target) : ?>
                        <?php if (IDO_Kingdom::is_protected($target)) continue; ?>
                        <option value="<?php echo esc_attr((string) $target->id); ?>">
                            <?php echo esc_html(sprintf('%s (%s)', $target->kingdom_name, $target->ruler_name)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ido-field">
                <span>Mission</span>
                <select name="op" class="ido-select">
                    <?php foreach ($ops as $key => $op) : ?>
                        <option value="<?php echo esc_attr($key); ?>">
                            <?php echo esc_html(sprintf(
                                '%s - %s gold, about %d%% likely, %d%% risk to the agent',
                                $op['label'], IDO_Game::fmt($op['gold']), (int) $op['chance'], (int) $op['risk']
                            )); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p><button type="submit" class="ido-btn">Send them out</button> <?php echo IDO_UI::turn_cost(max(1, IDO_Settings::int("op_turn_cost"))); ?></p>
        </form>
        <ul class="ido-list">
            <?php foreach ($ops as $op) : ?>
                <li><strong><?php echo esc_html($op['label']); ?></strong> &mdash; <?php echo esc_html($op['note']); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Whispers</h3>
    <?php if (!$history) : ?>
        <p class="ido-dim">Nothing has been heard in the dark yet.</p>
    <?php else : ?>
        <?php foreach ($history as $entry) :
            $mine = (int) $entry->actor_kingdom_id === (int) $kingdom->id;
            $report = $mine ? $entry->actor_report : $entry->target_report;
            $good = $mine ? ($entry->outcome === 'success') : ($entry->outcome !== 'success'); ?>
            <div class="ido-report <?php echo $good ? 'ido-report-good' : 'ido-report-bad'; ?>">
                <div class="ido-report-head">
                    <?php echo esc_html(sprintf(
                        '%s  %s',
                        date_i18n(get_option('date_format') . ' H:i', strtotime($entry->created_at)),
                        $mine
                            ? sprintf('your agent against %s', (string) $entry->target_name)
                            : sprintf('an agent of %s against you', (string) $entry->actor_name)
                    )); ?>
                </div>
                <div class="ido-report-body"><?php echo nl2br(esc_html((string) $report)); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
