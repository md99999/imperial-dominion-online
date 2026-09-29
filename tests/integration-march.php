<?php
/**
 * Integration test: a muster and a march, end to end, against a real database.
 *
 * Calls a muster, pledges forces from three empires, withdraws one, closes the
 * window, sends the war packet, fights it as the defending site, sends the
 * result back, opens it, and checks that every soldier and every coin ended up
 * where it should.
 *
 *   php tests/integration-march.php /path/to/wordpress [db-host]
 *
 * The site plays both ends, which works here because a packet is fed to the
 * handler that should receive it rather than looped back: the envelope binds
 * sender and recipient, so each direction is exercised with a packet actually
 * addressed to the side reading it.
 *
 * Every empire it creates is deleted on the way out, including after a failure.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-march.php /path/to/wordpress [db-host]'); exit(2); }
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
function clear_rate_limits(): void {
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_ido_rl_%'"
        . " OR option_name LIKE '_transient_timeout_ido_rl_%'");
    wp_cache_flush();
}

if (!class_exists('IDO_League_Muster')) { say('The plugin is older than 2.5.0 or not active.'); exit(1); }

global $wpdb;
$was = get_option(IDO_Settings::OPTION);
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

if (IDO_League::tables_exist()) {
    foreach ((array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues')) as $name) {
        if (strpos((string) $name, 'March Test') !== 0) {
            say('This site holds a league this test did not create: ' . $name);
            exit(2);
        }
    }
    IDO_League::drop_tables();
}

$made = [];
register_shutdown_function(static function () use (&$made, $was) {
    global $wpdb;
    foreach ($made as $id) $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $id], ['%d']);
    if (IDO_League::tables_exist()) IDO_League::drop_tables();
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
    clear_rate_limits();
    fwrite(STDERR, 'Test empires removed.' . PHP_EOL);
});

/** A throwaway empire with a known army and treasury. */
function empire(string $name, array $fields = []): object {
    global $wpdb, $round, $made;
    // One empire per user per round is a unique key, so each throwaway empire
    // needs its own fake user id. Deliberately far above any real one.
    static $fake_user = 999900;
    $fake_user++;
    $wpdb->insert(IDO_DB::t('kingdoms'), array_merge([
        'round_id' => (int) $round->id, 'user_id' => $fake_user,
        'kingdom_name' => $name, 'ruler_name' => $name . ' Ruler',
        'turns' => 60, 'last_turn_grant' => IDO_Game::today(),
        'land' => 500, 'gold' => 1000000, 'grain' => 200000, 'iron' => 100000, 'peasants' => 3000,
        'b_homestead' => 100, 'b_farmstead' => 100, 'b_fortification' => 20,
        'u_pawn' => 2000, 'u_legionnaire' => 1500, 'u_centurion' => 800, 'u_ballista_legion' => 200,
        'catapults' => 20, 'agents' => 1,
        'created_at' => IDO_Game::now(),
    ], $fields));
    $id = (int) $wpdb->insert_id;
    if (!$id) {
        fwrite(STDERR, 'Could not create ' . $name . ': ' . $wpdb->last_error . PHP_EOL);
        exit(1);
    }
    $made[] = $id;
    return IDO_Kingdom::find($id);
}

add_filter('home_url', static function () { return 'https://hub.example.com'; }, 99);
IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };
clear_rate_limits();

IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();
$league = IDO_League_Setup::found(['league_name' => 'March Test ' . wp_rand(100, 999)]);

$secret    = IDO_League_Crypto::secret();
$peer_uuid = IDO_League_Crypto::uuid();
$wpdb->insert(IDO_DB::t('sites'), [
    'league_id' => (int) $league->id, 'site_uuid' => $peer_uuid, 'site_name' => 'Northmarch',
    'site_url' => 'https://member.example.com', 'secret' => $secret, 'status' => 'active',
    'secret_issued_at' => IDO_League::now(), 'created_at' => IDO_League::now(),
]);
$peer = IDO_League_Queue::peer((int) $wpdb->insert_id);

// The peer accepts whatever it is sent; the packets are inspected here instead.
$sent_packets = [];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$sent_packets) {
    if (strpos($url, '/ido/v1/packet') === false) return $pre;
    $sent_packets[] = (string) ($args['body'] ?? '');
    return ['headers' => [], 'cookies' => [], 'filename' => null,
            'response' => ['code' => 202, 'message' => ''], 'body' => '{"status":"accepted"}'];
}, 10, 3);

$caller  = empire('March Caller ' . wp_rand(100, 999));
$joiner  = empire('March Joiner ' . wp_rand(100, 999));
$quitter = empire('March Quitter ' . wp_rand(100, 999));

say('=== calling a muster ===');
$before_turns = (int) $caller->turns;
IDO_League_Muster::call($caller, (int) $peer->id, ['legionnaire' => 900, 'centurion' => 600], ['catapult' => 4], true);
$march = IDO_League_Muster::open();
check('a muster is open', $march !== null);
check('with the caller named', $march && (int) $march->called_by === (int) $caller->id);
check('and a closing date', $march && $march->muster_closes_at !== null);

$caller = IDO_Kingdom::reload($caller);
check('the caller paid the turns', (int) $caller->turns === $before_turns - IDO_League_Muster::CALL_TURN_COST,
    $caller->turns . ' of ' . $before_turns);
check('their legionnaires left the empire', (int) $caller->u_legionnaire === 600, (string) $caller->u_legionnaire);
check('their catapults with them', (int) $caller->catapults === 16, (string) $caller->catapults);
check('and their agent rode ahead', (int) $caller->agents === 0);
check('who holds the one agent slot',
    IDO_League_Covert::slot_holder((int) $march->id) === (int) $caller->id);

say('');
say('=== a second muster cannot be called ===');
check('one army at a time', refused(static fn() => IDO_League_Muster::call(
    $joiner, (int) $peer->id, ['pawn' => 10])) !== '');

say('');
say('=== joining ===');
// Asking to send a second agent is refused outright rather than quietly
// dropping the agent, so a ruler is never left believing theirs went.
$turns_before = (int) IDO_Kingdom::reload($joiner)->turns;
$why = refused(static fn() => IDO_League_Muster::join($joiner, (int) $march->id, ['legionnaire' => 900], [], true));
check('a second agent is refused', stripos($why, 'Only one goes with the army') !== false, $why);
check('and the refused pledge cost no turn',
    (int) IDO_Kingdom::reload($joiner)->turns === $turns_before,
    IDO_Kingdom::reload($joiner)->turns . ' of ' . $turns_before);
check('nor did it take the force', (int) IDO_Kingdom::reload($joiner)->u_legionnaire === 1500);

IDO_League_Muster::join($joiner, (int) $march->id, ['legionnaire' => 900]);
$joiner = IDO_Kingdom::reload($joiner);
check('pledging without the agent works', (int) $joiner->u_legionnaire === 600, (string) $joiner->u_legionnaire);
check('and the joiner keeps their agent', (int) $joiner->agents === 1);
check('and the slot is still the caller\'s',
    IDO_League_Covert::slot_holder((int) $march->id) === (int) $caller->id);
check('pledging twice is refused', refused(static fn() => IDO_League_Muster::join(
    $joiner, (int) $march->id, ['pawn' => 5])) !== '');
check('pledging more than you have is refused', refused(static fn() => IDO_League_Muster::join(
    $quitter, (int) $march->id, ['centurion' => 999999])) !== '');
check('an unmanned siege train is refused', refused(static fn() => IDO_League_Muster::join(
    $quitter, (int) $march->id, ['pawn' => 1], ['catapult' => 20])) !== '');
check('pledging nothing is refused', refused(static fn() => IDO_League_Muster::join(
    $quitter, (int) $march->id, [])) !== '');

say('');
say('=== withdrawing while the window is open ===');
IDO_League_Muster::join($quitter, (int) $march->id, ['pawn' => 400]);
$quitter = IDO_Kingdom::reload($quitter);
check('the pledge left the empire', (int) $quitter->u_pawn === 1600, (string) $quitter->u_pawn);
IDO_League_Muster::withdraw($quitter, (int) $march->id);
$quitter = IDO_Kingdom::reload($quitter);
check('withdrawing brings it back', (int) $quitter->u_pawn === 2000, (string) $quitter->u_pawn);
check('and the pledge is gone', IDO_League_Muster::contribution((int) $march->id, (int) $quitter->id) === null);
check('the caller cannot abandon their own muster',
    refused(static fn() => IDO_League_Muster::withdraw($caller, (int) $march->id)) !== '');

say('');
say('=== closing the window ===');
$assembled = IDO_League_Muster::assembled((int) $march->id);
check('the army is the sum of the pledges',
    (int) $assembled['force']['legionnaire'] === 1800 && (int) $assembled['force']['centurion'] === 600,
    json_encode($assembled['force']));
check('and it clears the minimum',
    IDO_Units::offence_power($assembled['force']) >= IDO_League_Muster::minimum_force((int) $round->id),
    sprintf('%.0f vs %.0f', IDO_Units::offence_power($assembled['force']),
        IDO_League_Muster::minimum_force((int) $round->id)));
check('and the siege train with it', (int) $assembled['weapons']['catapult'] === 4);

$wpdb->update(IDO_DB::t('league_marches'),
    ['muster_closes_at' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => (int) $march->id]);
$before_packets = count($sent_packets);
IDO_League_Muster::close_due();
IDO_League_Queue::flush();

$march = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('league_marches')
    . ' WHERE id = %d', (int) $march->id));
check('the march is now marching', (string) $march->status === IDO_League_Status::MARCHING,
    (string) $march->status);
check('a war packet went out', count($sent_packets) === $before_packets + 1);
check('and the march remembers which one', (string) $march->packet_uuid !== '');

$war = IDO_League_Crypto::open($sent_packets[$before_packets], $secret);
check('the packet opens', is_array($war));
check('it is a war packet', $war && $war['type'] === 'war');
check('carrying the whole army', $war && (int) $war['body']['force']['legionnaire'] === 1800);
check('and the agent flag', $war && $war['body']['agent'] === true);
check('but no per-empire figures', $war && !isset($war['body']['contributions']));

say('');
say('=== the defending site fights it ===');
// Defenders on the other side: reuse this site, which is what the handler will
// read. The attacker's own empires are among them, which is fine: what is under
// test is that the whole site pays, by share.
$defence_before = [];
foreach (IDO_League_Battle::defenders((int) $round->id) as $row) {
    $defence_before[(int) $row->id] = ['gold' => (int) $row->gold, 'legionnaire' => (int) $row->u_legionnaire];
}
$site_gold_before = array_sum(array_column($defence_before, 'gold'));

$result = IDO_League_March::receive_war($peer, $war['body']);
check('the defending site resolves it', $result === '', $result);

$after = [];
foreach (IDO_League_Battle::defenders((int) $round->id) as $row) $after[(int) $row->id] = (int) $row->gold;
$site_gold_after = array_sum($after);
check('the site is lighter or held the field', $site_gold_after <= $site_gold_before,
    $site_gold_before . ' -> ' . $site_gold_after);

$inbound = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('league_marches')
    . " WHERE league_id = %d AND direction = 'in' ORDER BY id DESC LIMIT 1", (int) $league->id));
check('the defender recorded the battle', (bool) $inbound);
check('as resolved at once, with no waiting state',
    $inbound && (string) $inbound->status === IDO_League_Status::RESOLVED);

IDO_League_Queue::flush();
$result_wire = end($sent_packets);
$result_packet = IDO_League_Crypto::open($result_wire, $secret);
check('a result packet went back', $result_packet && $result_packet['type'] === 'result');
check('quoting the same march', $result_packet
    && $result_packet['body']['march'] === (string) $war['body']['march']);
check('with an outcome', $result_packet
    && in_array($result_packet['body']['outcome'], ['won', 'lost'], true),
    $result_packet ? (string) $result_packet['body']['outcome'] : '');
check('and the agent accounted for', $result_packet
    && in_array($result_packet['body']['agent'], ['success', 'failed', 'hanged'], true),
    $result_packet ? (string) $result_packet['body']['agent'] : '');

say('');
say('=== the dispatches come home ===');
$caller_before  = IDO_Kingdom::reload($caller);
$joiner_before  = IDO_Kingdom::reload($joiner);

$body = $result_packet['body'];
$body['march'] = (string) $march->packet_uuid;    // match the outbound march
$applied = IDO_League_March::receive_result($peer, $body);
check('the result applies', $applied === '', $applied);

$march = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('league_marches')
    . ' WHERE id = %d', (int) $march->id));
check('the march is resolved', (string) $march->status === IDO_League_Status::RESOLVED);
check('with an outcome recorded', in_array((string) $march->outcome, ['won', 'lost'], true),
    (string) $march->outcome);
check('and a report a ruler can read', strlen((string) $march->report) > 20, (string) $march->report);

$caller_after = IDO_Kingdom::reload($caller);
$joiner_after = IDO_Kingdom::reload($joiner);
check('survivors came back to the caller',
    (int) $caller_after->u_legionnaire > (int) $caller_before->u_legionnaire,
    $caller_before->u_legionnaire . ' -> ' . $caller_after->u_legionnaire);
check('and to the joiner',
    (int) $joiner_after->u_legionnaire > (int) $joiner_before->u_legionnaire,
    $joiner_before->u_legionnaire . ' -> ' . $joiner_after->u_legionnaire);
// Each ruler gets their own men back, not a share of everybody's. The caller
// sent 900 legionnaires and 600 centurions; the joiner sent 900 legionnaires and
// no centurions, so the joiner must get back no centurions at all.
$caller_home = (int) $caller_after->u_legionnaire - (int) $caller_before->u_legionnaire;
$joiner_home = (int) $joiner_after->u_legionnaire - (int) $joiner_before->u_legionnaire;
check('neither got back more legionnaires than they sent',
    $caller_home <= 900 && $joiner_home <= 900, $caller_home . ' and ' . $joiner_home);
check('and they sent the same number, so they got the same back',
    abs($caller_home - $joiner_home) <= 1, $caller_home . ' vs ' . $joiner_home);
check('the joiner got no centurions, having sent none',
    (int) $joiner_after->u_centurion === (int) $joiner_before->u_centurion,
    $joiner_before->u_centurion . ' -> ' . $joiner_after->u_centurion);
check('the caller got their centurions back',
    (int) $caller_after->u_centurion > (int) $caller_before->u_centurion);
check('and no more of them than they sent',
    (int) $caller_after->u_centurion - (int) $caller_before->u_centurion <= 600);
check('the siege train went home to its owner',
    (int) $caller_after->catapults >= (int) $caller_before->catapults
    && (int) $joiner_after->catapults === (int) $joiner_before->catapults);

$contributions = IDO_League_Muster::contributions((int) $march->id);
check('both contributions are settled', count(array_filter($contributions,
    static fn($c) => $c->settled_at !== null)) === 2);

// The caller sent 700 of the 1000 offence-weighted force, so takes the larger share.
$by_kingdom = [];
foreach ($contributions as $c) $by_kingdom[(int) $c->kingdom_id] = $c;
$caller_share = $by_kingdom[(int) $caller->id] ?? null;
$joiner_share = $by_kingdom[(int) $joiner->id] ?? null;
check('spoils are split, not duplicated', $caller_share && $joiner_share
    && (int) $caller_share->spoils_gold >= (int) $joiner_share->spoils_gold,
    ($caller_share ? $caller_share->spoils_gold : '?') . ' vs ' . ($joiner_share ? $joiner_share->spoils_gold : '?'));

check('the agent is accounted for either way',
    (int) $caller_after->agents === ((string) $march->agent_outcome === 'hanged' ? 0 : 1),
    'outcome ' . (string) $march->agent_outcome . ', agents ' . $caller_after->agents);

say('');
say('=== the site is one empire, except where a cap says otherwise ===');
// In league mode the site really is one empire, so the battle adds it up and
// runs the local rules on the total. That is exact for anything linear and
// wrong for anything capped, and the difference is big enough to matter.
$defenders = IDO_League_Battle::defenders((int) $round->id);
$sheet = IDO_League_Battle::site_sheet($defenders);

$summed_units = 0.0;
$summed_weapons = 0.0;
foreach ($defenders as $row) {
    $summed_units   += IDO_Units::defence_power($row);
    $summed_weapons += IDO_Weapons::defence_power($row);
}
check('troop defence aggregates exactly',
    abs(IDO_Units::defence_power($sheet) - $summed_units) < 0.001,
    sprintf('%.1f vs %.1f', IDO_Units::defence_power($sheet), $summed_units));
check('siege weapon defence too',
    abs(IDO_Weapons::defence_power($sheet) - $summed_weapons) < 0.001);
check('so the site sheet is the site, for those',
    abs(IDO_League_Battle::defence_power($defenders) - ($summed_units + $summed_weapons)) < 0.001);

// Walls are the exception, and the sheet deliberately does not carry them.
check('the sheet holds no fortifications to be tempted by',
    !isset($sheet->b_fortification) || (int) $sheet->b_fortification === 0);

$ten = [];
for ($i = 0; $i < 10; $i++) {
    $ten[] = (object) ['b_fortification' => 20, 'u_pawn' => 1000, 'u_legionnaire' => 500,
                       'u_centurion' => 0, 'u_ballista_legion' => 0,
                       'catapults' => 0, 'catapults_in_progress' => 0];
}
$per_empire = 0.0;
foreach ($ten as $e) $per_empire += IDO_Units::defence_power($e) * IDO_Buildings::fortification_bonus($e);
$naive = (object) ['b_fortification' => 200, 'u_pawn' => 10000, 'u_legionnaire' => 5000,
                   'u_centurion' => 0, 'u_ballista_legion' => 0,
                   'catapults' => 0, 'catapults_in_progress' => 0];
$aggregated = IDO_Units::defence_power($naive) * IDO_Buildings::fortification_bonus($naive);
check('aggregating walls would make a site stronger for being numerous',
    $aggregated > $per_empire * 1.2,
    sprintf('%.0f vs %.0f, %.1f%% more', $aggregated, $per_empire, ($aggregated / $per_empire - 1) * 100));
check('so the battle counts them per empire instead',
    abs(IDO_League_Battle::defended_power($ten, 0.0, 0.0) - $per_empire) < 0.001,
    sprintf('%.0f vs %.0f', IDO_League_Battle::defended_power($ten, 0.0, 0.0), $per_empire));

say('');
say('=== a dispatch cannot be read twice ===');
$gold_before_replay = (int) IDO_Kingdom::reload($caller)->gold;
IDO_League_March::receive_result($peer, $body);
check('applying the same result again changes nothing',
    (int) IDO_Kingdom::reload($caller)->gold === $gold_before_replay);

say('');
say('=== a lying defender cannot mint anything ===');
$liar = $body;
$liar['march'] = IDO_League_Crypto::uuid();
check('a result for a march that does not exist is refused',
    IDO_League_March::receive_result($peer, $liar) !== '');

say('');
say('=== an army nobody hears about again ===');
$stranded = empire('March Stranded ' . wp_rand(100, 999));
IDO_League_Muster::call($stranded, (int) $peer->id, ['pawn' => 500]);
$lost_march = IDO_League_Muster::open();
$wpdb->update(IDO_DB::t('league_marches'), [
    'status'  => IDO_League_Status::MARCHING,
    'sent_at' => gmdate('Y-m-d H:i:s', time() - (IDO_League_March::escrow_timeout_days() + 1) * DAY_IN_SECONDS),
], ['id' => (int) $lost_march->id]);

$stranded_before = (int) IDO_Kingdom::reload($stranded)->u_pawn;
check('the pawns are away', $stranded_before === 1500, (string) $stranded_before);
$returned = IDO_League_March::release_timed_out();
check('the timeout returns one army', $returned === 1, (string) $returned);
check('and the pawns are home', (int) IDO_Kingdom::reload($stranded)->u_pawn === 2000);
check('the march is closed', (string) $wpdb->get_var($wpdb->prepare(
    'SELECT status FROM ' . IDO_DB::t('league_marches') . ' WHERE id = %d', (int) $lost_march->id))
    === IDO_League_Status::RESOLVED);
check('releasing again returns nothing', IDO_League_March::release_timed_out() === 0);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
