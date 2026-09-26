<?php
if (!defined('ABSPATH')) exit;
global $wpdb;
$current = IDO_Rounds::current();
$rounds = $wpdb->get_results('SELECT * FROM ' . IDO_DB::t('rounds') . ' ORDER BY id DESC LIMIT 50');
?>
<div class="wrap">
    <h1>Rounds</h1>
    <?php IDO_Admin::notice(); ?>

    <p>
        A round runs for a fixed number of days. When it ends, the standings are copied into the Hall of Fame,
        every empire is retired and, if the setting allows, the next round opens at once.
    </p>

    <h2>Open a new round</h2>
    <p><strong>This retires every empire in the round currently running.</strong></p>
    <?php echo IDO_Admin::form_open('start_round', 'ido_rounds'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Name</th>
                <td>
                    <code><?php echo esc_html(sprintf('Round %d', (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . IDO_DB::t('rounds')) + 1)); ?></code>
                    <p class="description">Rounds are numbered automatically.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ido_round_days">Length in days</label></th>
                <td><input type="number" id="ido_round_days" name="round_days" min="1" step="1"
                           value="<?php echo esc_attr((string) IDO_Settings::int('round_days')); ?>"></td>
            </tr>
        </table>
        <p><button type="submit" class="button button-primary">Start a new round</button></p>
    </form>

    <?php if ($current) : ?>
        <h2>End the round now</h2>
        <p>
            Concludes <strong><?php echo esc_html($current->round_name); ?></strong> immediately, without waiting for
            its end date. The standings are archived first.
        </p>
        <?php echo IDO_Admin::form_open('end_round', 'ido_rounds'); ?>
            <p><button type="submit" class="button">Conclude <?php echo esc_html($current->round_name); ?></button></p>
        </form>
    <?php endif; ?>

    <h2>History</h2>
    <table class="widefat striped">
        <thead><tr><th>Round</th><th>Status</th><th>Started</th><th>Ends</th><th>Completed</th></tr></thead>
        <tbody>
        <?php foreach ($rounds as $round) : ?>
            <tr>
                <td><?php echo esc_html($round->round_name); ?></td>
                <td><?php echo esc_html($round->status); ?></td>
                <td><?php echo esc_html((string) $round->starts_at); ?></td>
                <td><?php echo esc_html((string) $round->ends_at); ?></td>
                <td><?php echo esc_html((string) $round->completed_at); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
