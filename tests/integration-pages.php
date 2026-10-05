<?php
/**
 * Integration test: the pages that were folded into other pages.
 *
 * The War Dept took the muster, the war room and the spy court. Lands took the
 * market.
 *
 * The risk in folding three pages into one is not that the new page fails to
 * render. It is everything that pointed at the old ones: a shortcode sitting in
 * a page an administrator created a year ago, a link in a menu, a recorded page
 * id. None of that can be allowed to go dark.
 *
 *   php tests/integration-pages.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-pages.php /path/to/wordpress [db-host]'); exit(2); }
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

// Played as a real ruler, because the sections render nothing without one.
$ruler = $wpdb->get_row($wpdb->prepare(
    'SELECT user_id FROM ' . IDO_DB::t('kingdoms')
    . ' WHERE round_id = %d AND is_defeated = 0 AND user_id > 0 LIMIT 1', (int) $round->id));
if (!$ruler) { say('No empire on this board to render as.'); exit(2); }
wp_set_current_user((int) $ruler->user_id);
register_shutdown_function(static fn() => wp_set_current_user(0));

say('=== the page map ===');
check('the war page is the War Dept',
    IDO_UI::PAGES['war'][0] === 'Imperial Dominion - War Dept', IDO_UI::PAGES['war'][0]);
check('and its tab says so', IDO_UI::PAGES['war'][3] === 'War Dept', IDO_UI::PAGES['war'][3]);
check('the Army page is gone from the map', !isset(IDO_UI::PAGES['military']));
check('and so is the Spy Court', !isset(IDO_UI::PAGES['covert']));
check('the slug did not change, so no shared link breaks',
    IDO_UI::PAGES['war'][1] === 'imperial-dominion-online-war', IDO_UI::PAGES['war'][1]);

say('');
say('=== the page itself ===');
$html = IDO_Shortcodes::render('war');
check('it renders', strlen($html) > 2000, strlen($html) . ' bytes');
foreach (['ido-army' => 'the muster', 'ido-war' => 'the war room', 'ido-spies' => 'the spy court'] as $id => $what) {
    check("it carries $what", strpos($html, 'id="' . $id . '"') !== false);
}
check('with jump links between them', substr_count($html, 'ido-subnav-item') === 3,
    (string) substr_count($html, 'ido-subnav-item'));
check('no PHP notice leaked into it',
    stripos($html, 'warning:') === false && stripos($html, 'fatal') === false
    && stripos($html, 'undefined') === false);

say('');
say('=== the navigation ===');
check('the tab bar names the War Dept', strpos($html, '>War Dept<') !== false);
check('and no longer offers Army', strpos($html, '>Army<') === false);
check('or Spies', strpos($html, '>Spies<') === false);

say('');
say('=== nothing that pointed at the old pages goes dark ===');
// The whole reason the old tags still exist: a page created before the merge
// still has one of them sitting in its content.
foreach (['ido_military', 'ido_covert'] as $tag) {
    check("[$tag] still answers", shortcode_exists($tag));
    $out = do_shortcode('[' . $tag . ']');
    check("[$tag] renders the War Dept in full",
        strpos($out, 'id="ido-spies"') !== false && strpos($out, 'id="ido-army"') !== false,
        strlen($out) . ' bytes');
}
check('and the war tag itself still answers', shortcode_exists('ido_war'));

say('');
say('=== Lands took the market ===');
check('the market page is gone from the map', !isset(IDO_UI::PAGES['market']));
check('Lands kept its slug', IDO_UI::PAGES['lands'][1] === 'imperial-dominion-online-lands');

$lands = IDO_Shortcodes::render('lands');
check('the holdings are there', strpos($lands, 'id="ido-holdings"') !== false);
check('and so is the market', strpos($lands, 'id="ido-market"') !== false);
check('with jump links', substr_count($lands, 'ido-subnav-item') === 2,
    (string) substr_count($lands, 'ido-subnav-item'));
check('no Market tab remains', strpos($lands, '>Market<') === false);
check('[ido_market] still answers', shortcode_exists('ido_market'));
check('and renders Lands in full',
    strpos(do_shortcode('[ido_market]'), 'id="ido-holdings"') !== false);
check('the market filters point back at Lands, not a page that is gone',
    strpos(file_get_contents(IDO_PATH . 'includes/frontend/views/partials/market.php'),
        "IDO_UI::url('market')") === false);

say('');
say('=== an existing site is carried across ===');
$src = file_get_contents(IDO_PATH . 'includes/class-ido-installer.php');
check('the rename knows the title it is replacing',
    strpos($src, "'Imperial Dominion - War Room'") !== false);
check('and allows a screen more than one old name',
    strpos($src, "in_array(\$page->post_title, (array) \$was, true)") !== false);
check('the rename is re-run rather than skipped as already done',
    IDO_Installer::PAGE_TITLES_VERSION !== '2', IDO_Installer::PAGE_TITLES_VERSION);

$ids = get_option('ido_page_ids');
if (is_array($ids) && !empty($ids['war'])) {
    $page = get_post((int) $ids['war']);
    check('this site\'s own war page has been renamed',
        $page && $page->post_title === 'Imperial Dominion - War Dept',
        $page ? $page->post_title : 'missing');
}

say('');
say('=== the old pages are left alone, not deleted ===');
// Deleting a page an administrator may have linked to is not the plugin's call.
$left = 0;
foreach (['military', 'covert'] as $key) {
    if (empty($ids[$key])) continue;
    $page = get_post((int) $ids[$key]);
    if ($page && $page->post_type === 'page' && $page->post_status !== 'trash') $left++;
}
say('  ' . $left . ' leftover page(s) on this site');
check('the dashboard offers to explain them',
    strpos(file_get_contents(IDO_PATH . 'admin/views/dashboard.php'),
        'folded into') !== false);
check('and looks for all three of them',
    strpos(file_get_contents(IDO_PATH . 'admin/views/dashboard.php'),
        "'market' => 'Market'") !== false);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
