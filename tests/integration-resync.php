<?php
/**
 * Integration test: noticing that this database is older than it should be.
 *
 * Nothing inside a database can notice that the database went backwards, because
 * the noticing would go back with it. Only the other end remembers. So the whole
 * mechanism is: tell each peer what we think the counters are, compare what they
 * tell us, and stop applying anything if their answer says we have travelled
 * backwards in time.
 *
 * The important half of this test is the quiet half: ordinary traffic, ordinary
 * gaps and a peer on an older build must not trip it, or a game master learns to
 * click the button without reading it.
 *
 *   php tests/integration-resync.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-resync.php /path/to/wordpress [db-host]'); exit(2); }
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

global $wpdb;
$was = get_option(IDO_Settings::OPTION);

if (IDO_League::tables_exist()) {
    foreach ((array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues')) as $name) {
        if (strpos((string) $name, 'Resync Test') !== 0) {
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
$league = IDO_League_Setup::found(['league_name' => 'Resync Test ' . wp_rand(100, 999)]);

$secret    = IDO_League_Crypto::secret();
$peer_uuid = IDO_League_Crypto::uuid();
$wpdb->insert(IDO_DB::t('sites'), [
    'league_id' => (int) $league->id, 'site_uuid' => $peer_uuid, 'site_name' => 'Northmarch',
    'site_url' => 'https://member.example.com', 'secret' => $secret, 'status' => 'active',
    'secret_issued_at' => IDO_League::now(), 'created_at' => IDO_League::now(),
]);
$peer = IDO_League_Queue::peer((int) $wpdb->insert_id);

// Outbound is swallowed; the packets are inspected here instead.
$sent = [];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$sent) {
    if (strpos($url, '/ido/v1/packet') === false) return $pre;
    $sent[] = (string) ($args['body'] ?? '');
    return ['headers' => [], 'cookies' => [], 'filename' => null,
            'response' => ['code' => 202, 'message' => ''], 'body' => '{"status":"accepted"}'];
}, 10, 3);

/** A news packet from the peer, carrying its view of the counters. */
function news_from_peer(array $counters = []): string {
    global $league, $peer_uuid, $secret;
    $body = array_merge([
        'site_name' => 'Northmarch', 'round_id' => 1, 'round_day' => 5, 'empire_count' => 8,
        'networth' => 500000, 'largest_networth' => 90000, 'accepting' => true,
        'as_of' => IDO_League::now(),
    ], $counters);

    return IDO_League_Crypto::pack([
        'v' => 1, 'type' => 'news', 'league' => $league->league_uuid, 'from' => $peer_uuid,
        'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 1,
        'ts' => time(), 'fp' => $league->fingerprint, 'body' => $body,
    ], $peer_uuid, $secret);
}

say('=== what we tell a peer about the pairing ===');
IDO_League_News::broadcast();
IDO_League_Queue::flush();
$our_news = IDO_League_Crypto::open(end($sent), $secret);
check('news carries how far we have got with them',
    isset($our_news['body']['seq_seen']) && isset($our_news['body']['seq_sent']),
    (string) wp_json_encode(array_intersect_key($our_news['body'], ['seq_seen' => 1, 'seq_sent' => 1])));
$now_out = (int) IDO_League_Queue::peer((int) $peer->id)->seq_out;
check('and the counters are the pairing\'s, not the site\'s',
    (int) $our_news['body']['seq_sent'] >= $now_out - 1
    && (int) $our_news['body']['seq_sent'] <= $now_out,
    $our_news['body']['seq_sent'] . ' vs ' . $now_out);

say('');
say('=== receiving a packet moves the high-water mark ===');
$before_in = (int) IDO_League_Queue::peer((int) $peer->id)->seq_in;
clear_rate_limits();
IDO_League_Endpoint::packet(new WP_REST_Request('POST', '/x'));   // empty: refused, no effect
$wire = news_from_peer();
$request = new WP_REST_Request('POST', '/ido/v1/packet');
$request->set_body($wire);
clear_rate_limits();
IDO_League_Endpoint::packet($request);
check('the mark rises to what arrived',
    (int) IDO_League_Queue::peer((int) $peer->id)->seq_in >= 1,
    (string) IDO_League_Queue::peer((int) $peer->id)->seq_in);
check('and nothing is flagged by ordinary traffic', !IDO_League::resync_required());

say('');
say('=== the quiet cases stay quiet ===');
// A peer on an older build sends no counters at all.
IDO_League_Queue::process(true);
check('a peer that sends no counters trips nothing', !IDO_League::resync_required());

// A peer that has seen exactly what we sent.
$ours = IDO_League_Queue::peer((int) $peer->id);
IDO_League_News::apply($ours, ['site_name' => 'Northmarch', 'round_id' => 1, 'round_day' => 6,
    'empire_count' => 8, 'networth' => 500000, 'largest_networth' => 90000, 'accepting' => true,
    'as_of' => gmdate('Y-m-d H:i:s', time() + 60), 'seq_seen' => (int) $ours->seq_out,
    'seq_sent' => (int) $ours->seq_in]);
check('a peer in perfect agreement trips nothing', !IDO_League::resync_required());

// A peer slightly ahead of us on what it has sent: packets in flight, not a hole.
$ours = IDO_League_Queue::peer((int) $peer->id);
IDO_League_News::apply($ours, ['site_name' => 'Northmarch', 'round_id' => 1, 'round_day' => 7,
    'empire_count' => 8, 'networth' => 500000, 'largest_networth' => 90000, 'accepting' => true,
    'as_of' => gmdate('Y-m-d H:i:s', time() + 120), 'seq_seen' => (int) $ours->seq_out,
    'seq_sent' => (int) $ours->seq_in + 2]);
check('a couple of packets still in flight trips nothing', !IDO_League::resync_required());
check('and a fresh packet was genuinely applied, not skipped as stale',
    (int) IDO_League_Queue::peer((int) $peer->id)->empire_count === 8);

say('');
say('=== a peer that has seen more from us than we ever sent ===');
$ours = IDO_League_Queue::peer((int) $peer->id);
$was_out = (int) $ours->seq_out;
$was_epoch = (int) $ours->epoch;
$stale = gmdate('Y-m-d H:i:s', time() - 86400);   // older than what is already on file
IDO_League_News::apply($ours, ['site_name' => 'Northmarch', 'round_id' => 1, 'round_day' => 8,
    'empire_count' => 8, 'networth' => 500000, 'largest_networth' => 90000, 'accepting' => true,
    'as_of' => $stale, 'seq_seen' => $was_out + 40, 'seq_sent' => (int) $ours->seq_in]);
IDO_League::forget();

check('that raises the alarm even on a packet too old to apply',
    IDO_League::resync_required());
check('and says so in numbers a game master can check',
    strpos(IDO_League::resync_note(), (string) ($was_out + 40)) !== false, IDO_League::resync_note());
check('and names the backup as the likely cause',
    stripos(IDO_League::resync_note(), 'backup') !== false);

$after = IDO_League_Queue::peer((int) $peer->id);
check('the counter is jumped past what they have seen',
    (int) $after->seq_out > $was_out + 40, $after->seq_out . ' vs ' . ($was_out + 40));
check('and the epoch is bumped, so our marches in flight are suspect',
    (int) $after->epoch === $was_epoch + 1, $after->epoch . ' vs ' . $was_epoch);

say('');
say('=== held, not dropped ===');
// Something worth applying arrives while the flag is up.
$wire2 = news_from_peer(['round_day' => 9]);
$request2 = new WP_REST_Request('POST', '/ido/v1/packet');
$request2->set_body($wire2);
clear_rate_limits();
$code = IDO_League_Endpoint::packet($request2);
check('it is still accepted', $code instanceof WP_REST_Response && $code->get_status() === 202,
    $code instanceof WP_REST_Response ? (string) $code->get_status() : '?');

$staged = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IDO_DB::t('packets_in')
    . ' WHERE league_id = %d AND status = %s', (int) $league->id, 'staged'));
check('and staged', $staged > 0, (string) $staged);

$result = IDO_League_Queue::process(true);
check('but nothing is applied', $result['processed'] === 0);
check('and the run says what it is holding', (int) ($result['held'] ?? 0) === $staged,
    (string) ($result['held'] ?? 0));
check('the count is reported to the administrator', IDO_League::held_count() === $staged);

say('');
say('=== and nothing new is started while the record is in doubt ===');
$why = refused(static fn() => IDO_League_Muster::call(
    (object) ['id' => 1, 'turns' => 50, 'u_pawn' => 100, 'kingdom_name' => 'X'],
    (int) $peer->id, ['pawn' => 10]));
check('a muster cannot be called', stripos($why, 'records straight') !== false, $why);

say('');
say('=== clearing it: discard ===');
$note = IDO_League::clear_resync(true);
IDO_League::forget();
check('the flag is down', !IDO_League::resync_required());
check('the waiting packets were discarded', strpos($note, 'discarded') !== false, $note);
check('none are left staged', IDO_League::held_count() === 0);
check('and they are marked rather than deleted',
    (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IDO_DB::t('packets_in')
        . ' WHERE league_id = %d AND status = %s', (int) $league->id, 'rejected')) > 0);
check('with a reason a human can read',
    stripos((string) $wpdb->get_var($wpdb->prepare('SELECT result_note FROM ' . IDO_DB::t('packets_in')
        . ' WHERE league_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
        (int) $league->id, 'rejected')), 'backup was restored') !== false);

say('');
say('=== clearing it: apply instead ===');
IDO_League::flag_resync('Raised again for the test.');
IDO_League::forget();
check('raised again', IDO_League::resync_required());
$wire3 = news_from_peer(['round_day' => 11]);
$request3 = new WP_REST_Request('POST', '/ido/v1/packet');
$request3->set_body($wire3);
clear_rate_limits();
IDO_League_Endpoint::packet($request3);
$held = IDO_League::held_count();
check('a packet is waiting', $held > 0, (string) $held);

IDO_League::clear_resync(false);
IDO_League::forget();
check('the flag is down', !IDO_League::resync_required());
check('and the waiting packet is still there to be applied', IDO_League::held_count() === $held);
$result = IDO_League_Queue::process(true);
check('it applies on the next run', $result['processed'] === $held,
    $result['processed'] . ' of ' . $held);

say('');
say('=== raising it twice does not pile up epochs ===');
$epoch_before = (int) IDO_League_Queue::peer((int) $peer->id)->epoch;
IDO_League::flag_resync('First.');
IDO_League::forget();
IDO_League::flag_resync('Second, which should be ignored.');
IDO_League::forget();
check('the note is the first one', strpos(IDO_League::resync_note(), 'First') === 0,
    IDO_League::resync_note());
check('and the epoch moved once', (int) IDO_League_Queue::peer((int) $peer->id)->epoch
    === $epoch_before + 1, IDO_League_Queue::peer((int) $peer->id)->epoch . ' vs ' . $epoch_before);
IDO_League::clear_resync(true);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
