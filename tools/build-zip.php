<?php
/**
 * Builds the installable plugin zip.
 *
 * The version is read from the plugin header rather than passed in, so the
 * archive can never claim a version the code does not carry. Entry paths use
 * forward slashes: Compress-Archive on Windows writes backslashes, which
 * WordPress cannot always unpack.
 *
 *   php tools/build-zip.php [output-directory]
 *
 * Needs the zip extension. The PHP bundled with Local ships it but does not
 * enable it by default:
 *
 *   php -d extension=php_zip.dll tools/build-zip.php
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$root = dirname(__DIR__);
$name = basename($root);
$plugin_file = $root . '/' . $name . '.php';

if (!is_readable($plugin_file)) {
    fwrite(STDERR, "Cannot find the plugin file at $plugin_file\n");
    exit(1);
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The zip extension is not loaded. Try: php -d extension=php_zip.dll " . __FILE__ . "\n");
    exit(1);
}

// The header is the single source of truth for the version.
if (!preg_match('/^\s*Version:\s*(.+)$/mi', (string) file_get_contents($plugin_file), $m)) {
    fwrite(STDERR, "No Version: header found in $plugin_file\n");
    exit(1);
}
$version = trim($m[1]);

$out_dir = $argv[1] ?? dirname($root);
$dest = rtrim(str_replace('\\', '/', $out_dir), '/') . '/' . $name . '-' . $version . '.zip';

// Repository furniture that has no business in an installed plugin. Anything
// at the root whose name begins with a dot goes too, named or not: that is
// where tooling puts its working directories, and one of them, .claude, can
// hold an entire second checkout of this plugin in a git worktree. Listing
// only the dotted names known at the time silently shipped that copy.
$skip = ['tests', 'tools'];
$is_furniture = static function (string $relative) use ($skip): bool {
    $top = explode('/', $relative)[0];
    return $top !== '' && ($top[0] === '.' || in_array($top, $skip, true));
};

if (file_exists($dest)) unlink($dest);

$zip = new ZipArchive();
if ($zip->open($dest, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create $dest\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$count = 0;
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if ($is_furniture($relative)) continue;

    if ($file->isDir()) {
        $zip->addEmptyDir($name . '/' . $relative);
    } else {
        $zip->addFile($file->getPathname(), $name . '/' . $relative);
        $count++;
    }
}

$zip->close();
printf("%s\n%d files, %.1f KB, version %s\n", $dest, $count, filesize($dest) / 1024, $version);
