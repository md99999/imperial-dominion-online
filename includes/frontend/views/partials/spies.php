<?php
/** Spy court: hire an agent, send them out, read what they found. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
IDO_UI::mark_reports_seen($kingdom);

$ops     = IDO_Covert::ops();
$history = IDO_Covert::history($kingdom, 20);
$targets = IDO_Military::targets($kingdom, 200);
$tiers   = IDO_Agents::all();

// Any spy at all, of either kind: the mission form opens on that, and then
// narrows itself per mission to the ones that could actually run it.
$has_spy = false;
foreach (IDO_Agents::keys() as $ido_tier) {
    if (IDO_Agents::held($kingdom, $ido_tier) > 0) { $has_spy = true; break; }
}
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">The spy court</h3>
    <p>
        Two kinds of servant keep the crown informed. An <strong>informer</strong> is cheap and can
        only bring back what they have seen; an <strong>agent</strong> costs a great deal more and
        will do rather more than watch. Missions can fail, and a failed mission often ends with your
        spy on a rope &mdash; an informer far more often than an agent. Replacing either costs the
        full price again.
    </p>

    <table class="ido-table ido-table-wide">
        <thead>
            <tr><th>Spy</th><th>In service</th><th class="ido-right">To hire</th><th>Can do</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($tiers as $key => $tier) : ?>
                <?php
                $held  = IDO_Agents::held($kingdom, $key);
                $limit = IDO_Agents::limit($key);
                $price = IDO_Agents::cost($key);
                $can   = $tier['ops'] === null
                    ? 'Every mission'
                    : implode(', ', array_map(
                        static fn($op) => $ops[$op]['label'] ?? $op, (array) $tier['ops']));
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($tier['label']); ?></strong>
                        <div class="ido-dim"><?php echo esc_html($tier['note']); ?></div>
                    </td>
                    <td><?php echo esc_html(IDO_Game::fmt($held) . ' of ' . $limit); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($price)); ?> gold</td>
                    <td class="ido-dim"><?php echo esc_html($can); ?></td>
                    <td>
                        <?php if ($held < $limit) : ?>
                            <?php echo IDO_UI::form_open('hire_agent', 'ido-form-inline'); ?>
                                <input type="hidden" name="tier" value="<?php echo esc_attr($key); ?>">
                                <button type="submit" class="ido-btn ido-btn-small">Hire</button>
                            </form>
                        <?php else : ?>
                            <span class="ido-dim">As many as allowed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="ido-dim">
        Your gold: <strong><?php echo esc_html(IDO_Game::fmt($kingdom->gold)); ?></strong>.
        Hiring costs one turn.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Send a mission</h3>
    <?php if (!$has_spy) : ?>
        <p class="ido-warning">You keep no spy of either kind. Nothing can be sent until one is hired.</p>
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
                                '%s - %s gold, about %d%% likely',
                                $op['label'], IDO_Game::fmt($op['gold']), (int) $op['chance']
                            )); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ido-field">
                <span>Send</span>
                <select name="tier" class="ido-select">
                    <?php foreach ($tiers as $key => $tier) : ?>
                        <?php if (IDO_Agents::held($kingdom, $key) < 1) continue; ?>
                        <option value="<?php echo esc_attr($key); ?>">
                            <?php
                            // The odds are quoted per spy, because they are the
                            // whole difference between them: the same mission
                            // run by somebody less able, and far likelier to be
                            // taken when it goes wrong.
                            echo esc_html(sprintf(
                                '%s - %s',
                                $tier['label'],
                                $tier['ops'] === null
                                    ? 'any mission, ordinary risk'
                                    : 'reconnaissance only, and caught far more often'
                            ));
                            ?>
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
