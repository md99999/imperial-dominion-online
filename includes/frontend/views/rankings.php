<?php
/** Rankings: the standings this round, and the champions of rounds past. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
$standings = IDO_Rankings::standings((int) $kingdom->round_id, 100);
$hall      = IDO_Rankings::hall(60, 3);
$position  = 0;
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">Standings</h3>
    <table class="ido-table ido-table-wide">
        <thead>
            <tr><th class="ido-right">#</th><th>Empire</th><th>Ruler</th><th>Title</th>
                <th class="ido-right">Acres</th><th class="ido-right">Net worth</th><th class="ido-right">Victories</th></tr>
        </thead>
        <tbody>
        <?php foreach ($standings as $row) :
            $position++;
            $is_me = (int) $row->id === (int) $kingdom->id; ?>
            <tr class="<?php echo $is_me ? 'ido-row-me' : ''; ?>">
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
</div>

<?php
/*
 * Listed, but not in the standings above.
 *
 * They are not players and must never be ranked against them -- a province
 * cannot win a round and has no business in the hall of fame. But leaving them
 * off the page entirely meant a ruler browsing who is out there saw nothing at
 * all, and the only way to discover them was to open the War Dept with one
 * already in reach. Their own table says both things at once: here they are,
 * and they are not in the running.
 */
$ido_masterless = IDO_Rivals::enabled()
    ? IDO_Rivals::all((int) $kingdom->round_id)
    : [];
?>
<?php if ($ido_masterless) : ?>
    <div class="ido-panel">
        <h3 class="ido-panel-title">Masterless provinces</h3>
        <p class="ido-dim">
            Land of the old empire that no living ruler holds. They take no part in the standings
            and cannot win a round, but they can be marched on like anybody else &mdash; and what
            they hold is worth taking. Strip one and it mends a little each day.
        </p>
        <p class="ido-dim">
            Acres and net worth are what anyone can see from the road. What stands behind the walls
            is not: send an <strong>informer</strong> from the
            <a href="<?php echo esc_url(IDO_UI::url('war')); ?>#ido-spies">spy court</a> and you will
            know the army and the stores before you commit one soldier. An informer is the cheap
            spy, and reconnaissance is the whole of what it does.
        </p>
        <table class="ido-table ido-table-wide">
            <thead>
                <tr><th>Province</th><th>Held by</th><th class="ido-right">Acres</th><th class="ido-right">Net worth</th><th>Report</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($ido_masterless as $ido_m) : ?>
                <tr>
                    <td><?php echo esc_html($ido_m->kingdom_name); ?></td>
                    <td class="ido-dim"><?php echo esc_html($ido_m->ruler_name); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($ido_m->land)); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($ido_m->networth)); ?></td>
                    <td>
                        <?php /* Acres and net worth are what anyone can see from the
                                 road. What is actually behind the walls -- the army,
                                 the granaries -- is what an informer is for, and the
                                 cheap one is enough: reconnaissance is all it does. */ ?>
                        <?php if (IDO_Covert::scouted($kingdom, (int) $ido_m->id)) : ?>
                            <span class="ido-good">Scouted</span>
                        <?php else : ?>
                            <span class="ido-dim">None &mdash; send an informer</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="ido-btn ido-btn-small ido-btn-alt"
                           href="<?php echo esc_url(IDO_UI::url('war', ['target' => (int) $ido_m->id])); ?>">Look</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="ido-panel">
    <h3 class="ido-panel-title">Hall of Fame</h3>
    <?php if (!$hall) : ?>
        <p class="ido-dim">No round has been carved into the stone yet.</p>
    <?php else : ?>
        <table class="ido-table ido-table-wide">
            <thead><tr><th>Round</th><th class="ido-right">#</th><th>Empire</th><th>Ruler</th><th>Title</th><th class="ido-right">Net worth</th></tr></thead>
            <tbody>
            <?php foreach ($hall as $row) : ?>
                <tr>
                    <td><?php echo esc_html($row->round_name); ?></td>
                    <td class="ido-right"><?php echo esc_html((string) $row->position); ?></td>
                    <td><?php echo esc_html($row->kingdom_name); ?></td>
                    <td><?php echo esc_html($row->ruler_name); ?></td>
                    <td><?php echo esc_html($row->title); ?></td>
                    <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($row->networth)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
