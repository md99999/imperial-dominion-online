<?php
/**
 * Checks what deleting the plugin actually does.
 *
 * This is the one irreversible path in the plugin, so both answers are worth
 * proving: that the default keeps every table, and that the opt-in really does
 * remove them.
 */
define('WP_UNINSTALL_PLUGIN', true);

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-56s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

class UninstallWPDB {
    public $prefix = 'wp_';
    public array $queries = [];
    public function query($sql) { $this->queries[] = $sql; return 1; }
    public function dropped(): array {
        return array_values(array_filter($this->queries, static function ($q) {
            return stripos($q, 'DROP TABLE') !== false;
        }));
    }
}

$GLOBALS['ido_deleted_options'] = [];
$GLOBALS['ido_cleared_hooks'] = [];

function get_option($key, $default = false) {
    return $key === 'ido_settings' ? $GLOBALS['ido_test_settings'] : $default;
}
function delete_option($key) { $GLOBALS['ido_deleted_options'][] = $key; return true; }
function wp_clear_scheduled_hook($hook) { $GLOBALS['ido_cleared_hooks'][] = $hook; return true; }

/** Runs uninstall.php in its own scope with the given settings. */
function run_uninstall(array $settings): UninstallWPDB {
    $GLOBALS['ido_test_settings'] = $settings;
    $GLOBALS['ido_deleted_options'] = [];
    $GLOBALS['ido_cleared_hooks'] = [];
    $GLOBALS['wpdb'] = new UninstallWPDB();
    include __DIR__ . '/../uninstall.php';
    return $GLOBALS['wpdb'];
}

echo "=== the default: keep the data ===\n";
$db = run_uninstall(['delete_data_on_uninstall' => 0]);
check('no tables are dropped', $db->dropped() === [], implode(', ', $db->dropped()));
check('settings are left in place', !in_array('ido_settings', $GLOBALS['ido_deleted_options'], true));
check('scheduled events are still cleared', count($GLOBALS['ido_cleared_hooks']) === 2);

echo "\n=== a game master who has opted in ===\n";
$db = run_uninstall(['delete_data_on_uninstall' => 1]);
$dropped = $db->dropped();
check('every game table is dropped', count($dropped) === 9, count($dropped) . ' dropped');
check('empires are among them', (bool) array_filter($dropped, static function ($q) {
    return strpos($q, 'wp_ido_kingdoms') !== false;
}));
check('options are removed too', in_array('ido_settings', $GLOBALS['ido_deleted_options'], true));
check('scheduled events are cleared', count($GLOBALS['ido_cleared_hooks']) === 2);

echo "\n=== a settings row that is missing or malformed ===\n";
$db = run_uninstall([]);
check('no setting means keep the data', $db->dropped() === []);

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
