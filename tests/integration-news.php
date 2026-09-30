<?php
/**
 * Integration test: the public gazette, its embed, and the events that feed it.
 *
 * The point of this one is the logged-out reader. Everything else in the game
 * assumes somebody signed in with an empire, and the gazette is the first view
 * that has to render for somebody who has neither. A view that reads
 * $kingdom->round_id is fine right up until $kingdom is null, and then it is a
 * fatal on a public page.
 *
 *   php tests/integration-news.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-news.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-62s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }
$round_id = (int) $round->id;

$tag = 'NEWSTEST' . wp_rand(1000, 9999);
$held_leader = get_option('ido_leader', false);

register_shutdown_function(static function () use ($tag, $round_id, $held_leader) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('news') . ' WHERE message LIKE %s', '%' . $tag . '%'));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('kingdoms') . ' WHERE kingdom_name LIKE %s', '%' . $tag . '%'));
    if ($held_leader === false) { delete_option('ido_leader'); } else { update_option('ido_leader', $held_leader); }
    wp_set_current_user(0);
});

// Nobody is signed in. This is the whole point.
wp_set_current_user(0);
check('the reader is logged out', !is_user_logged_in());

for ($i = 1; $i <= 20; $i++) {
    IDO_Log::news('war', sprintf('%s item %02d: an empire marched and was thrown back.', $tag, $i), $round_id);
}

say('');
say('=== the embed, for a widget ===');
$out = do_shortcode('[ido_news]');
check('the shortcode renders something', strlen($out) > 100, strlen($out) . ' bytes');
check('and it is the gazette', strpos($out, 'Gazette') !== false);
check('with no PHP notice leaking into it',
    stripos($out, 'warning:') === false && stripos($out, 'notice:') === false
    && stripos($out, 'fatal') === false);
check('a logged-out reader sees the news itself', strpos($out, $tag) !== false);

$count = substr_count($out, '<li>');
check('twelve items by default, not everything', $count === 12, (string) $count);

$five = do_shortcode('[ido_news limit="5"]');
check('the limit is honoured', substr_count($five, '<li>') === 5,
    (string) substr_count($five, '<li>'));

$huge = do_shortcode('[ido_news limit="99999"]');
check('an absurd limit is clamped rather than passed to SQL',
    substr_count($huge, '<li>') <= 100, (string) substr_count($huge, '<li>'));

$zero = do_shortcode('[ido_news limit="0"]');
check('and so is zero', substr_count($zero, '<li>') >= 1, (string) substr_count($zero, '<li>'));

$bare = do_shortcode('[ido_news heading="no"]');
check('the heading can be suppressed for a widget with its own title',
    strpos($bare, 'ido-title') === false);
check('but the news is still there', strpos($bare, $tag) !== false);

$nocta = do_shortcode('[ido_news cta="no"]');
check('the call to action can be suppressed',
    strpos($nocta, 'Take an empire') === false);
check('and is present by default for a visitor', strpos($out, 'Take an empire') !== false);
check('the ordinary embed keeps its heading', strpos($out, 'ido-title') !== false);
check('and its panel title', strpos($out, 'ido-panel-title') !== false);

say('');
say('=== compact, for a sidebar ===');
IDO_Log::news('league', sprintf('%s LEAGUEITEM: the heralds have lost their place.', $tag), $round_id);
IDO_Log::news('market', sprintf('%s MARKETITEM: grain changed hands.', $tag), $round_id);

$c10 = do_shortcode('[ido_news limit="10" compact="1"]');
// Deliberately not looking for the word "Gazette": compact has no heading, so
// the news list itself is the only thing that proves it rendered.
check('it renders', strpos($c10, 'ido-news') !== false && substr_count($c10, '<li>') > 0);
check('ten items', substr_count($c10, '<li>') === 10, (string) substr_count($c10, '<li>'));
check('the compact class is on the panel', strpos($c10, 'ido-news-compact') !== false);
check('league house-keeping is left out', strpos($c10, 'LEAGUEITEM') === false);
check('and so is the market', strpos($c10, 'MARKETITEM') === false);
check('the type chip is dropped', strpos($c10, 'ido-news-type') === false);
check('the time is relative, not a date', strpos($c10, 'ago') !== false);
check('the round meta line is dropped', strpos($c10, 'empires') === false);
check('it starts at the news: no world name above it',
    strpos($c10, 'ido-title') === false);
check('and no panel heading either', strpos($c10, 'ido-panel-title') === false);

$c_head = do_shortcode('[ido_news limit="5" compact="1" heading="yes"]');
check('asking for the heading brings it back',
    strpos($c_head, 'ido-title') !== false && strpos($c_head, 'ido-panel-title') !== false);
check('but the news still reads', strpos($c10, $tag) !== false);

$full = do_shortcode('[ido_news limit="40"]');
check('the page-style embed still shows the league item', strpos($full, 'LEAGUEITEM') !== false);
check('and still shows the type chip', strpos($full, 'ido-news-type') !== false);

$page_all = IDO_Shortcodes::render('gazette');
check('the Gazette page is unaffected and shows everything',
    strpos($page_all, 'LEAGUEITEM') !== false && strpos($page_all, 'MARKETITEM') !== false);

say('');
say('=== the stylesheet reaches the head, not the footer ===');
// A widget is not post content. If nothing notices the tag before wp_head, the
// CSS arrives after the markup and repaints the sidebar in front of the reader.
$held_block = get_option('widget_block');
$embeds = new ReflectionMethod('IDO_UI', 'widgets_embed_news');
$embeds->setAccessible(true);

update_option('widget_block', ['_multiwidget' => 1]);
check('nothing is claimed when no widget holds the tag', !$embeds->invoke(null));

update_option('widget_block', ['_multiwidget' => 1, 2 => [
    'content' => '<!-- wp:shortcode -->[ido_news limit="10" compact="1"]<!-- /wp:shortcode -->']]);
check('a block widget holding it is found', $embeds->invoke(null));

wp_dequeue_style('imperial-dominion-online');
IDO_UI::enqueue_assets();
check('and the style is enqueued before the page renders',
    wp_style_is('imperial-dominion-online', 'enqueued'));

update_option('widget_block', ['_multiwidget' => 1]);
$held_text = get_option('widget_text');
update_option('widget_text', ['_multiwidget' => 1, 3 => ['text' => '[ido_news]']]);
check('a classic text widget counts too', $embeds->invoke(null));

if ($held_text === false) { delete_option('widget_text'); } else { update_option('widget_text', $held_text); }
if ($held_block === false) { delete_option('widget_block'); } else { update_option('widget_block', $held_block); }
check('and the options are put back as they were', !$embeds->invoke(null));

say('');
say('=== the gazette page itself, logged out ===');
$page = IDO_Shortcodes::render('gazette');
check('the page renders for somebody with no empire', strpos($page, 'Gazette') !== false);
check('it shows the news rather than a sign-in wall', strpos($page, $tag) !== false);
check('and still invites them in', stripos($page, 'empire') !== false);

say('');
say('=== the lead changing hands ===');
// Distinct user ids, because one ruler holds one empire a round and the table
// enforces it. Three fixtures sharing user_id 0 means two silent failed inserts
// and a test that proves nothing.
$next_user = 990001;
$make = static function (string $name, int $worth) use ($round_id, $tag, &$next_user): int {
    global $wpdb;
    $wpdb->insert(IDO_DB::t('kingdoms'), [
        'round_id' => $round_id, 'user_id' => $next_user++,
        'kingdom_name' => $tag . ' ' . $name, 'ruler_name' => $name,
        'networth' => $worth, 'land' => 100, 'is_defeated' => 0,
        'created_at' => IDO_Game::now(),
    ]);
    return (int) $wpdb->insert_id;
};
// Built above whatever is already on this board, so the test does not depend on
// the dev site being empty. A real empire outranking the fixtures is not a bug
// in the code under test, but it is a broken test.
$ceiling = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COALESCE(MAX(networth), 0) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d',
    $round_id));
$base = $ceiling + 1000000;
$a = $make('Aldric', $base + 200000);
$b = $make('Boran',  $base + 100000);
$c = $make('Cassia', $base);

check('the three fixture empires were really created',
    $a > 0 && $b > 0 && $c > 0, "$a, $b, $c");

$announce = new ReflectionMethod('IDO_Maintenance', 'announce_leader');
$announce->setAccessible(true);

$before = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s',
    $round_id, 'rankings'));

delete_option('ido_leader');
$announce->invoke(null, $round_id);
$first = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s',
    $round_id, 'rankings'));
check('the first look at a round announces nothing', $first === $before,
    ($first - $before) . ' new');
check('but it remembers who is in front',
    strpos((string) get_option('ido_leader'), $round_id . ':' . $a) === 0,
    (string) get_option('ido_leader'));

$announce->invoke(null, $round_id);
$again = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s',
    $round_id, 'rankings'));
check('nothing is said while the lead does not move', $again === $before);

// Boran overtakes.
$overtake = $base + 500000;
$wpdb->update(IDO_DB::t('kingdoms'), ['networth' => $overtake], ['id' => $b]);
$announce->invoke(null, $round_id);
$moved = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT message FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s'
    . ' ORDER BY id DESC LIMIT 1', $round_id, 'rankings'));
check('taking the lead is news', strpos($moved, 'Boran') !== false, $moved);
check('and it quotes the net worth', strpos($moved, IDO_Game::fmt($overtake)) !== false,
    IDO_Game::fmt($overtake));

// A defeated empire must never be crowned.
$wpdb->update(IDO_DB::t('kingdoms'), ['is_defeated' => 1], ['id' => $b]);
$announce->invoke(null, $round_id);
$next = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT message FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s'
    . ' ORDER BY id DESC LIMIT 1', $round_id, 'rankings'));
check('a fallen empire does not hold the lead', strpos($next, 'Aldric') !== false, $next);

say('');
say('=== the other new criers are wired in ===');
$covert = file_get_contents(IDO_PATH . 'includes/services/class-covert-service.php');
check('a hanged agent reaches the gazette', strpos($covert, 'hanged before the gates') !== false);
check('and does not name whose agent it was',
    strpos($covert, 'hanged before the gates') !== false
    && strpos($covert, "hanged before the gates', \$kingdom->kingdom_name") === false);

$market = file_get_contents(IDO_PATH . 'includes/services/class-market-service.php');
check('a large trade reaches the gazette', strpos($market, "IDO_Log::news('market'") !== false);
check('and a small one does not', strpos($market, 'NEWS_MIN_GOLD') !== false);
check('the threshold is a real number', IDO_Market::NEWS_MIN_GOLD > 0,
    (string) IDO_Market::NEWS_MIN_GOLD);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
