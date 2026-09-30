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

$limit   = isset($ido_news_limit) ? (int) $ido_news_limit : 100;
$compact = !empty($ido_news_compact);

/*
 * Compact is widget mode, and it drops two whole types.
 *
 * 'league' carries both the drama and the plumbing under one name -- a march
 * won and a muster's escrow accounting and "the heralds have lost their place
 * in the ledgers", which is a database restore notice and the worst possible
 * line to greet a prospective player with. Until those are separate types this
 * is blunt on purpose: on a league board that traffic would otherwise fill
 * every slot in a ten-line widget with cross-site book-keeping.
 *
 * 'market' is dropped for being repetitive rather than for being noise. It
 * reads well on the page, where there is room for it.
 *
 * Everything else stays, including 'round' and 'reset': a new round beginning
 * with the land unclaimed is the best recruiting line the game has.
 */
$exclude = $compact ? ['league', 'market'] : [];
$news    = $round_id > 0 ? IDO_Rankings::news($round_id, $limit, $exclude) : [];
?>
<?php
/*
 * The heading. On the page it names the world, because a player reading it is
 * already inside that world and wants to know which one. An embed is read by
 * somebody who may be neither, so it takes a plain title naming the game, which
 * the shortcode supplies and a page author can replace or empty.
 */
$title = isset($ido_news_title)
    ? (string) $ido_news_title
    : IDO_Game::dominion() . ' Gazette';
?>
<div class="ido-panel<?php echo $compact ? ' ido-news-compact' : ''; ?>">
    <?php if ($title !== '') : ?>
        <h3 class="ido-panel-title"><?php echo esc_html($title); ?></h3>
    <?php endif; ?>
    <?php if ($round && !$compact) : ?>
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
                    <?php if ($compact) : ?>
                        <?php /* Relative, because a sidebar has no room for a date and
                                 "3 hours ago" says what a reader actually wants to know:
                                 whether this board is alive today. */ ?>
                        <span class="ido-news-time"><?php
                            echo esc_html(sprintf(
                                /* translators: %s is a length of time, e.g. "3 hours" */
                                __('%s ago', 'imperial-dominion-online'),
                                human_time_diff(strtotime($item->created_at), current_time('timestamp'))
                            ));
                        ?></span>
                    <?php else : ?>
                        <span class="ido-news-time"><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($item->created_at))); ?></span>
                        <span class="ido-news-type ido-news-<?php echo esc_attr($item->event_type); ?>"><?php echo esc_html($item->event_type); ?></span>
                    <?php endif; ?>
                    <span class="ido-news-text"><?php echo esc_html((string) $item->message); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
