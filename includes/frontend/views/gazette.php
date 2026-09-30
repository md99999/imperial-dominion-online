<?php
/**
 * Gazette: what the empires have been saying about each other.
 *
 * Readable by anyone, signed in or not. It reads the round rather than the
 * reader, because a visitor who has not claimed an empire still has a round to
 * look at, and the whole point of a public gazette is that somebody who is not
 * playing can see the game being played.
 *
 * $kingdom is optional here and may be null or absent. $ido_news_limit is
 * optional too: the embed sets it, the page leaves it alone.
 */
if (!defined('ABSPATH')) exit;
/** @var object|null $kingdom */
$round = IDO_Rounds::current();

// The reader's round when there is a reader, otherwise whatever is running.
$round_id = isset($kingdom) && $kingdom && isset($kingdom->round_id)
    ? (int) $kingdom->round_id
    : (int) ($round->id ?? 0);

$limit = isset($ido_news_limit) ? (int) $ido_news_limit : 100;
$news  = $round_id > 0 ? IDO_Rankings::news($round_id, $limit) : [];
?>
<div class="ido-panel">
    <h3 class="ido-panel-title"><?php echo esc_html(IDO_Game::dominion()); ?> Gazette</h3>
    <?php if ($round) : ?>
        <p class="ido-dim">
            <?php echo esc_html($round->round_name); ?>
            <?php $left = IDO_Rounds::days_left($round); ?>
            <?php if ($left !== null) : ?>
                &middot; <?php echo esc_html(sprintf(_n('%d day remains', '%d days remain', $left, 'imperial-dominion-online'), $left)); ?>
            <?php endif; ?>
            &middot; <?php echo esc_html(sprintf('%d empires', IDO_Rankings::kingdom_count($round_id))); ?>
        </p>
    <?php endif; ?>

    <?php if (!$news) : ?>
        <p class="ido-dim">The criers have nothing to report.</p>
    <?php else : ?>
        <ul class="ido-news">
            <?php foreach ($news as $item) : ?>
                <li>
                    <span class="ido-news-time"><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($item->created_at))); ?></span>
                    <span class="ido-news-type ido-news-<?php echo esc_attr($item->event_type); ?>"><?php echo esc_html($item->event_type); ?></span>
                    <span class="ido-news-text"><?php echo esc_html((string) $item->message); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
