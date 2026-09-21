# Tests

Two scripts that run the plugin outside WordPress, with the WordPress functions
stubbed out. They need nothing but a PHP 8 binary, and they catch the class of
mistake that a syntax check cannot: fatal type errors, broken activation, views
that die when rendered, and arithmetic that overflows.

They do not touch a database. Everything below the `$wpdb` calls is faked, so
these prove the code runs, not that the SQL is correct.

## Running them

    php tests/overflow-test.php     # scoring cannot overflow or go negative
    php tests/smoke-test.php        # plugin loads, activates, and every page renders

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
| `wp-admin/includes/upgrade.php` | A stub `dbDelta()`, since the installer requires that file the way WordPress provides it |

`smoke-test.php` also writes `preview.html` next to itself: a standalone copy of
the game screens wrapped in a mock theme column, for looking at the CSS without
a WordPress install. It is git-ignored.
