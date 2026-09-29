<?php
/**
 * Integration test: the enrolment handshake, both sides, against a real
 * WordPress and a real database.
 *
 * A handshake needs two sites, and this has one. So the outbound half is
 * intercepted with WordPress's own `pre_http_request` filter and answered in
 * process: the real handlers run, against the real tables, with real validation,
 * and only the wire between them is simulated. That is the right seam. Faking
 * the handlers would test nothing; faking the network tests everything except
 * the network.
 *
 *   php tests/integration-handshake.php /path/to/wordpress [db-host]
 *
 * It refuses to run on a site that is already in a league, and puts the settings
 * row back as it found it.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-handshake.php /path/to/wordpress [db-host]'); exit(2); }
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
/**
 * Clears the endpoint's rate-limit counters.
 *
 * They are real, they are per address per hour, and this test makes far more
 * calls than any genuine peer would. The first run of this test passed and every
 * run after it failed with 429s, which was the limiter working correctly and the
 * test not knowing about it. Cleared deliberately and then tested on purpose at
 * the end, rather than raising the limit to make the test comfortable.
 */
function clear_rate_limits(): void {
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_ido_rl_%'"
        . " OR option_name LIKE '_transient_timeout_ido_rl_%'");
    wp_cache_flush();
}

function request(array $body): WP_REST_Request {
    $r = new WP_REST_Request('POST', '/ido/v1/x');
    $r->set_body((string) wp_json_encode($body));
    return $r;
}
function status($response): int {
    return $response instanceof WP_REST_Response ? (int) $response->get_status() : 0;
}
function data($response): array {
    return $response instanceof WP_REST_Response ? (array) $response->get_data() : [];
}

if (!class_exists('IDO_League_Endpoint')) { say('The plugin is older than 2.1.0 or not active.'); exit(1); }

global $wpdb;
$was = get_option(IDO_Settings::OPTION);

// Names this test gives the leagues it creates. Anything else in these tables
// belongs to somebody, and this test does not touch it.
$test_leagues = ['Handshake Test', 'Far League'];

// The tables can hold a league even while league play is switched off, so
// checking the setting is not enough: a previous run that failed part way
// through would otherwise be invisible here and break this one. Look at the
// rows.
if (IDO_League::tables_exist()) {
    $rows = (array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues'));
    foreach ($rows as $name) {
        $mine = false;
        foreach ($test_leagues as $prefix) { if (strpos((string) $name, $prefix) === 0) $mine = true; }
        if (!$mine) {
            say('This site holds a league this test did not create: ' . $name);
            say('Refusing to run rather than disturbing it.');
            exit(2);
        }
    }
    if ($rows) say('Clearing ' . count($rows) . ' league(s) left by an earlier run.');
    IDO_League::drop_tables();
}

// Cleanup belongs in the shutdown handler, not at the end of the script. A
// failure half way through used to leave a league row behind and poison the next
// run, which cost a confusing "already in a league" fatal to learn.
register_shutdown_function(static function () use ($was) {
    if (IDO_League::tables_exist()) IDO_League::drop_tables();
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
});

// This site stands in for the hub. Its own address has to look like production.
$hub_url    = 'https://hub.example.com';
$member_url = 'https://member.example.com';
add_filter('home_url', static function () use ($hub_url) { return $hub_url; }, 99);
IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };

IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();

$league = IDO_League_Setup::found(['league_name' => 'Handshake Test ' . wp_rand(100, 999)]);
$token  = null;
$blob   = IDO_League_Setup::invite('Test member');
$token  = json_decode((string) IDO_League_Crypto::b64_decode($blob), true)['token'];
$member_uuid = IDO_League_Crypto::uuid();

say('=== the endpoint is only there when it should be ===');
check('the endpoint is open', IDO_League::endpoint_enabled());
IDO_Settings::update(['league_endpoint' => 0]);
IDO_League::forget();
check('closing it in settings closes it', !IDO_League::endpoint_enabled());
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();

// The simulated joining site. It answers /hello the way a real member would:
// an HMAC of the hub's nonce, keyed by the invitation token it holds.
$peer = ['answer' => true, 'token' => $token, 'calls' => 0];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$peer) {
    if (strpos($url, '/ido/v1/hello') === false) return $pre;
    $peer['calls']++;
    if (!$peer['answer']) return new WP_Error('http_request_failed', 'simulated: site unreachable');
    $sent  = json_decode((string) ($args['body'] ?? ''), true);
    $nonce = is_array($sent) ? (string) ($sent['nonce'] ?? '') : '';
    return [
        'headers'  => [], 'cookies' => [], 'filename' => null,
        'response' => ['code' => 200, 'message' => 'OK'],
        'body'     => (string) wp_json_encode([
            'proof' => hash_hmac('sha256', $nonce, (string) $peer['token']),
        ]),
    ];
}, 10, 3);

say('');
say('=== what /join refuses ===');
clear_rate_limits();
$good = ['token' => $token, 'site' => $member_uuid, 'url' => $member_url, 'name' => 'Northmarch'];

$cases = [
    'an empty body'                  => [],
    'a token that was never issued'  => ['token' => str_repeat('b', 64)] + $good,
    'a token of the wrong shape'     => ['token' => 'not-a-token'] + $good,
    'a malformed site identifier'    => ['site' => 'nope'] + $good,
    'a plain http address'           => ['url' => 'http://member.example.com'] + $good,
    'a loopback address'             => ['url' => 'https://127.0.0.1'] + $good,
    'the hub\'s own address'         => ['url' => $hub_url] + $good,
    'a name that is not a name'      => ['name' => '<script>x</script>'] + $good,
];
foreach ($cases as $label => $body) {
    $code = status(IDO_League_Endpoint::join(request($body)));
    check('refuses ' . $label, $code >= 400 && $code < 500, 'status ' . $code);
}
check('and nothing was stored by any of them',
    (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IDO_DB::t('sites')
        . ' WHERE league_id = %d AND is_hub = 0', (int) $league->id)) === 0);

$long = $good; $long['name'] = str_repeat('a', 20000);
check('refuses a body over the size cap', status(IDO_League_Endpoint::join(request($long))) === 400);

say('');
say('=== the callback has to succeed ===');
clear_rate_limits();
$peer['answer'] = false;
check('a site that cannot be reached is refused', status(IDO_League_Endpoint::join(request($good))) === 403);
$peer['answer'] = true;

$peer['token'] = str_repeat('c', 64);   // a site that holds the wrong invitation
check('a site that cannot prove it holds the invitation is refused',
    status(IDO_League_Endpoint::join(request($good))) === 403);
$peer['token'] = $token;

check('still nothing stored',
    (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . IDO_DB::t('sites')
        . ' WHERE league_id = %d AND is_hub = 0', (int) $league->id)) === 0);

say('');
say('=== a genuine enrolment ===');
clear_rate_limits();
$before_calls = $peer['calls'];
$response = IDO_League_Endpoint::join(request($good));
check('is accepted', status($response) === 200, 'status ' . status($response));
check('and reports itself as pending', (data($response)['status'] ?? '') === 'pending');
check('the hub called the joining site back exactly once', $peer['calls'] === $before_calls + 1);

$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('sites')
    . ' WHERE league_id = %d AND site_uuid = %s', (int) $league->id, $member_uuid));
check('a member row now exists', (bool) $row);
check('as pending, not active', $row && (string) $row->status === 'pending');
check('with no secret yet', $row && (string) $row->secret === '');
check('the invitation is not spent yet', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('invites') . ' WHERE league_id = %d AND used_at IS NULL',
    (int) $league->id)) === 1);

say('');
say('=== no secret before a human approves ===');
clear_rate_limits();
$ask = ['token' => $token, 'site' => $member_uuid];
$r = IDO_League_Endpoint::enrol_status(request($ask));
check('enrol-status says pending', (data($r)['status'] ?? '') === 'pending');
check('and sends no secret', !isset(data($r)['secret']));

check('a stranger asking with a bad token is refused',
    status(IDO_League_Endpoint::enrol_status(request(['token' => str_repeat('d', 64), 'site' => $member_uuid]))) === 403);
check('and with an unknown site identifier',
    status(IDO_League_Endpoint::enrol_status(request(['token' => $token, 'site' => IDO_League_Crypto::uuid()]))) === 403);

say('');
say('=== after approval, once and only once ===');
clear_rate_limits();
IDO_League_Enrol::approve((int) $row->id);
$r = IDO_League_Endpoint::enrol_status(request($ask));
$issued = data($r);
check('the secret is issued', ($issued['status'] ?? '') === 'approved');
check('it is 32 bytes of hex', isset($issued['secret']) && preg_match('/^[0-9a-f]{64}$/', $issued['secret']) === 1);
check('the hub identifies itself', isset($issued['hub']) && $issued['hub'] === $league->site_uuid);
check('the ruleset travels with it', isset($issued['ruleset']) && is_array($issued['ruleset'])
    && isset($issued['ruleset']['turns_per_day']));
check('so does the calendar', isset($issued['calendar']['round_days']));

$stored = $wpdb->get_var($wpdb->prepare('SELECT secret FROM ' . IDO_DB::t('sites') . ' WHERE id = %d', (int) $row->id));
check('the hub stored the same secret', $stored === ($issued['secret'] ?? 'x'));

$again = data(IDO_League_Endpoint::enrol_status(request($ask)));
check('asking again says spent', ($again['status'] ?? '') === 'spent');
check('and sends no second secret', !isset($again['secret']));
check('the secret on file did not change',
    $wpdb->get_var($wpdb->prepare('SELECT secret FROM ' . IDO_DB::t('sites') . ' WHERE id = %d', (int) $row->id)) === $stored);

say('');
say('=== the invitation is now worthless ===');
clear_rate_limits();
check('it cannot enrol a second site', status(IDO_League_Endpoint::join(request(
    ['token' => $token, 'site' => IDO_League_Crypto::uuid(), 'url' => 'https://other.example.com', 'name' => 'Other']
))) === 403);

say('');
say('=== a signed packet between the two now verifies ===');
// The point of the whole handshake: both sides hold the same secret, so a packet
// signed by one verifies at the other and nowhere else.
$packet = ['v' => 1, 'type' => 'news', 'league' => $league->league_uuid, 'from' => $member_uuid,
           'to' => $league->site_uuid, 'uuid' => IDO_League_Crypto::uuid(), 'seq' => 1, 'ts' => time(),
           'fp' => $league->fingerprint,
           'body' => ['site_name' => 'Northmarch', 'round_id' => 1, 'round_day' => 3, 'empire_count' => 6,
                      'networth' => 120000, 'largest_networth' => 40000, 'accepting' => true,
                      'as_of' => gmdate('Y-m-d H:i:s')]];
$wire = IDO_League_Crypto::pack($packet, $member_uuid, (string) $stored);
check('the hub can open a packet signed with the issued secret',
    is_array(IDO_League_Crypto::open($wire, (string) $stored)));
check('and not with any other secret',
    IDO_League_Crypto::open($wire, IDO_League_Crypto::secret()) === null);
$opened = IDO_League_Crypto::open($wire, (string) $stored);
$valid = false;
try { IDO_League_Packet::read((array) $opened, $member_uuid, (string) $league->site_uuid); $valid = true; }
catch (IDO_Game_Exception $e) { $valid = false; say('   schema said: ' . $e->getMessage()); }
check('and the schema accepts it', $valid);

say('');
say('=== declining destroys the secret ===');
clear_rate_limits();
$second_uuid = IDO_League_Crypto::uuid();
$blob2 = IDO_League_Setup::invite('Second member');
$token2 = json_decode((string) IDO_League_Crypto::b64_decode($blob2), true)['token'];
$peer['token'] = $token2;
IDO_League_Endpoint::join(request(['token' => $token2, 'site' => $second_uuid,
    'url' => 'https://second.example.com', 'name' => 'Southwatch']));
$second = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('sites')
    . ' WHERE league_id = %d AND site_uuid = %s', (int) $league->id, $second_uuid));
check('the second site enrolled', (bool) $second);
IDO_League_Enrol::approve((int) $second->id);
IDO_League_Endpoint::enrol_status(request(['token' => $token2, 'site' => $second_uuid]));
IDO_League_Enrol::decline((int) $second->id);
$after = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('sites') . ' WHERE id = %d', (int) $second->id));
check('declining marks it declined', (string) $after->status === 'declined');
check('and destroys its secret', (string) $after->secret === '');

say('');
say('=== the rate limit is real ===');
clear_rate_limits();
$refused_at = 0;
for ($i = 1; $i <= IDO_League_Endpoint::RATE_LIMIT + 5; $i++) {
    $code = status(IDO_League_Endpoint::enrol_status(request(['token' => str_repeat('e', 64), 'site' => IDO_League_Crypto::uuid()])));
    if ($code === 429) { $refused_at = $i; break; }
}
check('grinding at an enrolment route is cut off', $refused_at > 0, 'at call ' . $refused_at);
check('and not before the budget is spent', $refused_at > IDO_League_Endpoint::RATE_LIMIT, 'at call ' . $refused_at);
clear_rate_limits();
check('clearing the counters lets a legitimate peer through again',
    status(IDO_League_Endpoint::enrol_status(request(['token' => str_repeat('e', 64), 'site' => IDO_League_Crypto::uuid()]))) === 403);

say('');
say('=== now the other side: this site as the one joining ===');
clear_rate_limits();
// A fresh install pointed at a simulated hub. The hub's answers are intercepted,
// so what is under test is how this site treats them, which is the half the
// checks above could not reach.
IDO_League::drop_tables();
IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();

$hub_token = bin2hex(random_bytes(32));
$invitation = IDO_League_Crypto::b64_encode((string) wp_json_encode([
    'v' => 1, 'lid' => IDO_League_Crypto::uuid(), 'name' => 'Far League',
    'hub' => 'https://far-hub.example.com', 'token' => $hub_token,
]));
IDO_League_Setup::join($invitation);
IDO_League::forget();
$joining = IDO_League::league();
check('the enrolment is visible to the screen', $joining !== null,
    $joining === null ? 'league() could not see its own pending row' : '');
check('and reads as pending', IDO_League::pending());
check('the token is held so the callback can be answered', (string) $joining->enrol_token === $hub_token);

// The hub's side of the wire.
$hub = ['join' => 200, 'status' => 'pending', 'payload' => []];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$hub) {
    $reply = static function (int $code, array $body): array {
        return ['headers' => [], 'cookies' => [], 'filename' => null,
                'response' => ['code' => $code, 'message' => ''],
                'body' => (string) wp_json_encode($body)];
    };
    if (strpos($url, '/ido/v1/join') !== false) {
        return $hub['join'] === 200 ? $reply(200, ['status' => 'pending']) : $reply($hub['join'], ['status' => 'refused']);
    }
    if (strpos($url, '/ido/v1/enrol-status') !== false) {
        return $reply(200, $hub['payload'] ?: ['status' => $hub['status']]);
    }
    return $pre;
}, 5, 3);

say('');
say('-- answering the hub requires this site to be reachable --');
IDO_Settings::update(['league_endpoint' => 0]);
IDO_League::forget();
$why = '';
try { IDO_League_Enrol::present(); } catch (IDO_Game_Exception $e) { $why = $e->getMessage(); }
check('presenting is refused while the endpoint is closed',
    strpos($why, 'call this site back') !== false, $why);
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();

say('');
say('-- presenting the invitation --');
check('a successful presentation is reported', strpos(IDO_League_Enrol::present(), 'called this site back') !== false);
foreach ([403 => 'callback', 409 => 'full', 429 => 'repeated', 404 => 'not accepting'] as $code => $expect) {
    $hub['join'] = $code;
    $why = '';
    try { IDO_League_Enrol::present(); } catch (IDO_Game_Exception $e) { $why = $e->getMessage(); }
    check("a $code from the hub is explained", stripos($why, $expect) !== false, $why);
}
$hub['join'] = 200;

say('');
say('-- collecting the secret --');
$hub['status'] = 'pending';
check('a pending answer is reported and nothing stored',
    strpos(IDO_League_Enrol::collect(), 'not approved this site yet') !== false);
check('the league is still pending', IDO_League::pending());

$hub['status'] = 'declined';
$why = '';
try { IDO_League_Enrol::collect(); } catch (IDO_Game_Exception $e) { $why = $e->getMessage(); }
check('a declined answer throws', strpos($why, 'declined') !== false, $why);

// A hub that answers approved but sends rubbish is refused rather than obeyed.
$hub_uuid = IDO_League_Crypto::uuid();
$good_reply = ['status' => 'approved', 'secret' => bin2hex(random_bytes(32)), 'hub' => $hub_uuid,
               'league_name' => 'Far League', 'ruleset' => ['turns_per_day' => 10],
               'fingerprint' => str_repeat('a', 64),
               'calendar' => ['round_days' => 90, 'muster_days' => 5, 'delay_min_days' => 3, 'delay_max_days' => 8]];
foreach ([
    'a secret that is not hex'     => ['secret' => 'nonsense'],
    'a secret of the wrong length' => ['secret' => 'abcd'],
    'a missing secret'             => ['secret' => null],
    'a hub with no identity'       => ['hub' => 'not-a-uuid'],
] as $label => $override) {
    $hub['payload'] = array_filter(array_merge($good_reply, $override), static fn($v) => $v !== null);
    $why = '';
    try { IDO_League_Enrol::collect(); } catch (IDO_Game_Exception $e) { $why = $e->getMessage(); }
    check('refuses ' . $label, $why !== '', $why);
}
check('and is still not a member', IDO_League::pending());

// A hub pushing absurd numbers is range-checked, not obeyed.
$hub['payload'] = array_merge($good_reply, [
    'calendar' => ['round_days' => 999999, 'muster_days' => -5, 'delay_min_days' => 3, 'delay_max_days' => 8],
]);
check('an approved answer completes the enrolment',
    strpos(IDO_League_Enrol::collect(), 'has joined the league') !== false);
IDO_League::forget();
$done = IDO_League::league();
check('the league is now active', $done !== null && (string) $done->status === 'active');
check('and no longer paused', IDO_League::active());
check('the enrolment token has been cleared', (string) $done->enrol_token === '');
check('an absurd round length was refused and defaulted', (int) $done->round_days === 90,
    (string) $done->round_days);
check('as was a negative muster window', (int) $done->muster_days === 5, (string) $done->muster_days);

$hub_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IDO_DB::t('sites')
    . ' WHERE league_id = %d AND is_hub = 1', (int) $done->id));
check('the hub is recorded as an active peer', $hub_row && (string) $hub_row->status === 'active');
check('with the secret stored', $hub_row && $hub_row->secret === $good_reply['secret']);
check('and its identity', $hub_row && (string) $hub_row->site_uuid === $hub_uuid);
check('collecting again is harmless', strpos(IDO_League_Enrol::collect(), 'already joined') !== false);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
