<?php
if (!defined('ABSPATH')) exit;
$round = IDO_Rounds::current();
$kingdoms = $round ? IDO_Rankings::kingdom_count((int) $round->id) : 0;
$page_ids = get_option('ido_page_ids', []);
$pages_made = is_array($page_ids) ? count($page_ids) : 0;
?>
<div class="wrap">
    <h1>Imperial Dominion Online</h1>
    <?php IDO_Admin::notice(); ?>

    <?php $activation_error = get_option('ido_activation_error'); ?>
    <?php if ($activation_error) : ?>
        <div class="notice notice-error">
            <p><strong>Activation did not finish cleanly:</strong> <?php echo esc_html((string) $activation_error); ?></p>
            <p>Open a round by hand on the Rounds screen once the problem is fixed.</p>
        </div>
    <?php endif; ?>

    <?php $ido_issues = IDO_Health::issues(); ?>
    <h2>Install health</h2>
    <div class="card" style="max-width:860px;padding:4px 16px 12px<?php echo $ido_issues ? ';border-left:4px solid #d63638' : ''; ?>">
        <p class="description">
            How this copy was installed, and whether that will cause trouble later. Running from
            <code><?php echo esc_html(IDO_Health::folder()); ?></code>.
        </p>
        <?php if (!$ido_issues) : ?>
            <p><strong>Nothing to report.</strong> The plugin is in the folder updates expect, there is
            only one copy of it, and no repository furniture is sitting in your site.</p>
        <?php else : ?>
            <?php foreach ($ido_issues as $issue) : ?>
                <h3 style="margin-bottom:4px;color:<?php echo $issue['level'] === 'error' ? '#d63638' : '#996800'; ?>">
                    <?php echo esc_html($issue['title']); ?>
                </h3>
                <?php
                // Built in IDO_Health, which escapes every value it interpolates;
                // the tags around them are the point of it being HTML.
                echo $issue['body'];
                ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <p class="description">
            Building an installable zip from a checkout is covered in the plugin's
            <code>README.md</code>, under <em>Building the installable zip</em>.
        </p>
    </div>

    <h2>Where the game stands</h2>
    <table class="widefat striped" style="max-width:760px">
        <tbody>
            <tr><th>Round</th><td><?php echo $round ? esc_html($round->round_name) : 'None running'; ?></td></tr>
            <tr><th>Ends</th><td><?php echo $round && $round->ends_at ? esc_html($round->ends_at) : '&mdash;'; ?></td></tr>
            <tr><th>Empires</th><td><?php echo esc_html((string) $kingdoms); ?></td></tr>
            <tr><th>Game pages</th><td><?php echo esc_html(sprintf('%d of %d created', $pages_made, count(IDO_UI::PAGES))); ?></td></tr>
            <tr><th>Last daily upkeep</th><td><?php echo esc_html((string) get_option('ido_last_daily', 'never')); ?></td></tr>
            <tr><th>Last hourly upkeep</th><td><?php echo esc_html((string) get_option('ido_last_hourly', 'never')); ?></td></tr>
        </tbody>
    </table>

    <h2>Game pages</h2>
    <p>Each page below holds a single shortcode. Create them once and the navigation inside the game links them together.</p>
    <?php echo IDO_Admin::form_open('create_pages', 'ido_dashboard'); ?>
        <p><button type="submit" class="button button-primary">Create any missing game pages</button></p>
    </form>

    <table class="widefat striped" style="max-width:760px">
        <thead><tr><th>Page</th><th>Shortcode</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach (IDO_UI::PAGES as $key => $def) :
            $id = is_array($page_ids) && !empty($page_ids[$key]) ? (int) $page_ids[$key] : 0;
            $post = $id ? get_post($id) : get_page_by_path($def[1]); ?>
            <tr>
                <td><?php echo esc_html($def[0]); ?></td>
                <td><code>[<?php echo esc_html($def[2]); ?>]</code></td>
                <td>
                    <?php if ($post) : ?>
                        <a href="<?php echo esc_url((string) get_permalink($post)); ?>">View</a> &middot;
                        <a href="<?php echo esc_url((string) get_edit_post_link($post->ID)); ?>">Edit</a>
                    <?php else : ?>
                        Not created
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
