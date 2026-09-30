<?php
/**
 * Integration test: a disaster against a real database.
 *
 * disaster-test.php proves the calculations. This proves the part that has gone
 * wrong before on this project: that what was calculated actually reaches the
 * empires table. A $wpdb->update() naming a column that does not exist fails the
 * whole statement, silently, and every stubbed test in the world still passes.
 *
 * The odds are turned up to their floor and a large batch of turns is spent, so
 * a disaster is effectively certain to land. Exactly one must, however many turns
 * are spent, because disasters do not combine.
 *
 *   php tests/integration-disaster.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-disaster.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-60s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}

if (!class_exists('IDO_Disasters')) { say('The plugin is not active on that site.'); exit(1); }

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

$was = get_option(IDO_Settings::OPTION);
$name = 'Test Empire ' . wp_rand(1000, 9999);
$wpdb->insert(IDO_DB::t('kingdoms'), [
    'round_id' => (int) $round->id, 'user_id' => 999999,
    'kingdom_name' => $name, 'ruler_name' => $name . ' Ruler',
    'turns' => 10, 'last_turn_grant' => IDO_Game::today(),
    'land' => 4000, 'gold' => 5000000, 'grain' => 2000000, 'iron' => 50000,
    'peasants' => 30000,
    'b_homestead' => 1000, 'b_farmstead' => 800, 'b_mint' => 200, 'b_foundry' => 200,
    'created_at' => IDO_Game::now(),
]);
$id = (int) $wpdb->insert_id;
if (!$id) { say('Could not create the test empire: ' . $wpdb->last_error); exit(1); }

register_shutdown_function(static function () use ($id, $was, $round) {
    global $wpdb;
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $id]);
    $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s'
        . ' AND message LIKE %s', (int) $round->id, 'disaster', '%Test Empire%'
    ));
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
});

// The floor of the odds, so one lands inside a reasonable number of turns.
IDO_Settings::update(['disasters_enabled' => 1, 'disaster_one_in' => 10, 'disaster_percent' => 7]);

$before = IDO_Kingdom::find($id);
check('the empire is eligible to be struck', IDO_Disasters::eligible($before));

$news_before = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s',
    (int) $round->id, 'disaster'));

// 400 turns at one-in-ten: the chance of no disaster at all is about 1 in 10^18.
$lines = IDO_Economy::advance($before, 400);
$after = IDO_Kingdom::find($id);

$warnings = [];
foreach ($lines as $l) { if (is_array($l)) $warnings[] = $l[1]; }
say('');
say('What the ruler was told:');
foreach ($warnings as $w) say('  - ' . $w);
say('');

$news_after = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s',
    (int) $round->id, 'disaster'));

check('exactly one disaster reached the gazette', $news_after - $news_before === 1,
    (string) ($news_after - $news_before));

$gazette = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT message FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s'
    . ' ORDER BY id DESC LIMIT 1', (int) $round->id, 'disaster'));
check('and it names the empire', strpos($gazette, $name) !== false, $gazette);

// Which one landed, and did the table actually move?
$farm_lost = (int) $before->b_farmstead - (int) $after->b_farmstead;
$home_lost = (int) $before->b_homestead - (int) $after->b_homestead;
$hit = $farm_lost > 0 ? 'drought' : ($home_lost > 0 ? 'flood' : 'insects');
say('The disaster was: ' . $hit);

check('exactly one kind of thing was destroyed',
    ($farm_lost > 0 ? 1 : 0) + ($home_lost > 0 ? 1 : 0) <= 1,
    "farmsteads -$farm_lost, homesteads -$home_lost");

if ($hit === 'drought') {
    check('the farmstead column really moved in the database', $farm_lost === 56,
        $farm_lost . ' of 800');
    check('and homesteads were left alone', $home_lost === 0, (string) $home_lost);
} elseif ($hit === 'flood') {
    check('the homestead column really moved in the database', $home_lost === 70,
        $home_lost . ' of 1000');
    check('and farmsteads were left alone', $farm_lost === 0, (string) $farm_lost);
} else {
    check('no building was touched by insects', $farm_lost === 0 && $home_lost === 0);
    check('and the ruler was told about grain',
        strpos(implode(' ', $warnings), 'insects') !== false);
}

check('the land itself is untouched: acres go back to wilderness',
    (int) $after->land === (int) $before->land,
    $before->land . ' -> ' . $after->land);
check('net worth was recalculated afterwards', (int) $after->networth > 0,
    IDO_Game::fmt((int) $after->networth));
check('exactly one warning mentions a disaster',
    count(array_filter($warnings, static fn($w) =>
        strpos($w, 'drought') !== false || strpos($w, 'insects') !== false
        || strpos($w, 'river') !== false)) === 1);

say('');
say('=== grace really does hold, through advance() and not just eligible() ===');
$wpdb->update(IDO_DB::t('kingdoms'), [
    'protection_until' => gmdate('Y-m-d H:i:s', current_time('timestamp') + 86400),
    'b_farmstead' => 800, 'b_homestead' => 1000, 'grain' => 2000000,
], ['id' => $id]);
$truced = IDO_Kingdom::find($id);
$news_pre = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s',
    (int) $round->id, 'disaster'));

IDO_Economy::advance($truced, 400);
$still = IDO_Kingdom::find($id);
$news_post = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s',
    (int) $round->id, 'disaster'));

check('400 turns under the crown truce and nothing was destroyed',
    (int) $still->b_farmstead === 800 && (int) $still->b_homestead === 1000,
    $still->b_farmstead . ' farmsteads, ' . $still->b_homestead . ' homesteads');
check('and the gazette stayed quiet', $news_post === $news_pre);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
