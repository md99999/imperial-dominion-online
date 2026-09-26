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
