# Tests

Scripts that run the plugin outside WordPress, with the WordPress functions
stubbed out. They need nothing but a PHP 8 binary, and they catch the class of
mistake that a syntax check cannot: fatal type errors, broken activation, views
that die when rendered, and arithmetic that overflows.

They come in two kinds. The `*-test.php` scripts do not touch a database:
everything below the `$wpdb` calls is faked, so they prove the code runs, not
that the SQL is correct. The `integration-*.php` scripts boot a real WordPress
against a real database, and are the ones to reach for when an action looks
like it is not doing anything. Each creates throwaway empires and deletes them
afterwards.

## Running them

    php tests/overflow-test.php     # scoring cannot overflow or go negative
    php tests/catapult-test.php     # siege weapons divide correctly after a battle
    php tests/migration-test.php    # the upgrade path drops and renames the right columns
    php tests/smoke-test.php        # plugin loads, activates, and every page renders
    php tests/uninstall-test.php    # deleting the plugin keeps data unless told otherwise

The rest of the `*-test.php` scripts run the same way. The integration tests
take the path to a WordPress install, and optionally a database host:

    php tests/integration-march.php /path/to/wordpress [db-host]

Every script except `smoke-test.php` exits non-zero when a check fails, so they
are safe to wire into CI. `smoke-test.php` prints the byte size of each rendered
page; a fatal error shows up as a PHP error rather than a size.

If PHP is not on your PATH, the binary bundled with Local works:

    "$APPDATA/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" tests/overflow-test.php

## What is in here

| File | Purpose |
| --- | --- |
| `overflow-test.php` | Drives net worth with maximum values and asserts it saturates at the ceiling instead of wrapping negative |
| `catapult-test.php` | Divides a stake of siege weapons after a battle, and proves no setting or rounding can take more weapons than were there |
| `migration-test.php` | Runs the upgrade against described sites and checks the statements it emits: that a dropped column is only dropped once its replacement exists, that a queue owned by the old column is carried over first, and that nothing assumes the `wp_` prefix |
| `smoke-test.php` | Loads the plugin, runs the activation path, renders every game page signed in and logged out, and checks the SQL guards |
| `uninstall-test.php` | Runs `uninstall.php` with the setting off and on, proving the default keeps every table and the opt-in really drops them |
| `page-titles-test.php` | Renames the game's pages on an upgrade, and proves it leaves alone a title an administrator chose and a page it has already handled |
| `barbarian-test.php` | Who barbarians visit: only the leaders, and only on a board big enough to have a field to lead |
| `league-security-test.php` | The packet signature and the URLs this site is willing to call, tested for what they refuse |
| `league-packet-test.php` | Tries to smuggle hostile values past the packet schema: injection, type confusion, overflow, undeclared fields, misaddressed packets |
| `league-share-test.php` | Splits whole troops and coins by share, so a march never leaks or invents a unit through rounding |
| `league-outcome-test.php` | Where a league battle becomes a win, a loss or a draw, and what each costs both sides |
| `league-covert-test.php` | The odds and the payoff for the agent who rides with the army |
| `integration-explore.php` | Explores with a throwaway empire and checks the database actually changed |
| `integration-caps.php` | Refuses a turn spent on a building past its cap |
| `integration-input.php` | Fires SQL, shell and traversal payloads through the service layer and checks the tables, row counts and balances are untouched |
| `integration-league.php` | Opting in, founding, inviting, joining and leaving write what they claim |
| `integration-handshake.php` | The enrolment handshake, both sides, with only the wire between them simulated |
| `integration-endpoint.php` | Attacks the enrolment routes on purpose: malformed bodies, injection, misaddressed enrolments, and what a failure gives away |
| `integration-queue.php` | A news packet end to end: signed, queued, sent with retry, received, staged, delayed and applied |
| `integration-march.php` | A muster and a march end to end, one site playing both sides, checking every soldier and coin ends up where it should |
| `integration-table.php` | The league table's scoring, attacked the ways a site would try to game it |
| `integration-leaguemode.php` | League mode refuses local war and covert missions in the service, not just on the screen, and the ruin and relief cycle in a league |
| `integration-reset.php` | The board reset leaves nothing stale behind, and relief for a single ruined empire |
| `wp-admin/includes/upgrade.php` | A stub `dbDelta()`, since the installer requires that file the way WordPress provides it |

`smoke-test.php` also writes `preview.html` next to itself: a standalone copy of
the game screens wrapped in a mock theme column, for looking at the CSS without
a WordPress install. It is git-ignored.
