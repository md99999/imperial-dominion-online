<?php
/**
 * Loads the plugin with WordPress stubbed out, to catch fatals that happen at
 * include time or on the activation path.
 */
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['ido_actions'] = [];

function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://example.test/wp-content/plugins/' . basename(dirname($f)) . '/'; }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}
function add_action($hook, $cb, $p = 10, $a = 1) { $GLOBALS['ido_actions'][] = [$hook, $cb]; }
function add_shortcode($tag, $cb) {}
function is_admin() { return false; }
function get_option($k, $d = false) { return $k === 'blogname' ? 'Mad Dog Productions' : $d; }
function wp_specialchars_decode($s, $q = null) { return html_entity_decode((string) $s, ENT_QUOTES); }
function update_option($k, $v, $a = null) { return true; }
function delete_option($k) { return true; }
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function current_time($type) { return $type === 'timestamp' ? time() : date($type === 'mysql' ? 'Y-m-d H:i:s' : $type); }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wp_next_scheduled($h) { return false; }
function wp_schedule_event($t, $r, $h) { return true; }
function wp_clear_scheduled_hook($h) { return true; }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function wp_rand($min = 0, $max = 1) { return random_int($min, $max); }
function get_current_user_id() { return 1; }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function esc_url($s) { return (string) $s; }
function get_page_by_path($p) { return null; }
function home_url($p = '') { return 'http://example.test' . $p; }
function add_query_arg($a, $u) { return $u; }
function get_post_status($id) { return false; }
function wp_enqueue_style($h) {}
function wp_enqueue_script($h) {}
function wp_register_style($h, $s, $d, $v) {}
function wp_register_script($h, $s, $d, $v, $f) {}
function is_singular() { return true; }
function has_shortcode($c, $s) { return false; }
function get_transient($k) { return false; }
function set_transient($k, $v, $t) { return true; }
function delete_transient($k) { return true; }
function wp_get_current_user() { return (object) ['display_name' => 'Tester']; }
function wp_nonce_field($a, $n, $r, $e) { return ''; }
function _n($s, $p, $n, $d = '') { return $n === 1 ? $s : $p; }
function date_i18n($f, $t = null) { return date($f, $t ?: time()); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }

/** Just enough wpdb to see which statements the activation path runs. */
class FakeWPDB {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public array $queries = [];
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) $a = $a[0];
        return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%f'], $q), $a);
    }
    public function query($q) { $this->queries[] = $q; return 1; }
    public function get_var($q) { $this->queries[] = $q; return 0; }
    public function get_row($q) {
        $this->queries[] = $q;
        // Enough of a round row for the activation path to complete.
        if (strpos($q, 'ido_rounds') !== false) {
            return (object) [
                'id' => 1, 'round_name' => 'Round 1', 'status' => 'active',
                'starts_at' => date('Y-m-d H:i:s'),
                'ends_at' => date('Y-m-d H:i:s', time() + 45 * 86400),
                'completed_at' => null, 'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        if (strpos($q, 'ido_kingdoms') !== false && strpos($q, 'COUNT') === false) {
            return $GLOBALS['ido_fake_kingdom'];
        }
        return null;
    }
    public function get_results($q) { $this->queries[] = $q; return []; }
    public function insert($t, $d, $f = null) { $this->queries[] = 'INSERT ' . $t; return 1; }
    public function update($t, $d, $w, $df = null, $wf = null) { $this->queries[] = 'UPDATE ' . $t; return 1; }
    public function delete($t, $w, $f = null) { $this->queries[] = 'DELETE ' . $t; return 1; }
}
$GLOBALS['wpdb'] = new FakeWPDB();

echo "--- loading plugin ---\n";
require __DIR__ . '/../imperial-dominion-online.php';
echo "loaded, hooks registered: " . count($GLOBALS['ido_actions']) . "\n";

echo "--- activation path ---\n";
// dbDelta lives in wp-admin/includes/upgrade.php, which is not present here.

IDO_Installer::activate();
echo "activate() completed\n";

echo "--- settings and data ---\n";
echo 'turns_per_day: ' . IDO_Settings::int('turns_per_day') . "\n";
echo 'buildings: ' . implode(', ', IDO_Buildings::keys()) . "\n";
echo 'units: ' . implode(', ', IDO_Units::keys()) . "\n";
echo 'engines: ' . implode(', ', IDO_Engines::keys()) . "\n";
echo "OK\n";

echo "--- rendering the pages ---\n";

function is_user_logged_in() { return empty($GLOBALS['ido_logged_out']); }
function wp_login_url($r = '') { return 'http://example.test/login'; }
function wp_registration_url() { return 'http://example.test/register'; }
function get_permalink($p = null) { return 'http://example.test/page'; }
function selected($a, $b, $e = true) { return $a == $b ? ' selected' : ''; }
function disabled($a, $b = true, $e = true) { return $a == $b ? ' disabled' : ''; }
function get_userdata($id) { return (object) ['user_login' => 'tester']; }
function get_post($id) { return null; }

$kingdom_row = (object) [
    'id' => 1, 'round_id' => 1, 'user_id' => 1,
    'kingdom_name' => 'Vaelmark', 'ruler_name' => 'Tester',
    'turns' => 30, 'turns_spent' => 0, 'last_turn_grant' => date('Y-m-d'),
    'land' => 250, 'land_in_progress' => 0,
    'gold' => 75000, 'grain' => 40000, 'iron' => 5000, 'peasants' => 1500,
    'b_homestead' => 60, 'b_farmstead' => 60, 'b_mint' => 30, 'b_foundry' => 25,
    'b_barracks' => 13, 'b_fortification' => 13,
    'u_pawn' => 200, 'u_legionnaire' => 50, 'u_centurion' => 0, 'u_ballista_legion' => 0,
    'catapults' => 12, 'catapults_in_progress' => 4,
    'agents' => 0, 'networth' => 250000,
    'protection_until' => date('Y-m-d H:i:s', time() + 3600),
    'is_defeated' => 0, 'attacks_made' => 0, 'attacks_won' => 0, 'attacks_suffered' => 0,
    'land_taken' => 0, 'land_lost' => 0,
    'created_at' => date('Y-m-d H:i:s'), 'last_seen' => date('Y-m-d H:i:s'),
];

$GLOBALS['ido_fake_kingdom'] = $kingdom_row;
foreach (array_keys(IDO_UI::PAGES) as $page) {
    $html = IDO_Shortcodes::render($page);
    printf("%-9s %6d bytes%s\n", $page, strlen($html), strpos($html, 'Fatal') !== false ? '  <-- FATAL' : '');
}

echo "--- rendering with no empire (the claim screen) ---\n";
$GLOBALS['ido_fake_kingdom'] = null;
printf("found     %6d bytes\n", strlen(IDO_Shortcodes::render('empire')));
echo "ALL VIEWS OK\n";

// Write a standalone preview page, wrapped in a theme-like content column.
$GLOBALS['ido_fake_kingdom'] = $kingdom_row;
$css = file_get_contents(__DIR__ . '/../assets/css/imperial-dominion-online.css');
$body = '';
foreach (['empire', 'lands', 'war'] as $page) {
    $body .= IDO_Shortcodes::render($page) . '<hr style="margin:40px 0;border:0;border-top:1px dashed #999">';
}
$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>IDO preview</title><style>' . $css
    . 'body{margin:0;background:#f3f4f6;font-family:Georgia,serif}'
    . '.theme-shell{max-width:760px;margin:0 auto;padding:24px 0}'
    . '.theme-note{max-width:760px;margin:0 auto;color:#555;font-size:14px}'
    . '</style></head><body>'
    . '<div class="theme-note">The grey band is a typical 760px theme content column. The game box should overhang it evenly on both sides.</div>'
    . '<div class="theme-shell" style="background:#e5e7eb">' . $body . '</div></body></html>';
file_put_contents(__DIR__ . '/preview.html', $html);
echo "preview.html written (" . strlen($html) . " bytes)\n";

echo "--- guide, signed in with an empire ---\n";
$GLOBALS['ido_fake_kingdom'] = $kingdom_row;
printf("guide     %6d bytes\n", strlen(IDO_Shortcodes::render('guide')));

echo "--- logged out: front door and guide ---\n";
$GLOBALS['ido_logged_out'] = true;
$welcome = IDO_Shortcodes::render('empire');
printf("welcome   %6d bytes, leaderboard: %s\n", strlen($welcome),
    strpos($welcome, 'Who leads') !== false ? 'present' : 'MISSING');
printf("guide     %6d bytes, rules visible: %s\n", strlen(IDO_Shortcodes::render('guide')),
    strpos(IDO_Shortcodes::render('guide'), 'rules of engagement') !== false ? 'yes' : 'NO');
$GLOBALS['ido_logged_out'] = false;
echo "LOGGED-OUT VIEWS OK\n";

echo "--- pay() generates capped, guarded SQL ---\n";
$GLOBALS['wpdb']->queries = [];
IDO_Kingdom::pay($kingdom_row, ['gold' => -5000, 'land' => 25]);
$sql = end($GLOBALS['wpdb']->queries);
echo $sql . "\n";
printf("ceiling on the increase : %s\n", strpos($sql, 'LEAST(9000000000000000') !== false ? 'yes' : 'NO');
printf("floor on the decrease   : %s\n", strpos($sql, '`gold` >= 5000') !== false ? 'yes' : 'NO');

echo "--- the three states of the home page ---\n";
// A visitor, a signed-in reader with no empire, and a ruler: each must be
// offered the next step that actually applies to them.
$GLOBALS['ido_logged_out'] = true;
$GLOBALS['ido_fake_kingdom'] = null;
$visitor = IDO_Shortcodes::render('guide');

$GLOBALS['ido_logged_out'] = false;
$GLOBALS['ido_fake_kingdom'] = null;
$signed_in = IDO_Shortcodes::render('guide');

$GLOBALS['ido_fake_kingdom'] = $kingdom_row;
$ruler = IDO_Shortcodes::render('guide');

$state_fails = 0;
function state(string $label, bool $ok) {
    global $state_fails;
    if (!$ok) $state_fails++;
    printf("%-52s %s\n", $label, $ok ? 'PASS' : 'FAIL');
}
// Match the button, not the words: the guide's "first day" list opens with
// the sentence "Claim your empire." as ordinary prose.
$claim = '>Claim your empire</a>';
state('visitor is asked to sign in', strpos($visitor, 'Sign in to play') !== false);
state('signed-in reader is asked to claim, not sign in', strpos($signed_in, $claim) !== false
    && strpos($signed_in, 'Sign in to play') === false);
state('ruler is offered neither button', strpos($ruler, 'Sign in to play') === false
    && strpos($ruler, $claim) === false);
echo $state_fails === 0 ? "HOME PAGE STATES OK\n" : "$state_fails STATE CHECK(S) FAILED\n";
