<?php
/**
 * Optional: run from a system cron instead of relying on WP-Cron.
 *   0 * * * * php /path/to/wp-content/plugins/imperial-dominion-online/maintenance/hourly_maintenance.php
 */
if (PHP_SAPI !== "cli") exit("CLI only.\n");
require_once __DIR__ . "/bootstrap.php";
echo IDO_Maintenance::hourly(false, "system cron") . "\n";
