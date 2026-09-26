<?php
/**
 * The upgrade path, checked away from the database.
 *
 * dbDelta only ever adds, so every column that was ever renamed has to be
 * carried across by hand before it runs. That makes the migration the one part
 * of the plugin where a mistake is not recoverable: it drops columns, and a
 * site that runs it with a build queue in the wrong place loses the queue.
 *
 * Three things are worth pinning down. That the drop of realm_id is gated on
 * kingdom_id being there to drop it in favour of, so a site that never saw the
 * release adding kingdom_id is migrated rather than emptied. That an order
 * owned only by the old column is carried over before the column holding it
 * goes. And that none of it assumes the wp_ prefix, since a site may have been
 * installed on any prefix at all, and hardening one is common advice.
 */
define('ABSPATH', __DIR__ . '/');
define('IDO_PATH', dirname(__DIR__) . '/');
define('IDO_DB_VERSION', 'test');

/** A settings store the migration can actually write back to. */
$GLOBALS['ido_options'] = [];
function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['ido_options']) ? $GLOBALS['ido_options'][$name] : $default;
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['ido_options'][$name] = $value;
    return true;
}
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }

require IDO_PATH . 'includes/class-ido-core.php';
require IDO_PATH . 'includes/class-ido-installer.php';
require IDO_PATH . 'includes/data/class-ido-weapons.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-58s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

/**
 * Just enough wpdb to see which statements the upgrade runs. The table shape is
 * dictated rather than discovered, so each case below can describe a site.
 */
class MigrationWPDB {
    public $prefix;
    public array $queries = [];
    public array $columns;
    public array $keys;

    public function __construct(array $columns, array $keys, string $prefix = 'wp_') {
        $this->columns = $columns;
        $this->keys = $keys;
        $this->prefix = $prefix;
    }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) $a = $a[0];
        return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%f'], $q), $a);
    }
    /** Every table exists, so nothing is skipped for the wrong reason. */
    public function get_var($q) { $this->queries[] = $q; return 1; }
    public function query($q) { $this->queries[] = $q; return 1; }
    public function get_col($q, $offset = 0) {
        $this->queries[] = $q;
        if (strpos($q, 'SHOW INDEX') !== false) return $this->keys;
        if (strpos($q, 'ido_constructions') !== false) return $this->columns;
        return ['id', 'round_id', 'b_mint', 'u_legionnaire'];  // kingdoms, already modern
    }
}

/** Runs the whole upgrade against a described site, and returns its statements. */
function migrate(array $columns, array $keys = [], string $prefix = 'wp_'): array {
    $GLOBALS['wpdb'] = new MigrationWPDB($columns, $keys, $prefix);
    IDO_Installer::install_schema();
    return $GLOBALS['wpdb']->queries;
}

/** The statements that change the build queue, in the order they ran. */
function queue_writes(array $queries): array {
    return array_values(array_filter($queries, static function ($q) {
        return strpos($q, 'ido_constructions') !== false
            && preg_match('/^(ALTER|UPDATE|DELETE)/i', $q);
    }));
}

/** Where a statement matching $pattern first ran, or -1. */
function position(array $writes, string $pattern): int {
    foreach ($writes as $i => $q) {
        if (preg_match($pattern, $q)) return $i;
    }
    return -1;
}

$modern = ['id', 'round_id', 'kingdom_id', 'kind', 'building', 'qty', 'ready_on', 'created_at'];
$stale  = ['id', 'round_id', 'realm_id', 'building', 'qty', 'ready_on', 'created_at', 'kingdom_id', 'kind'];
$legacy = ['id', 'round_id', 'realm_id', 'building', 'qty', 'ready_on', 'created_at'];

echo "=== a site carrying both columns ===\n";
$writes = queue_writes(migrate($stale, ['PRIMARY', 'realm_ready', 'kingdom_ready']));
check('the dead column is dropped', position($writes, '/DROP COLUMN `realm_id`/') > -1);
check('only orders the new column never learned of are touched',
    position($writes, '/WHERE kingdom_id = 0 AND realm_id <> 0/') > -1);
check('the owner is carried over before the drop', (function () use ($writes) {
    $copy = position($writes, '/SET kingdom_id = realm_id/');
    $drop = position($writes, '/DROP COLUMN `realm_id`/');
    return $copy > -1 && $drop > -1 && $copy < $drop;
})(), implode(' then ', $writes));
check('the stale key goes with it', position($writes, '/DROP INDEX `realm_ready`/') > -1);
check('the key that replaced it is kept', position($writes, '/DROP INDEX `kingdom_ready`/') === -1);

echo "\n=== a site that skipped the release adding kingdom_id ===\n";
$writes = queue_writes(migrate($legacy, ['PRIMARY', 'realm_ready']));
check('the column is renamed, not dropped',
    position($writes, '/CHANGE `realm_id` `kingdom_id`/') > -1);
check('nothing is dropped, so the queue survives', position($writes, '/DROP COLUMN/') === -1);
check('no copy is attempted into a column that is not there',
    position($writes, '/SET kingdom_id = realm_id/') === -1);
check('the key is left alone until its replacement exists',
    position($writes, '/DROP INDEX/') === -1);

echo "\n=== a site that is already clean ===\n";
$writes = queue_writes(migrate($modern, ['PRIMARY', 'kingdom_ready', 'round_id']));
check('the migration is a no-op', $writes === [], implode('; ', $writes));
// Running the upgrade twice is ordinary: a second page load, a second site
// sharing the database. The second run must find nothing left to do.
$writes = queue_writes(migrate($modern, ['PRIMARY', 'kingdom_ready']));
check('running it again still does nothing', $writes === [], implode('; ', $writes));

echo "\n=== the prefix is never assumed ===\n";
foreach (['wp_', 'xK7q_secure_', 'a_', 'my_wp_site_'] as $prefix) {
    $queries = migrate($stale, ['PRIMARY', 'realm_ready', 'kingdom_ready'], $prefix);
    $touching = array_filter($queries, static function ($q) {
        return strpos($q, 'ido_constructions') !== false;
    });
    $wrong = array_filter($touching, static function ($q) use ($prefix) {
        return strpos($q, $prefix . 'ido_constructions') === false;
    });
    check(sprintf('every statement on a %s site carries the prefix', $prefix),
        $touching !== [] && $wrong === [],
        $wrong ? reset($wrong) : count($touching) . ' statements');
    check(sprintf('a %s site is never told wp_', $prefix),
        $prefix === 'wp_' || !preg_grep('/\bwp_ido_/', $queries));
}

echo "\n=== the schema handed to dbDelta ===\n";
migrate($modern, ['PRIMARY', 'kingdom_ready'], 'xK7q_secure_');
$schema = isset($GLOBALS['ido_dbdelta']) ? $GLOBALS['ido_dbdelta'] : '';
check('the prefix token is substituted', $schema !== '' && strpos($schema, '{prefix}') === false);
check('the charset token is substituted', strpos($schema, '{charset_collate}') === false);
check('it declares the table under the site prefix',
    strpos($schema, 'xK7q_secure_ido_constructions') !== false);
check('it declares kingdom_id and never realm_id',
    strpos($schema, 'kingdom_id') !== false && strpos($schema, 'realm_id') === false);

echo "\n=== settings retired along the way ===\n";
// The footer credit stopped being a setting in 1.15.2. A saved value that
// nothing reads any more is worse than none: it reads like a dial that has
// quietly stopped working.
$GLOBALS['ido_options'] = [
    'ido_db_version' => 'older',
    IDO_Settings::OPTION => [
        'turns_per_day'    => 7,
        'dominion_name'    => 'Anywhere',
        'footer_link_text' => 'someone else',
        'footer_link_url'  => 'https://example.com',
    ],
];
$GLOBALS['wpdb'] = new MigrationWPDB($modern, ['PRIMARY', 'kingdom_ready']);
IDO_Installer::maybe_upgrade();
$after = $GLOBALS['ido_options'][IDO_Settings::OPTION];
check('the retired credit text is cleared away', !array_key_exists('footer_link_text', $after));
check('the retired credit link is cleared away', !array_key_exists('footer_link_url', $after));
check('a tuned setting is left alone', ($after['turns_per_day'] ?? null) === 7);
check('so is one that is free text', ($after['dominion_name'] ?? null) === 'Anywhere');
check('the version is recorded, so it runs once',
    $GLOBALS['ido_options']['ido_db_version'] === IDO_DB_VERSION);

// 1.16.1 raised the price of a catapult, in gold and in iron. It is a setting,
// so this reaches new installs only: a site already running keeps whatever its
// options row holds, and a game master who wants the new price sets it.
$GLOBALS['ido_options'] = [];
check('the new defaults are the new prices',
    IDO_Settings::defaults()['catapult_gold_cost'] === 5000
    && IDO_Settings::defaults()['catapult_iron_cost'] === 500,
    sprintf('gold %s, iron %s', IDO_Settings::defaults()['catapult_gold_cost'],
        IDO_Settings::defaults()['catapult_iron_cost']));
// The gold price drives what a captured weapon is worth, so it must not lag.
check('a fresh site builds at the new prices',
    IDO_Weapons::cost('catapult') === ['gold' => 5000, 'iron' => 500],
    var_export(IDO_Weapons::cost('catapult'), true));
check('a pair of catapults costs double',
    IDO_Weapons::cost('catapult', 2) === ['gold' => 10000, 'iron' => 1000]);
// A site that already chose a price is not touched by any of this.
$GLOBALS['ido_options'] = [IDO_Settings::OPTION => ['catapult_gold_cost' => 300, 'catapult_iron_cost' => 15]];
check('a running site keeps the price in its options row',
    IDO_Weapons::cost('catapult') === ['gold' => 300, 'iron' => 15],
    var_export(IDO_Weapons::cost('catapult'), true));

$GLOBALS['ido_options'] = [];

check('the credit text is fixed', IDO_Game::CREDIT_TEXT !== '');
check('the credit points at the source',
    IDO_Game::CREDIT_URL === 'https://github.com/md99999/imperial-dominion-online', IDO_Game::CREDIT_URL);
check('it is not a setting any more',
    !array_key_exists('footer_link_text', IDO_Settings::defaults())
    && !array_key_exists('footer_link_url', IDO_Settings::defaults()));
check('and not a free-text key either',
    !in_array('footer_link_text', IDO_Settings::text_keys(), true)
    && !in_array('footer_link_url', IDO_Settings::text_keys(), true));

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
