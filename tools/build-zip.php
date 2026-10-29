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
// docs/ is design documentation, never read at runtime, and about half the
// weight of the archive. It also publishes a maintained list of this design's
// unanswered gaps and the name of the constant that turns off the SSRF guard,
// which is fine in a repository somebody has gone looking for and needless on
// every installation at a guessable URL. README.md ships; the rest is on GitHub.
$skip = ['tests', 'tools', 'docs'];

// One dotted file does belong in a release: .htaccess hardens the installed
// directory on Apache, and the blanket dot rule below would otherwise drop the
// one piece of furniture that is not furniture.
$keep = ['.htaccess'];

$is_furniture = static function (string $relative) use ($skip, $keep): bool {
    if (in_array($relative, $keep, true)) return false;
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

// A link is never packaged, and never followed.
//
// A symlink or a Windows junction in the tree reads as neither a file nor a
// directory here: isDir() says false, so the old code handed it to addFile(),
// which resolves it when the archive is written. At best that silently packages
// whatever it points at -- a key, a config file, anything outside the plugin --
// under a plugin path. At worst it points somewhere unreadable, and then see
// below. Nothing in a release is a link, so the honest answer is to refuse one
// and say so rather than to resolve it.
$links = [];

// The plugin's own directory, canonicalised once, to compare every entry against.
$root_real = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');

$count = 0;
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if ($is_furniture($relative)) continue;

    // Resolved and then checked, rather than checked and then resolved.
    //
    // is_link() is not enough on its own: a Windows junction answers false to
    // it and to isLink(), while still redirecting anywhere on the disk. The
    // reliable question is not "is this a link" but "after the operating system
    // has finished resolving this name, is the file still inside the plugin" --
    // which is the same question, asked so that it cannot be answered wrongly.
    $real = realpath($file->getPathname());
    $inside = $real !== false
        && strpos(str_replace('\\', '/', $real), $root_real . '/') === 0;
    if (!$inside || is_link($file->getPathname()) || $file->isLink()) {
        $links[] = $relative . ($real === false ? ' (unreadable)' : ' -> ' . $real);
        continue;
    }

    if ($file->isDir()) {
        $zip->addEmptyDir($name . '/' . $relative);
    } else {
        // addFile() is lazy: it returns true having only recorded the intent,
        // and does not open the file until close(). An unreadable entry
        // therefore fails at close() and not here, which is why the count below
        // is read back out of the finished archive instead of being tallied in
        // this loop. A build that reported "91 files" while writing an empty
        // zip is exactly the failure this guards.
        if (!$zip->addFile($file->getPathname(), $name . '/' . $relative)) {
            fwrite(STDERR, "Could not add $relative\n");
            exit(1);
        }
        $count++;
    }
}

if ($links) {
    fwrite(STDERR, "Refusing to build: these are links, and a release contains none.\n  "
        . implode("\n  ", $links) . "\n");
    exit(1);
}

if (!$zip->close()) {
    fwrite(STDERR, "Could not write $dest: " . $zip->getStatusString() . "\n");
    exit(1);
}

// Read back rather than trusted. The count above is what this script meant to
// write; this is what a site will actually unpack.
$check = new ZipArchive();
if ($check->open($dest) !== true) {
    fwrite(STDERR, "Built $dest but cannot reopen it.\n");
    exit(1);
}
$written = $check->numFiles;
$check->close();

$dirs = $written - $count;
if ($written < $count) {
    fwrite(STDERR, "Built $dest but it holds $written entries, not the $count files added.\n");
    exit(1);
}

printf("%s\n%d files (+%d directories), %.1f KB, version %s\n",
    $dest, $count, $dirs, filesize($dest) / 1024, $version);
