<?php
if (!defined('ABSPATH')) exit;
/**
 * The force fields, shared by calling a muster and joining one.
 *
 * One copy, because the two forms have to offer exactly the same thing: a caller
 * who could pledge differently from a joiner would be a rule nobody wrote down.
 *
 * Expects $kingdom, and $march when joining an open muster.
 */
$agent_taken = isset($march) && (int) $march->agent_kingdom_id > 0;
$agent_mine  = $agent_taken && (int) $march->agent_kingdom_id === (int) $kingdom->id;
?>
<table class="ido-table">
    <thead><tr><th>Troops</th><th class="ido-right">Under arms</th><th>Send</th></tr></thead>
    <tbody>
    <?php foreach (IDO_Units::all() as $key => $unit) :
        $have = (int) $kingdom->{IDO_Units::column($key)};
        if ($have < 1) continue;
    ?>
        <tr>
            <td><?php echo esc_html($unit['plural']); ?></td>
            <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($have)); ?></td>
            <td><?php echo IDO_UI::number_field('force_' . $key, 0, 0); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php
$has_weapons = false;
foreach (IDO_Weapons::keys() as $key) {
    if ((int) $kingdom->{IDO_Weapons::column($key)} > 0) { $has_weapons = true; break; }
}
?>
<?php if ($has_weapons) : ?>
    <table class="ido-table">
        <thead><tr><th>Siege train</th><th class="ido-right">Standing</th><th>Send</th></tr></thead>
        <tbody>
        <?php foreach (IDO_Weapons::all() as $key => $weapon) :
            $have = (int) $kingdom->{IDO_Weapons::column($key)};
            if ($have < 1) continue;
        ?>
            <tr>
                <td><?php echo esc_html($weapon['plural']); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($have)); ?></td>
                <td><?php echo IDO_UI::number_field('weapon_' . $key, 0, 0); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="ido-dim">
        A siege weapon needs its crew in the same force, or the order is refused rather than sending a
        train nobody can work.
    </p>
<?php endif; ?>

<?php /* One agent to a march, first to offer takes it. */ ?>
<?php if ((int) $kingdom->agents > 0 && !$agent_taken) : ?>
    <p>
        <label>
            <input type="checkbox" name="send_agent" value="1">
            Send my agent ahead
            (<?php echo esc_html(IDO_Game::fmt(IDO_League_Covert::cost())); ?> gold in bribes)
        </label>
    </p>
    <p class="ido-dim">
        He rides ahead of the army and tries to open the walls. If he gets in, the enemy fortifications
        count for less when the assault comes. If he is caught, he hangs and you have lost him. Only one
        agent goes with an army, and the first ruler to offer theirs takes the place.
    </p>
<?php elseif ($agent_mine) : ?>
    <p class="ido-dim">Your agent is already riding ahead of this army.</p>
<?php elseif ($agent_taken) : ?>
    <p class="ido-dim">Another ruler has already sent their agent ahead. Only one goes with the army.</p>
<?php elseif ((int) $kingdom->agents < 1) : ?>
    <p class="ido-dim">
        You keep no agent. One can be hired at the <a href="<?php echo esc_url(IDO_UI::url('war')); ?>#ido-spies">spy court</a>.
    </p>
<?php endif; ?>
