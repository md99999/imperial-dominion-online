<?php
/**
 * The War Dept: the muster, the war room and the spy court on one page.
 *
 * These were three pages, and the three of them are one decision. Raising
 * troops, choosing who to march on and sending an agent ahead are steps in a
 * single evening's play, and splitting them across three screens meant a ruler
 * worked out a target, went to another page to train for it, came back to find
 * the standings had moved, and went to a third to decide about the agent. Every
 * one of those trips cost the thing the page was about: deciding.
 *
 * Each section is still its own file, because they are long and they have
 * nothing to say to each other. They run in order and each sets up its own
 * variables before use, so the one name they share -- $targets, which the war
 * room wants few of and the spy court wants many -- cannot collide.
 *
 * @var object $kingdom
 */
if (!defined('ABSPATH')) exit;

$ido_sections = [
    'army'  => 'The muster',
    'war'   => 'The war room',
    'spies' => 'The spy court',
];
?>

<nav class="ido-subnav" aria-label="War Dept sections">
    <?php foreach ($ido_sections as $anchor => $label) : ?>
        <a class="ido-subnav-item" href="#ido-<?php echo esc_attr($anchor); ?>">
            <?php echo esc_html($label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<div id="ido-army" class="ido-section">
    <?php include IDO_PATH . 'includes/frontend/views/partials/army.php'; ?>
</div>

<div id="ido-war" class="ido-section">
    <?php include IDO_PATH . 'includes/frontend/views/partials/warroom.php'; ?>
</div>

<div id="ido-spies" class="ido-section">
    <?php include IDO_PATH . 'includes/frontend/views/partials/spies.php'; ?>
</div>
