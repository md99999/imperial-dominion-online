<?php
if (!defined('ABSPATH')) exit;
$round = IDO_Rounds::current();
$kingdoms = $round ? IDO_Rankings::standings((int) $round->id, 200) : [];
?>
<div class="wrap">
    <h1>Kingdoms</h1>
    <?php IDO_Admin::notice(); ?>

    <?php if (!$round) : ?>
        <p>No round is running.</p>
    <?php else : ?>
        <p>Standings for <strong><?php echo esc_html($round->round_name); ?></strong>.</p>
        <table class="widefat striped">
            <thead>
                <tr><th>#</th><th>Kingdom</th><th>Ruler</th><th>WordPress user</th>
                    <th>Acres</th><th>Net worth</th><th>Won</th><th>Suffered</th><th></th></tr>
            </thead>
            <tbody>
            <?php $position = 0; foreach ($kingdoms as $kingdom) :
                $position++;
                $user = get_userdata((int) $kingdom->user_id); ?>
                <tr>
                    <td><?php echo esc_html((string) $position); ?></td>
                    <td><?php echo esc_html($kingdom->kingdom_name); ?></td>
                    <td><?php echo esc_html($kingdom->ruler_name); ?></td>
                    <td><?php echo $user ? esc_html($user->user_login) : '&mdash;'; ?></td>
                    <td><?php echo esc_html(IDO_Game::fmt($kingdom->land)); ?></td>
                    <td><?php echo esc_html(IDO_Game::fmt($kingdom->networth)); ?></td>
                    <td><?php echo esc_html(IDO_Game::fmt($kingdom->attacks_won)); ?></td>
                    <td><?php echo esc_html(IDO_Game::fmt($kingdom->attacks_suffered)); ?></td>
                    <td>
                        <?php echo IDO_Admin::form_open('delete_kingdom', 'ido_kingdoms'); ?>
                            <input type="hidden" name="kingdom_id" value="<?php echo esc_attr((string) $kingdom->id); ?>">
                            <button type="submit" class="button button-link-delete"
                                onclick="return confirm('Delete this kingdom permanently?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
