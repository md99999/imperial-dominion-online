# Tests

Two scripts that run the plugin outside WordPress, with the WordPress functions
stubbed out. They need nothing but a PHP 8 binary, and they catch the class of
mistake that a syntax check cannot: fatal type errors, broken activation, views
that die when rendered, and arithmetic that overflows.

Most of them do not touch a database: everything below the `$wpdb` calls is
faked, so they prove the code runs, not that the SQL is correct.
`integration-explore.php` is the exception, and is the one to reach for when an
action looks like it is not doing anything.

## Running them

    php tests/overflow-test.php     # scoring cannot overflow or go negative
    php tests/smoke-test.php        # plugin loads, activates, and every page renders
    php tests/uninstall-test.php    # deleting the plugin keeps data unless told otherwise

The one test that uses a real database, against a running site:

    php tests/integration-explore.php /path/to/wordpress [db-host]

`overflow-test.php` exits non-zero when a check fails, so it is safe to wire
into CI. `smoke-test.php` prints the byte size of each rendered page; a fatal
error shows up as a PHP error rather than a size.

If PHP is not on your PATH, the binary bundled with Local works:

    "$APPDATA/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" tests/overflow-test.php

## What is in here

| File | Purpose |
| --- | --- |
| `overflow-test.php` | Drives net worth with maximum values and asserts it saturates at the ceiling instead of wrapping negative |
| `smoke-test.php` | Loads the plugin, runs the activation path, renders all nine pages signed in and logged out, and checks the SQL guards |
| `uninstall-test.php` | Runs `uninstall.php` with the setting off and on, proving the default keeps every table and the opt-in really drops them |
| `integration-explore.php` | Boots a real WordPress, explores with a throwaway kingdom, and checks the database actually changed. Deletes the kingdom afterwards |
| `wp-admin/includes/upgrade.php` | A stub `dbDelta()`, since the installer requires that file the way WordPress provides it |

`smoke-test.php` also writes `preview.html` next to itself: a standalone copy of
the game screens wrapped in a mock theme column, for looking at the CSS without
a WordPress install. It is git-ignored.
