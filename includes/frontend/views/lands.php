<?php
/**
 * Lands: the holdings and the market, on one page.
 *
 * The market was its own tab and could not earn one. Buying grain is what a
 * ruler does about grain, and growing it is the other half of the same thought,
 * so the two belong beside each other rather than a page apart -- particularly
 * now that a drought or a plague of insects can take a share of the harvest
 * between one order and the next.
 *
 * Both halves keep their own file. They set up their own variables and share no
 * names, and the market half reads $_GET['item'] for its filter, which the
 * holdings half neither sets nor cares about.
 *
 * @var object $kingdom
 */
if (!defined('ABSPATH')) exit;

$ido_sections = [
    'holdings' => 'Your holdings',
    'market'   => 'The market',
];
?>

<nav class="ido-subnav" aria-label="Lands sections">
    <?php foreach ($ido_sections as $anchor => $label) : ?>
        <a class="ido-subnav-item" href="#ido-<?php echo esc_attr($anchor); ?>">
            <?php echo esc_html($label); ?>
        </a>
    <?php endforeach; ?>
</nav>

<div id="ido-holdings" class="ido-section">
    <?php include IDO_PATH . 'includes/frontend/views/partials/landholdings.php'; ?>
</div>

<div id="ido-market" class="ido-section">
    <?php include IDO_PATH . 'includes/frontend/views/partials/market.php'; ?>
</div>
