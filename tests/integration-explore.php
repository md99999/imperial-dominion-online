<?php
/**
 * Integration test: Send Settlers, against a real WordPress and a real database.
 *
 * The other tests stub $wpdb, so they prove the code runs but not that the SQL
 * does what it claims. This one boots WordPress, creates a throwaway kingdom,
 * explores with it, checks what actually moved in the database, and deletes it
 * again. No row belonging to a real player is touched.
 *
 *   php tests/integration-explore.php /path/to/wordpress [db-host]
 *
 * The db-host argument is for setups whose wp-config says "localhost" but whose
 * database listens on a port the CLI cannot reach that way. Local (Flywheel) is
 * the usual example:
 *
 *   php tests/integration-explore.php "C:/.../app/public" 127.0.0.1:10005
 *
 * Output goes to STDERR, because a plugin on the site under test may be holding
 * on to stdout.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) {
    say('Usage: php tests/integration-explore.php /path/to/wordpress [db-host]');
    exit(2);
}
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) {
    say('Cannot read ' . $wp_load);
    exit(2);
}

// Defining this first wins, because wp-config.php uses define().
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-44s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}

if (!class_exists('IDO_Economy')) { say('The plugin is not active on that site.'); exit(1); }

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

$name = 'Test Kingdom ' . wp_rand(1000, 9999);
$created = $wpdb->insert(IDO_DB::t('kingdoms'), [
    'round_id'        => (int) $round->id,
    'user_id'         => 999999,               // deliberately not a real user
    'kingdom_name'    => $name,
    'ruler_name'      => $name . ' Ruler',
    'turns'           => 10,
    'last_turn_grant' => IDO_Game::today(),
    'land'            => 250,
    'gold'            => 500000,
    'grain'           => 40000,
    'iron'            => 5000,
    'peasants'        => 1500,
    'b_homestead'     => 60,
    'b_farmstead'     => 60,
    'created_at'      => IDO_Game::now(),
]);
$id = (int) $wpdb->insert_id;
if (!$created || !$id) { say('Could not create the test kingdom: ' . $wpdb->last_error); exit(1); }

try {
    $before  = IDO_Kingdom::find($id);
    $preview = IDO_Economy::explore_preview($before);

    say(sprintf('Before:  land %s, gold %s, turns %d',
        number_format((float) $before->land), number_format((float) $before->gold), (int) $before->turns));
    say(sprintf('Preview: %d acres for %s gold', $preview['acres'], number_format((float) $preview['gold'])));

    $messages = IDO_Economy::explore($before);
    $after = IDO_Kingdom::find($id);

    say(sprintf('After:   land %s, gold %s, turns %d',
        number_format((float) $after->land), number_format((float) $after->gold), (int) $after->turns));
    say();
    say('What the player is told:');
    foreach ($messages as $m) {
        say('  - ' . (is_array($m) ? $m[1] : $m));
    }
    say();

    $land_gain  = (int) $after->land - (int) $before->land;
    $turn_spend = (int) $before->turns - (int) $after->turns;

    check('land actually increased', $land_gain > 0, $land_gain . ' acres');
    check('gain matches the preview', $land_gain === $preview['acres'],
        'promised ' . $preview['acres'] . ', got ' . $land_gain);
    check('exactly one turn was spent', $turn_spend === 1, $turn_spend . ' turns');
    check('net worth was recalculated', (int) $after->networth > 0, number_format((float) $after->networth));
    check('wilderness grew by the same acres',
        IDO_Buildings::wilderness($after) - IDO_Buildings::wilderness($before) === $land_gain);
    check('the report quotes the new total, not the old',
        strpos(implode(' ', array_map(static function ($m) { return is_array($m) ? $m[1] : $m; }, $messages)),
            IDO_Game::fmt($after->land)) !== false);

    // Not a failure, but the reason exploring can look like it did nothing.
    $next = IDO_Economy::explore_preview($after);
    say();
    say(sprintf('Next preview reads %d acres (was %d)%s', $next['acres'], $preview['acres'],
        $next['acres'] === $preview['acres'] ? '  <-- identical: the yield curve moves slowly' : ''));

    say();
    say($fails === 0 ? 'EXPLORE WORKS' : $fails . ' CHECK(S) FAILED');
} catch (Throwable $e) {
    say('FAILED: ' . get_class($e) . ': ' . $e->getMessage());
    say('  at ' . $e->getFile() . ':' . $e->getLine());
    $fails++;
} finally {
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $id], ['%d']);
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND message LIKE %s',
        (int) $round->id, '%Test Kingdom%'
    ));
    say('Test kingdom removed.');
}

exit($fails === 0 ? 0 : 1);
