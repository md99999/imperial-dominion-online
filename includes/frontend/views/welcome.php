<?php
/**
 * The front door, shown to anyone who is not signed in. It has to do three
 * things: say what the game is, show that it is being played, and get the
 * visitor to the rules or to a login.
 */
if (!defined('ABSPATH')) exit;

$s         = IDO_Settings::all();
$round     = IDO_Rounds::current();
$standings = $round ? IDO_Rankings::standings((int) $round->id, 10) : [];
$count     = $round ? IDO_Rankings::kingdom_count((int) $round->id) : 0;
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">Welcome to <?php echo esc_html(IDO_Game::dominion()); ?></h3>
    <p>
        The old empire is broken and its provinces lie open. Claim a kingdom, settle the wilderness, raise
        farmsteads and fortifications on it, and turn your peasants into an army. Then decide what to do about
        your neighbours, who are doing exactly the same thing a few acres away.
    </p>
    <p>
        You get <strong><?php echo esc_html((string) $s['turns_per_day']); ?> turns a day</strong>. Every order
        costs turns, and every turn you spend pays out your income at once, so the game rewards showing up rather
        than grinding. A round lasts <strong><?php echo esc_html((string) $s['round_days']); ?> days</strong>;
        at the end, the richest kingdom is champion, everything is wiped, and a new round opens for everyone.
    </p>
    <p>
        <a class="ido-btn" href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Sign in to play</a>
        <?php if (get_option('users_can_register')) : ?>
            <a class="ido-btn ido-btn-alt" href="<?php echo esc_url(wp_registration_url()); ?>">Register</a>
        <?php endif; ?>
        <a class="ido-btn ido-btn-alt" href="<?php echo esc_url(IDO_UI::url('guide')); ?>">How to play</a>
    </p>
</div>

<div class="ido-columns">
    <div class="ido-panel">
        <h3 class="ido-panel-title">What a ruler does</h3>
        <ul class="ido-list">
            <li><strong>Settle and build.</strong> Bare land earns nothing; buildings are what produce.</li>
            <li><strong>Feed your people.</strong> Empty granaries mean peasants fleeing and troops deserting.</li>
            <li><strong>Raise an army.</strong> Troops to hold your ground, and troops to take someone else's.</li>
            <li><strong>Trade.</strong> An open market where rulers set their own prices.</li>
            <li><strong>Make war.</strong> Take acres, plunder treasuries, or throw down fortifications.</li>
            <li><strong>Keep a spy.</strong> One agent, ruinously expensive, who can tell you what a rival really has.</li>
        </ul>
    </div>

    <div class="ido-panel">
        <h3 class="ido-panel-title">The round</h3>
        <?php if (!$round) : ?>
            <p>No round is running just now. The heralds will announce the next one.</p>
        <?php else : ?>
            <table class="ido-table">
                <tbody>
                    <tr><th>Round</th><td><?php echo esc_html($round->round_name); ?></td></tr>
                    <?php $left = IDO_Rounds::days_left($round); ?>
                    <?php if ($left !== null) : ?>
                        <tr><th>Time left</th><td><?php echo esc_html(sprintf(_n('%d day', '%d days', $left, 'imperial-dominion-online'), $left)); ?></td></tr>
                    <?php endif; ?>
                    <tr><th>Kingdoms</th><td><?php echo esc_html(IDO_Game::fmt($count)); ?></td></tr>
                    <tr><th>Turns a day</th><td><?php echo esc_html((string) $s['turns_per_day']); ?></td></tr>
                    <tr><th>New rulers</th><td><?php echo $s['allow_new_kingdoms'] ? 'Welcome' : 'The rolls are closed'; ?></td></tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Who leads <?php echo esc_html(IDO_Game::dominion()); ?></h3>
    <?php if (!$standings) : ?>
        <p class="ido-dim">Not one throne is claimed. The first kingdom founded takes the top of this table by
            default, and holds it until somebody takes it from them.</p>
    <?php else : ?>
        <table class="ido-table ido-table-wide">
            <thead>
                <tr><th class="ido-right">#</th><th>Kingdom</th><th>Ruler</th><th>Title</th>
                    <th class="ido-right">Acres</th><th class="ido-right">Net worth</th><th class="ido-right">Victories</th></tr>
            </thead>
            <tbody>
            <?php $position = 0; foreach ($standings as $row) : $position++; ?>
                <tr>
                    <td class="ido-right"><?php echo esc_html((string) $position); ?></td>
                    <td><?php echo esc_html($row->kingdom_name); ?></td>
                    <td><?php echo esc_html($row->ruler_name); ?></td>
                    <td><?php echo esc_html(IDO_Game::title((int) $row->networth)); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($row->land)); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($row->networth)); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($row->attacks_won)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($count > count($standings)) : ?>
            <p class="ido-dim">
                <?php echo esc_html(sprintf('Showing the top %d of %d kingdoms.', count($standings), $count)); ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php $hall = IDO_Rankings::hall(10, 1); ?>
<?php if ($hall) : ?>
    <div class="ido-panel">
        <h3 class="ido-panel-title">Champions of rounds past</h3>
        <table class="ido-table ido-table-wide">
            <thead><tr><th>Round</th><th>Kingdom</th><th>Ruler</th><th>Title</th><th class="ido-right">Net worth</th></tr></thead>
            <tbody>
            <?php foreach ($hall as $row) : ?>
                <tr>
                    <td><?php echo esc_html($row->round_name); ?></td>
                    <td><?php echo esc_html($row->kingdom_name); ?></td>
                    <td><?php echo esc_html($row->ruler_name); ?></td>
                    <td><?php echo esc_html($row->title); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($row->networth)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
