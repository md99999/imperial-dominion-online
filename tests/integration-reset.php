<?php
/**
 * Integration test: the board reset.
 *
 * The thing to prove is that the board is genuinely new rather than
 * new-looking. A reset that leaves one stale number behind is worse than no
 * reset, because everybody believes it worked.
 *
 *   php tests/integration-reset.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-reset.php /path/to/wordpress [db-host]'); exit(2); }
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
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (IDO_Game_Exception $e) { return $e->getMessage(); }
}

global $wpdb;
$was = get_option(IDO_Settings::OPTION);

// A reset touches every empire in the round, so this test needs a round of its
// own rather than the live one.
$wpdb->insert(IDO_DB::t('rounds'), [
    'round_name' => 'Reset Test ' . wp_rand(1000, 9999), 'status' => 'pending',
    'starts_at' => IDO_Game::now(), 'ends_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 60),
    'created_at' => IDO_Game::now(),
]);
$round_id = (int) $wpdb->insert_id;
if (!$round_id) { say('Could not create a test round.'); exit(1); }

if (IDO_League::tables_exist()) {
    foreach ((array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues')) as $name) {
        if (strpos((string) $name, 'Reset Test') !== 0) {
            say('This site holds a league this test did not create: ' . $name);
            exit(2);
        }
    }
    IDO_League::drop_tables();
}

register_shutdown_function(static function () use ($round_id, $was) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d', $round_id));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('constructions') . ' WHERE round_id = %d', $round_id));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('listings') . ' WHERE round_id = %d', $round_id));
    $wpdb->delete(IDO_DB::t('rounds'), ['id' => $round_id], ['%d']);
    if (IDO_League::tables_exist()) IDO_League::drop_tables();
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
    fwrite(STDERR, 'Test round removed.' . PHP_EOL);
});

/** A rich, developed empire: the opposite of a founding. */
function empire(int $round_id, string $name): object {
    global $wpdb;
    static $user = 999600;
    $user++;
    $wpdb->insert(IDO_DB::t('kingdoms'), [
        'round_id' => $round_id, 'user_id' => $user,
        'kingdom_name' => $name . ' ' . $user, 'ruler_name' => $name . ' Ruler ' . $user,
        'turns' => 27, 'turns_spent' => 400, 'last_turn_grant' => IDO_Game::today(),
        'land' => 4000, 'land_in_progress' => 120,
        'gold' => 9000000, 'grain' => 800000, 'iron' => 400000, 'peasants' => 20000,
        'b_homestead' => 400, 'b_farmstead' => 300, 'b_mint' => 200, 'b_foundry' => 150,
        'b_barracks' => 60, 'b_fortification' => 70,
        'u_pawn' => 9000, 'u_legionnaire' => 7000, 'u_centurion' => 4000, 'u_ballista_legion' => 900,
        'catapults' => 60, 'catapults_in_progress' => 10, 'agents' => 1,
        'attacks_made' => 30, 'attacks_won' => 20, 'attacks_suffered' => 12,
        'land_taken' => 900, 'land_lost' => 400,
        'networth' => 12000000, 'is_defeated' => 0,
        'created_at' => IDO_Game::now(), 'last_seen' => IDO_Game::now(),
    ]);
    return IDO_Kingdom::find((int) $wpdb->insert_id);
}

$a = empire($round_id, 'Reset A');
$b = empire($round_id, 'Reset B');
$names = [(int) $a->id => $a->kingdom_name, (int) $b->id => $b->kingdom_name];
$users = [(int) $a->id => (int) $a->user_id, (int) $b->id => (int) $b->user_id];

// Work in the yards and goods on the market: in-flight commitments.
$wpdb->insert(IDO_DB::t('constructions'), ['round_id' => $round_id, 'kingdom_id' => (int) $a->id,
    'kind' => 'building', 'building' => 'homestead', 'qty' => 50,
    'ready_on' => IDO_Game::today(), 'created_at' => IDO_Game::now()]);
$wpdb->insert(IDO_DB::t('listings'), ['round_id' => $round_id, 'seller_kingdom_id' => (int) $a->id,
    'item_key' => 'grain', 'qty' => 5000, 'unit_price' => 3, 'status' => 'open',
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'created_at' => IDO_Game::now()]);

say('=== a developed board is not a ruined one ===');
IDO_Settings::update(['board_ruin_percent' => 25]);
check('two rich empires are not called finished', !IDO_Board::is_ruined($round_id));
$report = IDO_Board::ruin_report($round_id);
check('the threshold is derived from the founding grant', $report['threshold'] > 0,
    number_format($report['threshold']));
check('and the board sits well above it', $report['worth'] > $report['threshold'] * 4,
    number_format($report['worth']) . ' vs ' . number_format($report['threshold']));

say('');
say('=== a flattened board is ===');
$wpdb->query($wpdb->prepare('UPDATE ' . IDO_DB::t('kingdoms')
    . ' SET networth = 1000 WHERE round_id = %d', $round_id));
check('a board worth almost nothing is finished', IDO_Board::is_ruined($round_id));
IDO_Settings::update(['board_ruin_percent' => 0]);
check('setting the threshold to zero switches it off', !IDO_Board::is_ruined($round_id));
IDO_Settings::update(['board_ruin_percent' => 25]);

say('');
say('=== the reset ===');
$result = IDO_Board::reset($round_id, 'Test.');
check('both empires were refounded', $result['empires'] === 2, (string) $result['empires']);
check('and the in-flight orders cleared', $result['cleared'] >= 2, (string) $result['cleared']);

$package = IDO_Kingdom::starting_package();
foreach ([$a->id, $b->id] as $id) {
    $fresh = IDO_Kingdom::find((int) $id);
    $label = 'empire ' . $id;

    check("$label keeps its name", $fresh->kingdom_name === $names[(int) $id]);
    check("$label keeps its player", (int) $fresh->user_id === $users[(int) $id]);

    check("$label has the founding land", (int) $fresh->land === (int) $package['land'],
        $fresh->land . ' vs ' . $package['land']);
    check("$label has the founding gold", (int) $fresh->gold === (int) $package['gold']);
    check("$label has the founding grain", (int) $fresh->grain === (int) $package['grain']);
    check("$label has the founding turns", (int) $fresh->turns === (int) $package['turns']);
    check("$label has the founding pawns", (int) $fresh->u_pawn === (int) $package['u_pawn']);

    // Everything a founding does not grant has to be gone, not reduced.
    foreach (['land_in_progress' => 0, 'b_homestead' => 0, 'b_farmstead' => 0, 'b_mint' => 0,
              'b_foundry' => 0, 'b_barracks' => 0, 'b_fortification' => 0,
              'u_centurion' => 0, 'u_ballista_legion' => 0, 'catapults' => 0,
              'catapults_in_progress' => 0, 'agents' => 0, 'turns_spent' => 0,
              'attacks_made' => 0, 'attacks_won' => 0, 'attacks_suffered' => 0,
              'land_taken' => 0, 'land_lost' => 0] as $column => $expected) {
        check("  $label has no $column left", (int) $fresh->{$column} === $expected,
            (string) $fresh->{$column});
    }

    check("$label is not marked defeated", (int) $fresh->is_defeated === 0);
    check("$label is worth a founding, no more",
        (int) $fresh->networth === IDO_Board::founding_worth(),
        $fresh->networth . ' vs ' . IDO_Board::founding_worth());
    check("$label is under the opening truce", IDO_Kingdom::is_protected($fresh));
}

say('');
say('=== nothing in flight survived ===');
check('the yards are empty', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('constructions') . ' WHERE round_id = %d', $round_id)) === 0);
check('and the market with it', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('listings') . ' WHERE round_id = %d', $round_id)) === 0);

say('');
say('=== the board is no longer ruined, so it does not loop ===');
check('a refounded board is not finished', !IDO_Board::is_ruined($round_id));
check('so the daily tick does nothing more', IDO_Board::reset_if_ruined($round_id) === '');

say('');
say('=== every column was covered, not just the ones I thought of ===');
// The reset builds its blank row from the column list rather than a hand-written
// one, so a column added later is zeroed instead of quietly surviving. This
// proves the list really is the source.
$developed = empire($round_id, 'Reset C');
$columns = IDO_Kingdom::numeric_columns();
$wpdb->query($wpdb->prepare('UPDATE ' . IDO_DB::t('kingdoms') . ' SET '
    . implode(', ', array_map(static fn($c) => "`$c` = 777", $columns))
    . ' WHERE id = %d', (int) $developed->id));

IDO_Board::reset($round_id, 'Test again.');
$after = IDO_Kingdom::find((int) $developed->id);
$leftovers = [];
foreach ($columns as $column) {
    $expected = array_key_exists($column, $package) ? (int) $package[$column] : 0;
    if ((int) $after->{$column} !== $expected) $leftovers[] = $column . '=' . $after->{$column};
}
check('no column kept a stale value', $leftovers === [], implode(', ', $leftovers));

// The relief cycle's own columns are not numeric-column material, so the sweep
// above cannot see them. A refounded board carrying a spent relief, or worse a
// relief truce dated into the future, would stop a ruler pledging on a board that
// is brand new.
$wpdb->update(IDO_DB::t('kingdoms'), [
    'reliefs_used' => 1, 'is_defeated' => 1,
    'defeated_at' => IDO_Game::now(),
    'relief_until' => date('Y-m-d H:i:s', current_time('timestamp') + 86400),
], ['id' => (int) $developed->id]);
IDO_Board::reset($round_id, 'Third time.');
$swept = IDO_Kingdom::find((int) $developed->id);
check('a refounded board has its relief cycle back', (int) $swept->reliefs_used === 0);
check('with nothing marked defeated', (int) $swept->is_defeated === 0);
check('no defeat on record', $swept->defeated_at === null);
check('and no relief truce left over', $swept->relief_until === null);

say('');
say('=== one empire at a time: ruin, a day, then relief ===');
IDO_Settings::update(['defeat_threshold_percent' => 25, 'defeat_grace_hours' => 24]);
$threshold = IDO_Board::relief_threshold();
check('the threshold is under a founding grant',
    $threshold > 0 && $threshold < IDO_Board::founding_worth(),
    number_format($threshold) . ' vs ' . number_format(IDO_Board::founding_worth()));

// A ruined empire: nothing left and no army.
$ruined = empire($round_id, 'Reset Ruined');
$wpdb->update(IDO_DB::t('kingdoms'), [
    'gold' => 0, 'grain' => 0, 'iron' => 0, 'peasants' => 10, 'land' => 5,
    'u_pawn' => 0, 'u_legionnaire' => 0, 'u_centurion' => 0, 'u_ballista_legion' => 0,
    'b_homestead' => 1, 'b_farmstead' => 0, 'b_mint' => 0, 'b_foundry' => 0,
    'b_barracks' => 0, 'b_fortification' => 0, 'catapults' => 0,
    'attacks_made' => 9, 'attacks_won' => 2, 'land_lost' => 300,
    'networth' => 1000,
], ['id' => (int) $ruined->id]);

// And one that is merely between armies: poor on paper, still holding soldiers.
$between = empire($round_id, 'Reset Between');
$wpdb->update(IDO_DB::t('kingdoms'), [
    'gold' => 0, 'grain' => 0, 'iron' => 0, 'networth' => 1000, 'u_legionnaire' => 500,
], ['id' => (int) $between->id]);

check('one empire is marked ruined', IDO_Board::mark_ruined($round_id) === 1);
check('the ruined one is flagged', (int) IDO_Kingdom::find((int) $ruined->id)->is_defeated === 1);
check('with the hour recorded', IDO_Kingdom::find((int) $ruined->id)->defeated_at !== null);
check('an empire between armies is left alone',
    (int) IDO_Kingdom::find((int) $between->id)->is_defeated === 0);
check('marking again changes nothing', IDO_Board::mark_ruined($round_id) === 0);
check('relief does not come the same moment', IDO_Board::relieve_due($round_id) === 0);

// Wind the clock back a day and an hour.
$wpdb->update(IDO_DB::t('kingdoms'),
    ['defeated_at' => date('Y-m-d H:i:s', current_time('timestamp') - 25 * HOUR_IN_SECONDS)],
    ['id' => (int) $ruined->id]);
check('a day later it does', IDO_Board::relieve_due($round_id) === 1);

$relieved = IDO_Kingdom::find((int) $ruined->id);
$package = IDO_Kingdom::starting_package();
check('the empire stands again', (int) $relieved->is_defeated === 0);
check('with a founding grant', (int) $relieved->gold === (int) $package['gold'],
    $relieved->gold . ' vs ' . $package['gold']);
check('and founding troops', (int) $relieved->u_pawn === (int) $package['u_pawn']);
check('and land', (int) $relieved->land === (int) $package['land'], (string) $relieved->land);
check('under a crown truce', IDO_Kingdom::is_protected($relieved));

// Relief, not a new identity.
check('it keeps its war record',
    (int) $relieved->attacks_made === 9 && (int) $relieved->attacks_won === 2,
    $relieved->attacks_made . '/' . $relieved->attacks_won);
check('and its scars', (int) $relieved->land_lost === 300);
check('and the building it still had', (int) $relieved->b_homestead === 1);
check('and its name', strpos((string) $relieved->kingdom_name, 'Reset Ruined') === 0);

check('relief is recorded as spent', (int) $relieved->reliefs_used === 1);
check('so it cannot be had twice in a round', IDO_Board::mark_ruined($round_id) === 0);
check('and is no longer available', !IDO_Board::relief_available($relieved));

say('');
say('=== relief never takes anything away ===');
$hoarder = empire($round_id, 'Reset Hoarder');
$wpdb->update(IDO_DB::t('kingdoms'), [
    'networth' => 1000, 'u_pawn' => 0, 'u_legionnaire' => 0, 'u_centurion' => 0,
    'u_ballista_legion' => 0, 'land' => 9000, 'grain' => 999999,
    'defeated_at' => date('Y-m-d H:i:s', current_time('timestamp') - 25 * HOUR_IN_SECONDS),
    'is_defeated' => 1,
], ['id' => (int) $hoarder->id]);
IDO_Board::relieve_due($round_id);
$after_relief = IDO_Kingdom::find((int) $hoarder->id);
check('land above the founding figure is kept', (int) $after_relief->land === 9000,
    (string) $after_relief->land);
check('and so is the grain', (int) $after_relief->grain === 999999);
check('while empty troops are filled to founding',
    (int) $after_relief->u_pawn === (int) $package['u_pawn']);

say('');
say('=== relief can be switched off ===');
IDO_Settings::update(['defeat_threshold_percent' => 0]);
check('a threshold of zero turns it off', IDO_Board::relief_threshold() === 0);
$off = empire($round_id, 'Reset NoRelief');
$wpdb->update(IDO_DB::t('kingdoms'), ['networth' => 1, 'u_pawn' => 0, 'u_legionnaire' => 0,
    'u_centurion' => 0, 'u_ballista_legion' => 0], ['id' => (int) $off->id]);
check('and nothing is marked', IDO_Board::mark_ruined($round_id) === 0);
IDO_Settings::update(['defeat_threshold_percent' => 25]);

say('');
say('=== in a league, the record is forfeit too ===');
add_filter('home_url', static function () { return 'https://hub.example.com'; }, 99);
IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };
IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();
$league = IDO_League_Setup::found(['league_name' => 'Reset Test ' . wp_rand(100, 999)]);
$wpdb->insert(IDO_DB::t('sites'), [
    'league_id' => (int) $league->id, 'site_uuid' => IDO_League_Crypto::uuid(),
    'site_name' => 'Northmarch', 'site_url' => 'https://member.example.com',
    'secret' => IDO_League_Crypto::secret(), 'status' => 'active',
    'secret_issued_at' => IDO_League::now(), 'created_at' => IDO_League::now(),
]);
$peer = IDO_League_Queue::peer((int) $wpdb->insert_id);

// Two wins on the board, and an army still away.
foreach (['won', 'won'] as $outcome) {
    $wpdb->insert(IDO_DB::t('league_marches'), [
        'league_id' => (int) $league->id, 'peer_id' => (int) $peer->id, 'round_id' => $round_id,
        'direction' => 'out', 'status' => IDO_League_Status::RESOLVED, 'outcome' => $outcome,
        'resolved_at' => IDO_League::now(), 'created_at' => IDO_League::now(), 'spoils_json' => '{}',
    ]);
}
$wpdb->insert(IDO_DB::t('league_marches'), [
    'league_id' => (int) $league->id, 'peer_id' => (int) $peer->id, 'round_id' => $round_id,
    'direction' => 'out', 'status' => IDO_League_Status::MARCHING,
    'sent_at' => IDO_League::now(), 'created_at' => IDO_League::now(),
]);
$away_id = (int) $wpdb->insert_id;
$wpdb->insert(IDO_DB::t('league_contributions'), [
    'march_id' => $away_id, 'kingdom_id' => (int) $a->id,
    'committed_json' => wp_json_encode(['force' => ['legionnaire' => 500]]),
    'committed_at' => IDO_League::now(),
]);

$before_score = IDO_League_Table::score(IDO_League_Table::record_for(0));
check('two wins are on the board', $before_score > 0, (string) $before_score);

IDO_Board::reset($round_id, 'Forfeit test.');
check('the score is forfeit', IDO_League_Table::score(IDO_League_Table::record_for(0)) === 0,
    (string) IDO_League_Table::score(IDO_League_Table::record_for(0)));
check('the marches are marked rather than deleted',
    (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IDO_DB::t('league_marches')
        . ' WHERE round_id = %d', $round_id)) === 3);
check('the army that was away is written off',
    (string) $wpdb->get_var($wpdb->prepare('SELECT outcome FROM ' . IDO_DB::t('league_marches')
        . ' WHERE id = %d', $away_id)) === 'void');
check('and nobody is left to credit when its result lands',
    (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IDO_DB::t('league_contributions')
        . ' WHERE march_id = %d', $away_id)) === 0);

say('');
say('=== and the site is protected while it rebuilds ===');
check('the site is under grace', IDO_Board::under_grace());
check('with an end date', IDO_Board::grace_until() !== null, (string) IDO_Board::grace_until());
check('it tells its peers so', !empty(IDO_League_News::compose()['grace_until']));
check('and says it is not accepting marches', IDO_League_News::compose()['accepting'] === false);
$why = refused(static fn() => IDO_League_Muster::call(
    IDO_Kingdom::find((int) $a->id), (int) $peer->id, ['pawn' => 10]));
check('and cannot march while the truce holds', stripos($why, 'rebuilding under a truce') !== false, $why);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
