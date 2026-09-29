<?php
if (!defined('ABSPATH')) exit;
/**
 * The League screen a player sees.
 *
 * Five things, in the order a ruler cares about them: where we stand, what is
 * being raised right now, the table, what I have done for it, and what has been
 * happening elsewhere.
 *
 * Nothing on this page shows an inbound march. A defender learns nothing until
 * the battle has been fought, and that rule reaches here as much as anywhere.
 */

$league = IDO_League::league();
?>

<?php if (!$league) : ?>
    <div class="ido-panel">
        <h3 class="ido-panel-title">No league</h3>
        <p>
            This site is playing its own game. Rulers here war with each other, and there is no league
            table because there is no league.
        </p>
        <p class="ido-dim">
            League play joins this site to other sites running this game: your rulers stop fighting each
            other and march together on somebody else. It is the game master's decision, not a ruler's.
        </p>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php if (IDO_League::pending()) : ?>
    <div class="ido-panel">
        <h3 class="ido-panel-title"><?php echo esc_html($league->league_name); ?></h3>
        <p>This site is joining the league but is not a member yet. Nothing can be marched, and nothing
           can march on us, until the enrolment is finished.</p>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php
$standings = IDO_League_Table::standings();
$us = null;
foreach ($standings as $row) { if (!empty($row['is_us'])) { $us = $row; break; } }
$march = IDO_League_Muster::open();
$mine = IDO_League_Table::ruler_record((int) $kingdom->id);
?>

<div class="ido-panel">
    <h3 class="ido-panel-title"><?php echo esc_html($league->league_name); ?></h3>
    <?php if ($us) : ?>
        <table class="ido-table">
            <tbody>
                <tr><th>Our position</th>
                    <td><?php echo esc_html(sprintf('%d of %d', (int) $us['position'], count($standings))); ?></td></tr>
                <tr><th>Score</th><td><?php echo esc_html(IDO_Game::fmt($us['score'])); ?></td></tr>
                <tr><th>Marches</th>
                    <td><?php echo esc_html(sprintf('%d won, %d lost, %d drawn',
                        (int) $us['won'], (int) $us['lost'], (int) $us['drawn'])); ?></td></tr>
                <tr><th>Defences</th>
                    <td><?php echo esc_html(sprintf('%d held, %d broken',
                        (int) $us['repelled'], (int) $us['broken'])); ?></td></tr>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if (IDO_League::paused()) : ?>
        <p class="ido-warning">League traffic is paused by the game master. Nothing is being sent or received.</p>
    <?php endif; ?>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">The muster</h3>
    <?php if (!$march) :
        $targets = [];
        foreach (IDO_League_Queue::active_peers() as $peer_site) {
            $under_grace = $peer_site->grace_until
                && strtotime((string) $peer_site->grace_until . ' UTC') > time();
            if (!$under_grace) $targets[] = $peer_site;
        }
    ?>
        <p>No army is being raised. Any ruler may call one.</p>
        <p class="ido-dim">
            One muster at a time. Calling one costs <?php echo esc_html((string) IDO_League_Muster::CALL_TURN_COST); ?>
            turns and the caller commits the first force: nobody starts a war they are not in. What you
            pledge leaves your empire at once and does not stand in your defence.
        </p>

        <?php if (!$targets) : ?>
            <p class="ido-warning">
                No member site can be marched on at the moment. Either nobody else has finished joining,
                or the ones who have are rebuilding under a grace period.
            </p>
        <?php else : ?>
            <?php echo IDO_UI::form_open('league_call'); ?>
                <p>
                    <label>March on
                        <select name="peer_id" class="ido-select" required>
                            <?php foreach ($targets as $peer_site) : ?>
                                <option value="<?php echo esc_attr((int) $peer_site->id); ?>">
                                    <?php echo esc_html($peer_site->site_name); ?>
                                    <?php if ($peer_site->news_as_of) : ?>
                                        &mdash; <?php echo esc_html(sprintf('%s empires, %s net worth, as of %s',
                                            IDO_Game::fmt($peer_site->empire_count),
                                            IDO_Game::fmt($peer_site->networth),
                                            IDO_League::when($peer_site->news_as_of))); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p class="ido-dim">
                    Those figures are what each site says about itself, and they are as old as the date
                    beside them. What your army meets is whatever is standing on the day it arrives.
                </p>
                <?php include __DIR__ . '/partials/pledge-fields.php'; ?>
                <button type="submit" class="ido-btn">Call the muster</button>
            </form>
        <?php endif; ?>
    <?php else :
        $target = IDO_League_Queue::peer((int) $march->peer_id);
        $board = IDO_League_Table::muster_board();
        $assembled = IDO_League_Muster::assembled((int) $march->id);
        $agent_holder = (int) $march->agent_kingdom_id;
    ?>
        <p>
            <strong>Against <?php echo esc_html($target ? $target->site_name : 'a member site'); ?>.</strong>
            The army leaves <?php echo esc_html(IDO_League::when($march->muster_closes_at)); ?>.
        </p>
        <p>Assembled so far: <?php echo esc_html(IDO_League_Muster::describe_force(
            $assembled['force'], $assembled['weapons'])); ?>.</p>

        <table class="ido-table">
            <thead><tr><th>Ruler</th><th>Pledged</th><th class="ido-right">Agent</th></tr></thead>
            <tbody>
            <?php if (!$board) : ?>
                <tr><td colspan="3">Nobody has pledged yet.</td></tr>
            <?php else : foreach ($board as $row) :
                $committed = json_decode((string) $row->committed_json, true);
                $force = is_array($committed) ? (array) ($committed['force'] ?? []) : [];
                $train = is_array($committed) ? (array) ($committed['weapons'] ?? []) : [];
            ?>
                <tr>
                    <td><?php echo esc_html($row->kingdom_name ?: 'A departed ruler'); ?></td>
                    <td><?php echo esc_html(IDO_League_Muster::describe_force($force, $train)); ?></td>
                    <td class="ido-right"><?php
                        echo $agent_holder === (int) $row->kingdom_id ? 'sent ahead' : '&mdash;'; ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php $mine_here = IDO_League_Muster::contribution((int) $march->id, (int) $kingdom->id); ?>

        <?php if ($mine_here) : ?>
            <p><strong>You have pledged to this muster.</strong> Your force is gone from your empire until
               the army returns.</p>
            <?php echo IDO_UI::form_open('league_withdraw', 'ido-form-inline'); ?>
                <input type="hidden" name="march_id" value="<?php echo esc_attr((int) $march->id); ?>">
                <button type="submit" class="ido-btn ido-btn-alt">Withdraw my force</button>
            </form>
            <p class="ido-dim">
                You can take it back while the window is open, and not after. The turns you spent stay
                spent.
            </p>
        <?php else : ?>
            <?php echo IDO_UI::form_open('league_join'); ?>
                <input type="hidden" name="march_id" value="<?php echo esc_attr((int) $march->id); ?>">
                <?php include __DIR__ . '/partials/pledge-fields.php'; ?>
                <button type="submit" class="ido-btn">Join the muster</button>
            </form>
            <p class="ido-dim">
                What you pledge leaves your empire at once and does not stand in your defence. You can take
                it back while the window is open, and not after.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">The league table</h3>
    <table class="ido-table">
        <thead>
            <tr>
                <th class="ido-right">#</th>
                <th>Site</th>
                <th class="ido-right">Score</th>
                <th class="ido-right">Won</th>
                <th class="ido-right">Lost</th>
                <th class="ido-right">Drawn</th>
                <th class="ido-right">Held</th>
                <th class="ido-right">Empires</th>
                <th class="ido-right">Net worth</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($standings as $row) : ?>
            <tr<?php echo !empty($row['is_us']) ? ' class="ido-highlight"' : ''; ?>>
                <td class="ido-right"><?php echo esc_html((string) $row['position']); ?></td>
                <td><?php echo esc_html($row['name']); ?><?php
                    echo !empty($row['is_us']) ? ' <span class="ido-dim">(us)</span>' : ''; ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($row['score'])); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $row['won']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $row['lost']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $row['drawn']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $row['repelled']); ?></td>
                <?php /* Claimed figures are shown as claims: dimmed, and stamped with
                          the date the site said them. Nothing is ranked on these. */ ?>
                <td class="ido-right ido-dim"><?php echo esc_html($row['as_of'] || empty($row['claimed'])
                    ? IDO_Game::fmt($row['empires']) : '—'); ?></td>
                <td class="ido-right ido-dim"><?php echo esc_html($row['as_of'] || empty($row['claimed'])
                    ? IDO_Game::fmt($row['networth']) : '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="ido-dim">
        Score comes from battles, which both sites hold a signed record of. The last two columns are what
        each site says about itself: signing proves a message came from them unaltered, not that the
        numbers are true, so nothing is ranked on them. Sites are ordered by what this site has seen
        first hand, so another member&rsquo;s table may differ by an exchange we have not heard about.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">What you have given</h3>
    <table class="ido-table">
        <tbody>
            <tr><th>Musters joined</th><td><?php echo esc_html(IDO_Game::fmt($mine['marches'])); ?></td></tr>
            <tr><th>Soldiers committed</th><td><?php echo esc_html(IDO_Game::fmt($mine['committed'])); ?></td></tr>
            <tr><th>Come home</th><td><?php echo esc_html(IDO_Game::fmt($mine['returned'])); ?></td></tr>
            <tr><th>Spoils</th>
                <td><?php echo esc_html(sprintf('%s gold, %s grain, %s iron, %s engines',
                    IDO_Game::fmt($mine['gold']), IDO_Game::fmt($mine['grain']),
                    IDO_Game::fmt($mine['iron']), IDO_Game::fmt($mine['weapons']))); ?></td></tr>
            <?php if ($mine['agent_missions'] > 0) : ?>
                <tr><th>Agents sent ahead</th>
                    <td><?php echo esc_html(IDO_Game::fmt($mine['agent_missions'])); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php $recent = IDO_League_Table::recent(10); ?>
<?php if ($recent) : ?>
    <div class="ido-panel">
        <h3 class="ido-panel-title">Recent exchanges</h3>
        <table class="ido-table">
            <thead><tr><th>When</th><th>Against</th><th>What happened</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $row) : ?>
                <tr>
                    <td class="ido-dim"><?php echo esc_html(IDO_League::when($row->resolved_at)); ?></td>
                    <td><?php echo esc_html($row->site_name ?: 'a departed member'); ?></td>
                    <td>
                        <?php echo esc_html(sprintf('%s %s',
                            (string) $row->direction === 'out' ? 'We marched:' : 'They marched:',
                            IDO_League_Status::label(IDO_League_Status::RESOLVED, (string) $row->outcome))); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
