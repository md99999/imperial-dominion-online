<?php
/**
 * Integration test: the league table.
 *
 * The scoring is the part worth attacking, because it decides who is winning and
 * every rule in it exists to stop a particular way of gaming the answer: losses
 * score zero so nobody profits from arranging a defeat, repeats decay so nobody
 * farms the weakest member, and nothing is ever ranked on a figure a site
 * publishes about itself.
 *
 *   php tests/integration-table.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-table.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-62s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}

global $wpdb;
$was = get_option(IDO_Settings::OPTION);
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

if (IDO_League::tables_exist()) {
    foreach ((array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues')) as $name) {
        if (strpos((string) $name, 'Table Test') !== 0) {
            say('This site holds a league this test did not create: ' . $name);
            exit(2);
        }
    }
    IDO_League::drop_tables();
}
register_shutdown_function(static function () use ($was) {
    if (IDO_League::tables_exist()) IDO_League::drop_tables();
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
});

add_filter('home_url', static function () { return 'https://hub.example.com'; }, 99);
IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };

IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();
$league = IDO_League_Setup::found(['league_name' => 'Table Test ' . wp_rand(100, 999)]);

/** A paired peer with claimed figures. */
function peer(string $name, int $empires, int $networth): object {
    global $wpdb, $league;
    $wpdb->insert(IDO_DB::t('sites'), [
        'league_id' => (int) $league->id, 'site_uuid' => IDO_League_Crypto::uuid(),
        'site_name' => $name, 'site_url' => 'https://' . strtolower($name) . '.example.com',
        'secret' => IDO_League_Crypto::secret(), 'status' => 'active',
        'empire_count' => $empires, 'networth' => $networth,
        'news_as_of' => IDO_League::now(), 'last_contact_at' => IDO_League::now(),
        'secret_issued_at' => IDO_League::now(), 'created_at' => IDO_League::now(),
    ]);
    return IDO_League_Queue::peer((int) $wpdb->insert_id);
}

/** A finished exchange. */
function march(object $peer, string $direction, string $outcome, array $spoils = []): void {
    global $wpdb, $league, $round;
    $wpdb->insert(IDO_DB::t('league_marches'), [
        'league_id' => (int) $league->id, 'peer_id' => (int) $peer->id, 'round_id' => (int) $round->id,
        'direction' => $direction, 'status' => IDO_League_Status::RESOLVED, 'outcome' => $outcome,
        'resolved_at' => IDO_League::now(), 'created_at' => IDO_League::now(),
        'spoils_json' => wp_json_encode($spoils),
    ]);
}

$weak   = peer('Weakling', 3, 500000);
$strong = peer('Colossus', 25, 900000000);
$quiet  = peer('Hermitage', 10, 4000000);

say('=== an empty league ===');
$empty = IDO_League_Table::standings();
check('every active site appears', count($empty) === 4, (string) count($empty));
check('with us among them', (bool) array_filter($empty, static fn($r) => !empty($r['is_us'])));
check('and nobody has scored', array_sum(array_column($empty, 'score')) === 0);
// With nothing fought, every site is level. The order among equals is stable and
// alphabetical, which is arbitrary on purpose: what matters is that wealth plays
// no part in it, and that is proved further down where a poor site that wins
// outranks a rich one that has not.
check('nobody leads before a blow is struck',
    count(array_unique(array_column($empty, 'score'))) === 1,
    implode(',', array_column($empty, 'score')));

say('');
say('=== a win scores, a loss does not ===');
march($weak, 'out', 'won', ['spoils_gold' => 500000, 'spoils_grain' => 0, 'spoils_iron' => 0]);
$after_win = IDO_League_Table::standings();
$us = array_values(array_filter($after_win, static fn($r) => !empty($r['is_us'])))[0];
check('winning scores', $us['score'] === IDO_League_Table::WIN_POINTS, (string) $us['score']);
check('and is recorded', (int) $us['won'] === 1);

march($strong, 'out', 'lost');
$after_loss = IDO_League_Table::standings();
$us = array_values(array_filter($after_loss, static fn($r) => !empty($r['is_us'])))[0];
check('losing changes nothing', $us['score'] === IDO_League_Table::WIN_POINTS, (string) $us['score']);
check('but is recorded honestly', (int) $us['lost'] === 1);
check('a loss never scores below zero', $us['score'] >= 0);

say('');
say('=== holding the wall pays like carrying the field ===');
march($quiet, 'in', 'held');
$after_hold = IDO_League_Table::standings();
$us = array_values(array_filter($after_hold, static fn($r) => !empty($r['is_us'])))[0];
check('repelling a march scores',
    $us['score'] === IDO_League_Table::WIN_POINTS + IDO_League_Table::REPEL_POINTS, (string) $us['score']);
check('and is recorded as a defence', (int) $us['repelled'] === 1);

say('');
say('=== a stalemate is worth something, but less ===');
march($quiet, 'out', 'drawn');
$us = array_values(array_filter(IDO_League_Table::standings(), static fn($r) => !empty($r['is_us'])))[0];
check('a draw scores less than a win',
    IDO_League_Table::DRAW_POINTS < IDO_League_Table::WIN_POINTS);
check('and more than a loss', IDO_League_Table::DRAW_POINTS > 0);
check('the draw is recorded', (int) $us['drawn'] === 1);

say('');
say('=== farming the weakest member does not pay ===');
$one = IDO_League_Table::decayed(1);
$two = IDO_League_Table::decayed(2);
$five = IDO_League_Table::decayed(5);
check('two wins beat one', $two > $one, sprintf('%.2f vs %.2f', $two, $one));
check('but are worth less than two separate firsts', $two < $one * 2, sprintf('%.2f vs %.2f', $two, $one * 2));
check('and five are worth well under five', $five < $one * 5, sprintf('%.2f vs %.2f', $five, $one * 5));
check('each one still adds something', IDO_League_Table::decayed(6) > $five);
check('nothing is nothing', IDO_League_Table::decayed(0) === 0.0);

say('');
say('=== the peer sees the mirror of what we see ===');
$rows = [];
foreach (IDO_League_Table::standings() as $row) $rows[$row['name']] = $row;
// The mirror has to be exact about which side did what. We marched on Weakling
// and won, so their record is a defence broken, not a march lost: they never
// marched. Colossus threw our march back, which is a defence held for them, not
// a victory of their own.
check('a site whose walls we broke is recorded as broken, not beaten',
    (int) $rows['Weakling']['broken'] === 1 && (int) $rows['Weakling']['lost'] === 0,
    $rows['Weakling']['broken'] . ' broken, ' . $rows['Weakling']['lost'] . ' lost');
check('a site that threw us back is recorded as having held',
    (int) $rows['Colossus']['repelled'] === 1 && (int) $rows['Colossus']['won'] === 0,
    $rows['Colossus']['repelled'] . ' held, ' . $rows['Colossus']['won'] . ' won');
check('and scores for the defence', $rows['Colossus']['score'] === IDO_League_Table::REPEL_POINTS,
    (string) $rows['Colossus']['score']);
check('a site whose march we held is recorded as having lost it',
    (int) $rows['Hermitage']['lost'] === 1);
check('our spoils are their losses', $rows['Weakling']['spoils'] < 0, (string) $rows['Weakling']['spoils']);

say('');
say('=== claimed wealth is carried, never ranked on ===');
check('the claim is shown', (int) $rows['Colossus']['networth'] === 900000000);
check('and marked as a claim', !empty($rows['Colossus']['claimed']));
check('with the date it was claimed', !empty($rows['Colossus']['as_of']));
check('our own figures are not a claim', empty($rows[$league->site_name]['claimed'])
    || $rows[$league->site_name]['is_us']);
// The property that matters: a poor site that has won outranks a rich one that
// has not, so the table cannot be climbed by publishing a bigger number.
$standings_now = IDO_League_Table::standings();
$positions = [];
foreach ($standings_now as $row) $positions[$row['name']] = (int) $row['position'];
check('a site with fewer points sits below one with more, whatever it claims',
    $positions[$league->site_name] < $positions['Weakling'],
    sprintf('%s at %d, Weakling at %d, though Weakling claims %s',
        $league->site_name, $positions[$league->site_name], $positions['Weakling'],
        number_format($rows['Weakling']['networth'])));
check('and the richest claim in the league does not sit top on it alone',
    $standings_now[0]['score'] >= $rows['Colossus']['score']);

say('');
say('=== what is not a battle is not in the record ===');
foreach (['failed', 'cancelled', 'lost_contact', 'refused'] as $non_battle) {
    march($weak, 'out', $non_battle);
}
$after_noise = array_values(array_filter(IDO_League_Table::standings(),
    static fn($r) => !empty($r['is_us'])))[0];
check('a muster that never marched is not a loss', (int) $after_noise['lost'] === 1,
    (string) $after_noise['lost']);
check('nor a cancelled one, nor one that vanished', (int) $after_noise['won'] === 1);
check('and the score is unmoved', $after_noise['score'] === $us['score'],
    $after_noise['score'] . ' vs ' . $us['score']);

say('');
say('=== the order is stable ===');
$a = array_column(IDO_League_Table::standings(), 'name');
$same = true;
for ($i = 0; $i < 20; $i++) {
    if (array_column(IDO_League_Table::standings(), 'name') !== $a) $same = false;
}
check('the same table every time', $same, implode(' > ', $a));
check('and positions run from one', IDO_League_Table::standings()[0]['position'] === 1);

say('');
say('=== recent exchanges ===');
$recent = IDO_League_Table::recent(5);
check('the feed is populated', count($recent) > 0, (string) count($recent));
check('and capped at what was asked for', count($recent) <= 5);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
