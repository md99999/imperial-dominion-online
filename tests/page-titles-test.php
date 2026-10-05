<?php
/**
 * Renaming the game's pages on an upgrade.
 *
 * This migration rewrites rows in wp_posts on a live site, so what matters is
 * not that it renames, but what it refuses to touch: a title an administrator
 * chose themselves, and a page it has already handled once.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', __DIR__ . '/');
define('IDO_DB_VERSION', '1.0');

$GLOBALS['ido_options'] = ['ido_db_version' => IDO_DB_VERSION];
$GLOBALS['ido_posts']   = [];
$GLOBALS['ido_writes']  = [];

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['ido_options']) ? $GLOBALS['ido_options'][$key] : $default;
}
function update_option($key, $value) { $GLOBALS['ido_options'][$key] = $value; return true; }
function get_post($id) { return $GLOBALS['ido_posts'][(int) $id] ?? null; }
function wp_update_post(array $post) {
    $id = (int) $post['ID'];
    $GLOBALS['ido_writes'][] = $id;
    if (isset($GLOBALS['ido_posts'][$id])) $GLOBALS['ido_posts'][$id]->post_title = $post['post_title'];
    return $id;
}

require __DIR__ . '/../includes/frontend/class-ido-ui.php';
require __DIR__ . '/../includes/class-ido-installer.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-56s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}
function page(int $id, string $title, string $type = 'page'): object {
    $GLOBALS['ido_posts'][$id] = (object) ['ID' => $id, 'post_title' => $title, 'post_type' => $type];
    return $GLOBALS['ido_posts'][$id];
}

echo "=== an upgrade from the short prefix ===\n";
page(11, 'ID - Imperial Dominion');
page(12, 'ID - Lands');
page(13, 'ID - War Room');
page(14, 'Our Own Marketplace');           // renamed by hand
page(15, 'Imperial Dominion - Gazette');   // already correct
page(16, 'ID - Rankings', 'post');         // not a page at all
$GLOBALS['ido_options']['ido_page_ids'] = [
    'guide' => 11, 'lands' => 12, 'war' => 13, 'market' => 14,
    'gazette' => 15, 'rankings' => 16, 'covert' => 99,
];

IDO_Installer::maybe_rename_pages();

check('the front page loses the prefix entirely', $GLOBALS['ido_posts'][11]->post_title === 'Imperial Dominion',
    $GLOBALS['ido_posts'][11]->post_title);
check('a game page is spelled out', $GLOBALS['ido_posts'][12]->post_title === 'Imperial Dominion - Lands',
    $GLOBALS['ido_posts'][12]->post_title);
// The War Room became the War Dept when the muster and the spy court were
// folded into it, and the rename migration is what carries an existing site's
// page across rather than leaving it under a name the navigation no longer uses.
check('a renamed screen is carried to its new name', $GLOBALS['ido_posts'][13]->post_title === 'Imperial Dominion - War Dept',
    $GLOBALS['ido_posts'][13]->post_title);
check("a title chosen by hand is left alone", $GLOBALS['ido_posts'][14]->post_title === 'Our Own Marketplace');
check('a correct title is not rewritten', !in_array(15, $GLOBALS['ido_writes'], true));
check('something that is not a page is skipped', $GLOBALS['ido_posts'][16]->post_title === 'ID - Rankings');
check('a recorded id with no post behind it does not fatal', true);

echo "\n=== it only runs once ===\n";
check('the run is recorded', get_option('ido_page_titles') === IDO_Installer::PAGE_TITLES_VERSION);
$GLOBALS['ido_writes'] = [];
$GLOBALS['ido_posts'][12]->post_title = 'ID - Lands';
IDO_Installer::maybe_rename_pages();
check('a second upgrade rewrites nothing', $GLOBALS['ido_writes'] === []);
check('so a later hand-rename survives', $GLOBALS['ido_posts'][12]->post_title === 'ID - Lands');

echo "\n=== a fresh install with no pages yet ===\n";
$GLOBALS['ido_options'] = ['ido_db_version' => IDO_DB_VERSION];
$GLOBALS['ido_writes']  = [];
IDO_Installer::maybe_rename_pages();
check('nothing is written and the run is recorded', $GLOBALS['ido_writes'] === []
    && get_option('ido_page_titles') === IDO_Installer::PAGE_TITLES_VERSION);

echo "\n=== the menu label ===\n";
require __DIR__ . '/../includes/class-ido-menu.php';
check('the site menu shows the name without a prefix', IDO_Menu::menu_label() === 'Imperial Dominion',
    IDO_Menu::menu_label());

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
