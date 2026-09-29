<?php
/**
 * Integration test: a news packet end to end, against a real WordPress and a
 * real database.
 *
 * This is the first exchange the design supports, and it exercises everything
 * the marches will later need: composing a body, signing it for one peer,
 * queueing, sending with retry and backoff, receiving at the endpoint, staging,
 * the receiver-drawn delay, and applying it on a later tick.
 *
 *   php tests/integration-queue.php /path/to/wordpress [db-host]
 *
 * One site plays both ends. The outbound HTTP is intercepted and handed straight
 * to the receiving handler, so the real code runs on both sides of a simulated
 * wire.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-queue.php /path/to/wordpress [db-host]'); exit(2); }
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
function packet_request(string $wire): WP_REST_Request {
    $r = new WP_REST_Request('POST', '/ido/v1/packet');
    $r->set_body($wire);
    return $r;
}
function code($response): int {
    return $response instanceof WP_REST_Response ? (int) $response->get_status() : 0;
}
function clear_rate_limits(): void {
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_ido_rl_%'"
        . " OR option_name LIKE '_transient_timeout_ido_rl_%'");
    wp_cache_flush();
}

if (!class_exists('IDO_League_Queue')) { say('The plugin is older than 2.2.0 or not active.'); exit(1); }

global $wpdb;
$was = get_option(IDO_Settings::OPTION);

if (IDO_League::tables_exist()) {
    $rows = (array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues'));
    foreach ($rows as $name) {
        if (strpos((string) $name, 'Queue Test') !== 0) {
            say('This site holds a league this test did not create: ' . $name);
            exit(2);
        }
    }
    IDO_League::drop_tables();
}
register_shutdown_function(static function () use ($was) {
    if (IDO_League::tables_exist()) IDO_League::drop_tables();
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
    clear_rate_limits();
});

add_filter('home_url', static function () { return 'https://hub.example.com'; }, 99);
IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };
clear_rate_limits();

IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();
$league = IDO_League_Setup::found(['league_name' => 'Queue Test ' . wp_rand(100, 999)]);

// A paired peer, written directly: the handshake has its own test and this one is
// about what happens once two sites already share a secret.
$secret    = IDO_League_Crypto::secret();
$peer_uuid = IDO_League_Crypto::uuid();
$wpdb->insert(IDO_DB::t('sites'), [
    'league_id' => (int) $league->id, 'site_uuid' => $peer_uuid, 'site_name' => 'Northmarch',
    'site_url' => 'https://member.example.com', 'secret' => $secret, 'status' => 'active',
    'secret_issued_at' => IDO_League::now(), 'created_at' => IDO_League::now(),
]);
$peer = IDO_League_Queue::peer((int) $wpdb->insert_id);

say('=== what this site says about itself ===');
$body = IDO_League_News::compose();
check('a news body is composed', is_array($body) && isset($body['as_of']));
check('it passes its own schema', (static function () use ($body, $league, $peer_uuid) {
    $envelope = ['v' => 1, 'type' => 'news', 'league' => $league->league_uuid, 'from' => $peer_uuid,
                 'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 1,
                 'ts' => time(), 'fp' => $league->fingerprint, 'body' => $body];
    try { IDO_League_Packet::read($envelope, $peer_uuid, (string) $league->site_uuid); return true; }
    catch (IDO_Game_Exception $e) { say('   schema said: ' . $e->getMessage()); return false; }
})());
check('it carries no player names', !isset($body['rulers']) && !isset($body['empires']));
foreach (['muster', 'march', 'force', 'target', 'committed'] as $forbidden) {
    check("and nothing about a $forbidden", !array_key_exists($forbidden, $body));
}

say('');
say('=== queueing and sending ===');
// The peer accepts what it is sent, and that is all this filter does.
//
// The first version of this test fed the outbound bytes straight back into this
// site's own endpoint, on the theory that one site could play both ends. It
// cannot, and the reason is the design working: the envelope binds both the
// sender and the recipient inside the signed bytes, so a packet addressed to the
// peer is refused here no matter who signed it. Each direction is therefore
// tested with a packet actually addressed to the side receiving it.
$posted = [];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$posted) {
    if (strpos($url, '/ido/v1/packet') === false) return $pre;
    $posted[] = (string) ($args['body'] ?? '');
    return ['headers' => [], 'cookies' => [], 'filename' => null,
            'response' => ['code' => 202, 'message' => 'Accepted'],
            'body' => '{"status":"accepted"}'];
}, 10, 3);

check('news is queued for the peer', IDO_League_News::broadcast()['queued'] === 1);
$queued = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('packets_out')
    . ' WHERE league_id = %d ORDER BY id DESC LIMIT 1', (int) $league->id));
check('the row is queued, not sent', $queued && (string) $queued->status === 'queued');
check('it carries a signed wire', $queued && strpos((string) $queued->payload, 'ido1.') === 0);
check('the sequence number was taken', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT seq_out FROM ' . IDO_DB::t('sites') . ' WHERE id = %d', (int) $peer->id)) === 1);
check('news is queued with no delay', $queued
    && strtotime((string) $queued->send_after . ' UTC') <= time() + 5);

$flushed = IDO_League_Queue::flush();
check('flushing sends it', $flushed['sent'] === 1, (string) wp_json_encode($flushed));
check('exactly one request went out', count($posted) === 1);
check('the row is marked sent', (string) $wpdb->get_var($wpdb->prepare(
    'SELECT status FROM ' . IDO_DB::t('packets_out') . ' WHERE id = %d', (int) $queued->id)) === 'sent');
check('flushing again sends nothing', IDO_League_Queue::flush()['sent'] === 0);

say('');
say('=== receiving one, from the peer to us ===');
$inbound_body = array_merge($body, ['site_name' => 'Northmarch', 'empire_count' => 11,
                                    'networth' => 987654, 'largest_networth' => 321000]);
$inbound = IDO_League_Crypto::pack([
    'v' => 1, 'type' => 'news', 'league' => $league->league_uuid, 'from' => $peer_uuid,
    'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 1,
    'ts' => time(), 'fp' => $league->fingerprint, 'body' => $inbound_body,
], $peer_uuid, $secret);

clear_rate_limits();
$received = IDO_League_Endpoint::packet(packet_request($inbound));
check('the endpoint accepts it with 202', code($received) === 202, 'status ' . code($received));

$staged = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('packets_in')
    . ' WHERE league_id = %d ORDER BY id DESC LIMIT 1', (int) $league->id));
check('it was staged, not applied', $staged && (string) $staged->status === 'staged');
check('recorded as a news packet', $staged && (string) $staged->packet_type === 'news');
check('with the sender\'s sequence number', $staged && (int) $staged->sequence === 1);
check('and a process_after the receiver set', $staged && $staged->process_after !== null);

say('');
say('=== the delay is a coded value, drawn per packet ===');
$seen = [];
for ($i = 0; $i < 400; $i++) $seen[IDO_League::delay_days()] = true;
ksort($seen);
check('every value from 3 to 6 comes up', array_keys($seen) === [3, 4, 5, 6],
    implode(',', array_keys($seen)));
check('and nothing outside that range ever does',
    min(array_keys($seen)) === IDO_League::DELAY_MIN_DAYS && max(array_keys($seen)) === IDO_League::DELAY_MAX_DAYS);
check('the floor is three days, for three daily ticks', IDO_League::DELAY_MIN_DAYS === 3);
check('the ceiling is six, leaving the seventh day for the ride home',
    IDO_League::DELAY_MAX_DAYS === 6);
check('so no exchange runs longer than a week of travel',
    IDO_League::DELAY_MAX_DAYS + IDO_League::RESULT_DELAY_DAYS === 7);
check('the result rides home in a day', IDO_League::RESULT_DELAY_DAYS === 1);
check('the longest exchange is the muster, the march out and the ride home',
    IDO_League::longest_exchange_days(5) === 5 + 6 + 1, (string) IDO_League::longest_exchange_days(5));

// A war packet is staged with a real wait; news is not delayed at all.
clear_rate_limits();
$war_wire = IDO_League_Crypto::pack([
    'v' => 1, 'type' => 'war', 'league' => $league->league_uuid, 'from' => $peer_uuid,
    'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 9,
    'ts' => time(), 'fp' => $league->fingerprint, 'body' => ['force' => []],
], $peer_uuid, $secret);
check('a war packet is still refused, since its body has no specification yet',
    code(IDO_League_Endpoint::packet(packet_request($war_wire))) === 422);
check('news was staged with no wait',
    strtotime((string) $staged->process_after . ' UTC') <= strtotime((string) $staged->received_at . ' UTC') + 5);

say('');
say('=== a replay is accepted and applied once ===');
clear_rate_limits();
$again = IDO_League_Endpoint::packet(packet_request($inbound));
check('a duplicate answers 202, not an error', code($again) === 202, 'status ' . code($again));
check('and did not stage a second row', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('packets_in') . ' WHERE league_id = %d', (int) $league->id)) === 1);

say('');
say('=== what the endpoint refuses ===');
foreach ([
    'an empty body'                 => '',
    'rubbish'                       => 'not a packet at all',
    'a wire with a bad signature'   => substr($inbound, 0, -8) . 'deadbeef',
    'a wire from an unknown peer'   => 'ido1.' . IDO_League_Crypto::uuid() . '.'
                                        . IDO_League_Crypto::b64_encode('{}') . '.' . str_repeat('a', 64),
    'a wire over the size cap'      => str_repeat('A', IDO_League_Crypto::MAX_BYTES + 10),
] as $label => $wire) {
    clear_rate_limits();
    $c = code(IDO_League_Endpoint::packet(packet_request($wire)));
    check('refuses ' . $label, $c >= 400, 'status ' . $c);
}
check('and none of them staged anything', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('packets_in') . ' WHERE league_id = %d', (int) $league->id)) === 1);

// A packet correctly signed but addressed elsewhere, which a hostile peer can do.
clear_rate_limits();
$misaddressed = IDO_League_Crypto::pack([
    'v' => 1, 'type' => 'news', 'league' => $league->league_uuid, 'from' => $peer_uuid,
    'to' => IDO_League_Crypto::uuid(), 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 2,
    'ts' => time(), 'fp' => $league->fingerprint, 'body' => $body,
], $peer_uuid, $secret);
check('refuses a signed packet addressed to another site',
    code(IDO_League_Endpoint::packet(packet_request($misaddressed))) === 422);

clear_rate_limits();
$wrong_league = IDO_League_Crypto::pack([
    'v' => 1, 'type' => 'news', 'league' => IDO_League_Crypto::uuid(), 'from' => $peer_uuid,
    'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 3,
    'ts' => time(), 'fp' => $league->fingerprint, 'body' => $body,
], $peer_uuid, $secret);
check('and one claiming another league', code(IDO_League_Endpoint::packet(packet_request($wrong_league))) === 422);

say('');
say('=== processing applies it ===');
$before = IDO_League_Queue::peer((int) $peer->id);
check('the peer has no figures yet', (int) $before->empire_count === 0 && $before->news_as_of === null);

$result = IDO_League_Queue::process();
check('one packet is processed', $result['processed'] === 1, (string) wp_json_encode($result));
$after = IDO_League_Queue::peer((int) $peer->id);
// The figures that should land are the ones the *peer* sent, not the ones this
// site composed about itself.
check('the peer\'s figures are recorded', (int) $after->empire_count === 11, (string) $after->empire_count);
check('its net worth too', (int) $after->networth === 987654, (string) $after->networth);
check('with the date it claimed', $after->news_as_of !== null);
check('the row is marked processed', (string) $wpdb->get_var($wpdb->prepare(
    'SELECT status FROM ' . IDO_DB::t('packets_in') . ' WHERE id = %d', (int) $staged->id)) === 'processed');
check('processing again does nothing', IDO_League_Queue::process()['processed'] === 0);

say('');
say('=== stale news does not move a peer backwards ===');
$stale = $body;
$stale['empire_count'] = 999;
$stale['as_of'] = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
clear_rate_limits();
$stale_wire = IDO_League_Crypto::pack([
    'v' => 1, 'type' => 'news', 'league' => $league->league_uuid, 'from' => $peer_uuid,
    'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 4,
    'ts' => time(), 'fp' => $league->fingerprint, 'body' => $stale,
], $peer_uuid, $secret);
check('an older packet is accepted', code(IDO_League_Endpoint::packet(packet_request($stale_wire))) === 202);
IDO_League_Queue::process();
check('but its figures are not applied',
    (int) IDO_League_Queue::peer((int) $peer->id)->empire_count !== 999);

say('');
say('=== a peer that cannot be reached ===');
remove_all_filters('pre_http_request');
add_filter('pre_http_request', static function () {
    return new WP_Error('http_request_failed', 'simulated: peer down');
}, 10, 3);

IDO_League_News::broadcast();
$down = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('packets_out')
    . " WHERE league_id = %d AND status = 'queued' ORDER BY id DESC LIMIT 1", (int) $league->id));
check('a fresh packet is queued', (bool) $down);
$r = IDO_League_Queue::flush();
check('sending fails rather than throwing', $r['failed'] === 1, (string) wp_json_encode($r));
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('packets_out') . ' WHERE id = %d', (int) $down->id));
check('the packet is kept for a retry', (string) $row->status === 'queued');
check('the attempt was counted', (int) $row->attempts === 1);
check('and the reason recorded', (string) $row->last_error !== '');
check('backoff holds it back from an immediate retry', IDO_League_Queue::flush()['failed'] === 0);

// Eight failures and it is abandoned rather than retried forever.
for ($i = 0; $i < IDO_League_Queue::MAX_ATTEMPTS + 1; $i++) {
    $wpdb->update(IDO_DB::t('packets_out'), ['last_attempt_at' => null], ['id' => (int) $down->id]);
    IDO_League_Queue::flush();
}
$final = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT status FROM ' . IDO_DB::t('packets_out') . ' WHERE id = %d', (int) $down->id));
check('it is eventually abandoned, not retried forever', $final === 'failed', $final);

say('');
say('=== a defender learns nothing until the battle is fought ===');
// Stage a war-shaped row directly. The endpoint refuses a war packet today
// because its body has no specification yet, and what is under test here is the
// disclosure rule rather than the packet format.
$wpdb->insert(IDO_DB::t('packets_in'), [
    'league_id' => (int) $league->id, 'peer_id' => (int) $peer->id,
    'uuid' => IDO_League_Crypto::uuid(), 'packet_type' => 'war', 'sequence' => 99,
    'received_at' => IDO_League::now(),
    'process_after' => gmdate('Y-m-d H:i:s', time() + 4 * DAY_IN_SECONDS),
    'status' => 'staged', 'payload' => 'placeholder',
]);
$war_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('packets_in')
    . ' WHERE id = %d', (int) $wpdb->insert_id));

$summary = IDO_League_Queue::summary();
check('an inbound march is not counted anywhere the admin can see it',
    (int) ($summary['in']['staged'] ?? 0) === 0, (string) wp_json_encode($summary['in']));
check('the defender is told nothing about it', IDO_League_Status::for_defender($war_row) === null);
check('a war packet is one that must stay secret', IDO_League_Status::is_secret_until_resolved('war'));
check('and so is a result', IDO_League_Status::is_secret_until_resolved('result'));
check('news is not', !IDO_League_Status::is_secret_until_resolved('news'));

// The admin log is a screen too. A refused march must not name itself there.
$before_log = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . IDO_DB::t('admin_log'));
clear_rate_limits();
IDO_League_Endpoint::packet(packet_request(IDO_League_Crypto::pack([
    'v' => 1, 'type' => 'war', 'league' => $league->league_uuid, 'from' => $peer_uuid,
    'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 77,
    'ts' => time(), 'fp' => $league->fingerprint, 'body' => ['force' => []],
], $peer_uuid, $secret)));
$logged = (string) $wpdb->get_var('SELECT message FROM ' . IDO_DB::t('admin_log') . ' ORDER BY id DESC LIMIT 1');
check('a refused march is logged without naming itself',
    (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . IDO_DB::t('admin_log')) > $before_log
    && stripos($logged, 'war') === false, $logged);

$war_row->status = 'processed';
check('only once it is fought does the defender learn the outcome',
    IDO_League_Status::for_defender($war_row) === IDO_League_Status::RESOLVED);

check('the attacker sees three states after the muster', [
    IDO_League_Status::label(IDO_League_Status::MARCHING),
    IDO_League_Status::label(IDO_League_Status::IN_BATTLE),
    IDO_League_Status::label(IDO_League_Status::RESOLVED, true),
    IDO_League_Status::label(IDO_League_Status::RESOLVED, false),
] === ['Marching to the battlefield', 'In battle', 'Victory', 'Defeat']);

$march = static function (array $f) { return (object) array_merge(
    ['sent_at' => null, 'joined_at' => null, 'resolved_at' => null], $f); };
check('a march starts out mustering',
    IDO_League_Status::derive($march([])) === IDO_League_Status::MUSTERING);
check('marching once the packet has gone',
    IDO_League_Status::derive($march(['sent_at' => '2026-10-01 00:00:00'])) === IDO_League_Status::MARCHING);
check('in battle the day the sealed result arrives',
    IDO_League_Status::derive($march(['sent_at' => '2026-10-01 00:00:00',
        'joined_at' => '2026-10-05 00:00:00'])) === IDO_League_Status::IN_BATTLE);
check('and resolved the morning after, when it is opened',
    IDO_League_Status::derive($march(['sent_at' => '2026-10-01 00:00:00',
        'joined_at' => '2026-10-05 00:00:00', 'resolved_at' => '2026-10-06 00:00:00']))
    === IDO_League_Status::RESOLVED);
check('the four states are the whole vocabulary', IDO_League_Status::all() === [
    'mustering', 'marching', 'in_battle', 'resolved']);
check('the march tables exist to hold them',
    (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', IDO_DB::t('league_marches')))
    === IDO_DB::t('league_marches'));
check('and the contributions beside them',
    (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', IDO_DB::t('league_contributions')))
    === IDO_DB::t('league_contributions'));

$wpdb->delete(IDO_DB::t('packets_in'), ['id' => (int) $war_row->id], ['%d']);

say('');
say('=== the kill switch stops both directions ===');
IDO_League_Setup::set_paused(true);
IDO_League::forget();
check('nothing is sent while paused', IDO_League_Queue::flush()['sent'] === 0);
check('nothing is processed while paused', IDO_League_Queue::process()['processed'] === 0);
clear_rate_limits();
check('and the endpoint turns packets away', code(IDO_League_Endpoint::packet(packet_request($inbound))) === 404);
check('news is not even composed', IDO_League_News::broadcast()['queued'] === 0);
IDO_League_Setup::set_paused(false);
IDO_League::forget();

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
