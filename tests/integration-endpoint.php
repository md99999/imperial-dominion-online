<?php
/**
 * Integration test: the three enrolment routes, attacked on purpose.
 *
 * The handshake test proves the routes work. This one tries to break them, the
 * way the packet schema was tested: type confusion, injection payloads in every
 * field, oversized and malformed bodies, identifiers of the wrong shape, an
 * enrolment aimed at somebody else's site, and a probe for whether the handlers
 * say more about a failure than they should.
 *
 *   php tests/integration-endpoint.php /path/to/wordpress [db-host]
 *
 * These routes answer before any trust exists, so the property under test is not
 * "the right thing happens" but "nothing happens, and nothing is said".
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-endpoint.php /path/to/wordpress [db-host]'); exit(2); }
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }
$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-64s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}
function request($body): WP_REST_Request {
    $r = new WP_REST_Request('POST', '/ido/v1/x');
    $r->set_body(is_string($body) ? $body : (string) wp_json_encode($body));
    return $r;
}
function code($response): int {
    return $response instanceof WP_REST_Response ? (int) $response->get_status() : 0;
}
function body($response): array {
    return $response instanceof WP_REST_Response ? (array) $response->get_data() : [];
}
function clear_rate_limits(): void {
    global $wpdb;
    $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_ido_rl_%'"
        . " OR option_name LIKE '_transient_timeout_ido_rl_%'");
    wp_cache_flush();
}

if (!class_exists('IDO_League_Endpoint')) { say('The plugin is older than 2.1.0 or not active.'); exit(1); }

global $wpdb;
$was = get_option(IDO_Settings::OPTION);

if (IDO_League::tables_exist()) {
    $rows = (array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues'));
    foreach ($rows as $name) {
        if (strpos((string) $name, 'Endpoint Test') !== 0) {
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

IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();
$league = IDO_League_Setup::found(['league_name' => 'Endpoint Test ' . wp_rand(100, 999)]);
$token  = json_decode((string) IDO_League_Crypto::b64_decode(IDO_League_Setup::invite('t')), true)['token'];

// No peer answers in this test. Any outbound call is itself a finding: it would
// mean an unvalidated address got as far as being dialled.
$dialled = [];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$dialled) {
    $dialled[] = $url;
    return new WP_Error('http_request_failed', 'no peer in this test');
}, 10, 3);

// Payloads aimed at each layer in turn.
$payloads = [
    "'; DROP TABLE {$wpdb->prefix}ido_sites; --",
    "' OR 1=1 --",
    '; rm -rf /',
    '$(whoami)',
    '`id`',
    '<script>alert(1)</script>',
    '../../../../etc/passwd',
    "null\x00byte",
    'O:8:"stdClass":0:{}',
    str_repeat('A', 300),
];

say('=== bodies that are not bodies ===');
foreach ([
    'an empty body'                  => '',
    'not JSON at all'                => 'this is not json',
    'a JSON string'                  => '"hello"',
    'a JSON number'                  => '42',
    'a JSON null'                    => 'null',
    'a JSON array rather than object'=> '[1,2,3]',
    'truncated JSON'                 => '{"token":',
    'deeply nested JSON'             => str_repeat('[', 40) . str_repeat(']', 40),
    'a body of 20kB'                 => (string) wp_json_encode(['token' => str_repeat('a', 20000)]),
] as $label => $raw) {
    clear_rate_limits();
    $codes = [
        code(IDO_League_Endpoint::hello(request($raw))),
        code(IDO_League_Endpoint::join(request($raw))),
        code(IDO_League_Endpoint::enrol_status(request($raw))),
    ];
    $all = count(array_filter($codes, static fn($c) => $c >= 400)) === 3;
    check('every route refuses ' . $label, $all, implode('/', $codes));
}

say('');
say('=== type confusion in every field ===');
$shapes = [
    'an array'   => ['x'],
    'an object'  => ['a' => 'b'],
    'a number'   => 12345,
    'a boolean'  => true,
    'null'       => null,
];
foreach ($shapes as $label => $value) {
    clear_rate_limits();
    $codes = [
        code(IDO_League_Endpoint::hello(request(['league' => $value, 'nonce' => $value]))),
        code(IDO_League_Endpoint::join(request(['token' => $value, 'site' => $value, 'url' => $value, 'name' => $value]))),
        code(IDO_League_Endpoint::enrol_status(request(['token' => $value, 'site' => $value]))),
    ];
    check('every route refuses ' . $label . ' where text belongs',
        count(array_filter($codes, static fn($c) => $c >= 400)) === 3, implode('/', $codes));
}

// The one that was wrong before this test existed: a cast rather than a check
// turned an array into the word "Array", which then passed the name rules.
clear_rate_limits();
$r = IDO_League_Endpoint::join(request(['token' => $token, 'site' => IDO_League_Crypto::uuid(),
    'url' => 'https://member.example.com', 'name' => []]));
check('an array in the name field does not become the word "Array"', code($r) === 400, 'status ' . code($r));
check('and nothing was stored', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('sites') . ' WHERE league_id = %d AND is_hub = 0',
    (int) $league->id)) === 0);

say('');
say('=== payloads in each field ===');
foreach ($payloads as $payload) {
    clear_rate_limits();
    $label = substr(str_replace(["\x00", "\n"], ['\0', '\n'], $payload), 0, 34);
    $codes = [
        code(IDO_League_Endpoint::hello(request(['league' => $payload, 'nonce' => $payload]))),
        code(IDO_League_Endpoint::join(request(['token' => $payload, 'site' => $payload,
                                                'url' => $payload, 'name' => $payload]))),
        code(IDO_League_Endpoint::join(request(['token' => $token, 'site' => IDO_League_Crypto::uuid(),
                                                'url' => 'https://member.example.com', 'name' => $payload]))),
        code(IDO_League_Endpoint::enrol_status(request(['token' => $payload, 'site' => $payload]))),
    ];
    check('refuses  ' . $label, count(array_filter($codes, static fn($c) => $c >= 400)) === 4, implode('/', $codes));
}

say('');
say('=== addresses it must not dial ===');
foreach (['http://member.example.com', 'https://127.0.0.1', 'https://localhost', 'https://10.0.0.5',
          'https://169.254.169.254', 'https://[::1]', 'https://user:pass@member.example.com',
          'https://member.example.com/?x=1', 'file:///etc/passwd', 'gopher://evil.example.com',
          'https://hub.example.com'] as $url) {
    clear_rate_limits();
    $dialled = [];
    $c = code(IDO_League_Endpoint::join(request(['token' => $token, 'site' => IDO_League_Crypto::uuid(),
        'url' => $url, 'name' => 'Northmarch'])));
    check('refuses ' . substr($url, 0, 38), $c >= 400 && $dialled === [],
        $dialled ? 'DIALLED IT' : 'status ' . $c);
}

say('');
say('=== the handlers are not an oracle ===');
clear_rate_limits();
$unknown_token = IDO_League_Endpoint::join(request(['token' => str_repeat('f', 64),
    'site' => IDO_League_Crypto::uuid(), 'url' => 'https://member.example.com', 'name' => 'Northmarch']));
check('a refusal says only that it refused', body($unknown_token) === ['status' => 'refused'],
    (string) wp_json_encode(body($unknown_token)));
check('and never names the reason', strpos((string) wp_json_encode(body($unknown_token)), 'token') === false);

clear_rate_limits();
$hello_wrong_league = IDO_League_Endpoint::hello(request([
    'league' => IDO_League_Crypto::uuid(), 'nonce' => str_repeat('a', 64)]));
check('hello refuses an unknown league without explaining', code($hello_wrong_league) === 404
    && body($hello_wrong_league) === ['status' => 'refused']);

say('');
say('=== hello gives away nothing but the proof ===');
// Put this site into a pending enrolment so hello has something to answer with.
IDO_League::drop_tables();
IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();
$hub_token = bin2hex(random_bytes(32));
$lid = IDO_League_Crypto::uuid();
IDO_League_Setup::join(IDO_League_Crypto::b64_encode((string) wp_json_encode([
    'v' => 1, 'lid' => $lid, 'name' => 'Endpoint Test Far', 'hub' => 'https://far.example.com',
    'token' => $hub_token,
])));
IDO_League::forget();

clear_rate_limits();
$nonce = str_repeat('7', 64);
$answer = IDO_League_Endpoint::hello(request(['league' => $lid, 'nonce' => $nonce]));
check('it answers a genuine callback', code($answer) === 200);
check('with the right proof',
    ($answer instanceof WP_REST_Response ? (body($answer)['proof'] ?? '') : '')
    === hash_hmac('sha256', $nonce, $hub_token));
check('and with nothing else at all', array_keys(body($answer)) === ['proof']);
check('the token itself never leaves', strpos((string) wp_json_encode(body($answer)), $hub_token) === false);

clear_rate_limits();
check('a caller naming the wrong league gets nothing',
    code(IDO_League_Endpoint::hello(request(['league' => IDO_League_Crypto::uuid(), 'nonce' => $nonce]))) === 404);

say('');
say('=== the joining site is not an enrolment hub ===');
clear_rate_limits();
check('join is refused on a site that is not the originator',
    code(IDO_League_Endpoint::join(request(['token' => $hub_token, 'site' => IDO_League_Crypto::uuid(),
        'url' => 'https://member.example.com', 'name' => 'Northmarch']))) === 404);
check('and so is enrol-status',
    code(IDO_League_Endpoint::enrol_status(request(['token' => $hub_token,
        'site' => IDO_League_Crypto::uuid()]))) === 404);

say('');
say('=== and the database is untouched ===');
check('the sites table still exists',
    (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', IDO_DB::t('sites'))) === IDO_DB::t('sites'));
check('wp_users still exists',
    (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->users)) === $wpdb->users);
check('no member rows were created by any of this',
    (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . IDO_DB::t('sites') . ' WHERE is_hub = 0') === 0);
check('nothing was ever dialled', $dialled === [], (string) wp_json_encode($dialled));

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
