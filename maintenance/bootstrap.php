<?php
/**
 * Loads WordPress for the CLI maintenance scripts by walking up to wp-load.php.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$dir = __DIR__;
while ($dir !== dirname($dir) && !file_exists($dir . '/wp-load.php')) {
    $dir = dirname($dir);
}
if (!file_exists($dir . '/wp-load.php')) {
    fwrite(STDERR, "Could not locate wp-load.php.\n");
    exit(1);
}
require_once $dir . '/wp-load.php';
if (!class_exists('IDO_Maintenance')) {
    fwrite(STDERR, "The Imperial Dominion Online plugin is not active.\n");
    exit(1);
}
