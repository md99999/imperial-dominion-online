<?php
/**
 * Optional: run from a system cron instead of relying on WP-Cron.
 *   5 0 * * * php /path/to/wp-content/plugins/imperial-dominion-online/maintenance/daily_maintenance.php
 */
if (PHP_SAPI !== "cli") exit("CLI only.\n");
require_once __DIR__ . "/bootstrap.php";
echo IDO_Maintenance::daily(false, "system cron") . "\n";
