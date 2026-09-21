<?php
/** Shown to a signed-in user who does not yet rule a kingdom in the open round. */
if (!defined('ABSPATH')) exit;
$user = wp_get_current_user();
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">Claim a kingdom</h3>
    <p>
        The old empire is broken and its provinces lie open. Name your kingdom, take the throne, and take your place among the kingdoms of <strong><?php echo esc_html(IDO_Game::dominion()); ?></strong>:
        you begin with <?php echo esc_html(IDO_Game::fmt(IDO_Settings::int('starting_land'))); ?> acres,
        a small treasury and a crown truce of <?php echo esc_html((string) IDO_Settings::int('protection_hours')); ?> hours
        that no one may break.
    </p>
    <?php if (!IDO_Settings::int('allow_new_kingdoms')) : ?>
        <p class="ido-warning">The heralds are turning away new claimants for now. Ask the game master when the next round opens.</p>
    <?php else : ?>
        <?php echo IDO_UI::form_open('found_kingdom'); ?>
            <label class="ido-field">
                <span>Name of your kingdom</span>
                <input type="text" name="kingdom_name" maxlength="40" required
                       placeholder="Vaelmark" class="ido-text">
            </label>
            <label class="ido-field">
                <span>Name of its ruler</span>
                <input type="text" name="ruler_name" maxlength="40"
                       value="<?php echo esc_attr($user->display_name); ?>" class="ido-text">
            </label>
            <p class="ido-dim">
                Both names must be unique among the kingdoms of this round, so no two rulers can be
                confused for one another in the gazette or on a battle report. They are released again
                when the round ends.
            </p>
            <p>
                <button type="submit" class="ido-btn">Raise your banner</button>
                <a class="ido-btn ido-btn-alt" href="<?php echo esc_url(IDO_UI::url('guide')); ?>">Read how to play first</a>
            </p>
        </form>
    <?php endif; ?>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">How a round is played</h3>
    <ul class="ido-list">
        <li>You are granted <?php echo esc_html((string) IDO_Settings::int('turns_per_day')); ?> turns a day, stored up to
            <?php echo esc_html((string) IDO_Settings::int('turn_cap')); ?>. Every order costs turns, and every turn spent
            pays out your kingdom income at once. Hoarding turns earns you nothing.</li>
        <li>Settle wilderness, raise buildings on it, and turn peasants into soldiers.</li>
        <li>March on rival kingdoms to take their acres, their stores or their walls.</li>
        <li>Trade on the open market, where rulers set their own prices.</li>
        <li>The round ends after <?php echo esc_html((string) IDO_Settings::int('round_days')); ?> days. The standings are
            carved into the Hall of Fame and the land is opened again for everyone.</li>
    </ul>
</div>
