<?php
/**
 * The two pieces of league play that are security rather than game design:
 * the signature on a packet, and the URLs this site is willing to call.
 *
 * Everything else in Phase 2 is built on these, so they are tested first and
 * tested for what they refuse rather than for what they accept.
 */
define('ABSPATH', __DIR__ . '/');

function wp_json_encode($data) { return json_encode($data); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
class IDO_Game_Exception extends Exception {}

require __DIR__ . '/../includes/league/class-ido-league-crypto.php';
require __DIR__ . '/../includes/league/class-ido-league-url.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-62s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

$secret = IDO_League_Crypto::secret();
$other  = IDO_League_Crypto::secret();
$from   = IDO_League_Crypto::uuid();
$body   = ['type' => 'war', 'to' => 'b1b2', 'seq' => 7, 'force' => ['centurion' => 800]];
$wire   = IDO_League_Crypto::pack($body, $from, $secret);

echo "=== a packet signed and read back ===\n";
check('it verifies with the right secret', IDO_League_Crypto::verify($wire, $secret));
check('the body survives the round trip', IDO_League_Crypto::open($wire, $secret) === $body);
check('the sender is readable without verifying', IDO_League_Crypto::split($wire)['from'] === $from);

echo "\n=== what it refuses ===\n";
check('a different secret does not verify', !IDO_League_Crypto::verify($wire, $other));
check('an empty secret does not verify', !IDO_League_Crypto::verify($wire, ''));

$tampered = $wire;
$tampered[strlen($tampered) - 1] = $tampered[strlen($tampered) - 1] === 'a' ? 'b' : 'a';
check('a flipped signature byte does not verify', !IDO_League_Crypto::verify($tampered, $secret));

$parts = explode('.', $wire);
$evil  = IDO_League_Crypto::b64_encode(json_encode(['type' => 'war', 'force' => ['centurion' => 999999]]));
check('a swapped payload does not verify',
    !IDO_League_Crypto::verify($parts[0] . '.' . $parts[1] . '.' . $evil . '.' . $parts[3], $secret));
check('a swapped sender does not verify',
    !IDO_League_Crypto::verify($parts[0] . '.' . IDO_League_Crypto::uuid() . '.' . $parts[2] . '.' . $parts[3], $secret));
check('an unknown wire version is refused',
    IDO_League_Crypto::split('ido2.' . $parts[1] . '.' . $parts[2] . '.' . $parts[3]) === null);
check('a packet with no signature at all is refused',
    IDO_League_Crypto::split($parts[0] . '.' . $parts[1] . '.' . $parts[2]) === null);
check('a packet over the size cap is refused',
    IDO_League_Crypto::split(str_repeat('a', IDO_League_Crypto::MAX_BYTES + 1)) === null);
check('an empty string is refused', IDO_League_Crypto::split('') === null);

echo "\n=== the parser is only reachable through a valid signature ===\n";
// These are signed with the real secret, so they get past verification and
// actually reach the parser. That is the point of testing them: a hostile or
// compromised *peer* can do exactly this, and what stops it is the parser's own
// limits rather than the signature.
$signed = static function (string $json) use ($from, $secret): string {
    $body = 'ido1.' . $from . '.' . IDO_League_Crypto::b64_encode($json);
    return $body . '.' . hash_hmac('sha256', $body, $secret);
};
check('malformed JSON yields null rather than throwing',
    IDO_League_Crypto::open($signed('{"broken": '), $secret) === null);
check('a deeply nested body is refused by the depth cap',
    IDO_League_Crypto::open($signed(str_repeat('[', 40) . str_repeat(']', 40)), $secret) === null);
check('a body that is not an object is refused',
    IDO_League_Crypto::open($signed('"just a string"'), $secret) === null);
check('a non-base64 payload yields no body',
    IDO_League_Crypto::open('ido1.' . $from . '.not base64!.' . str_repeat('a', 64), $secret) === null);
check('a valid body with the wrong secret never reaches the parser',
    IDO_League_Crypto::open($signed('{"a":1}'), $other) === null);

echo "\n=== secrets and tokens ===\n";
check('a secret is 32 bytes', strlen(hex2bin(IDO_League_Crypto::secret())) === 32);
check('two secrets differ', IDO_League_Crypto::secret() !== IDO_League_Crypto::secret());
$token = IDO_League_Crypto::token();
check('a token hash is not the token', IDO_League_Crypto::token_hash($token) !== $token);
check('hashing is stable', IDO_League_Crypto::token_hash($token) === IDO_League_Crypto::token_hash($token));

echo "\n=== URLs this site will call ===\n";
foreach ([
    'https://league.example.com'            => 'https://league.example.com',
    'https://League.Example.com/'           => 'https://league.example.com',
    'https://league.example.com/games/ido'  => 'https://league.example.com/games/ido',
    'https://league.example.com:8443'       => 'https://league.example.com:8443',
    'league.example.com'                    => null,
    'http://league.example.com'             => null,
    'ftp://league.example.com'              => null,
    'https://user:pass@league.example.com'  => null,
    'https://league.example.com/?a=1'       => null,
    'https://'                              => null,
    ''                                      => null,
] as $input => $expected) {
    $got = IDO_League_URL::normalize((string) $input);
    check(sprintf('%-38s -> %s', $input === '' ? '(empty)' : $input, $expected === null ? 'refused' : $expected),
        $got === $expected, $got === null ? 'refused' : $got);
}

echo "\n=== addresses this site will not call ===\n";
foreach (['127.0.0.1', '127.1.2.3', '0.0.0.0', '10.0.0.5', '172.16.4.1', '192.168.1.1',
          '169.254.169.254', '100.64.0.1', '100.127.255.254', '224.0.0.1', '255.255.255.255',
          '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1',
          '::ffff:127.0.0.1', '::ffff:169.254.169.254', 'not an address'] as $address) {
    check("refuses $address", !IDO_League_URL::is_public_ip($address));
}
foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111'] as $address) {
    check("allows $address", IDO_League_URL::is_public_ip($address));
}

echo "\n=== the development allowance for local-network pairing ===\n";
// The whole matrix in one process, which is why the decision is a pure function:
// a constant cannot be undefined once set, so reading it directly would test one
// case per run, and "defined but on a production site" is the case that matters
// most and the one such a test would be least likely to reach.
foreach ([
    [false, 'production',  false, 'no constant, production'],
    [false, 'local',       false, 'no constant, local'],
    [true,  'production',  false, 'a constant copied to a production site is ignored'],
    [true,  'staging',     false, 'a constant on staging is ignored'],
    [true,  'local',       true,  'a constant on a local site'],
    [true,  'development', true,  'a constant on a development site'],
] as [$constant, $environment, $expected, $label]) {
    check('  ' . $label, IDO_League_URL::dev_mode_for($constant, $environment) === $expected);
}
check('it is off on this test run', !IDO_League_URL::dev_mode());
check('and says nothing while it is off', IDO_League_URL::dev_notice() === '');
// The allowance covers addresses only. HTTPS is mandatory in every mode.
check('plain http is refused whatever the environment', IDO_League_URL::normalize('http://imperial-a.local') === null);

echo "\n=== a hostname is judged by where it points ===\n";
IDO_League_URL::$resolver = static function (string $host): array {
    return [
        'good.example.com'  => ['93.184.216.34'],
        'evil.example.com'  => ['127.0.0.1'],
        'mixed.example.com' => ['93.184.216.34', '10.0.0.1'],
        'v6.example.com'    => ['2606:4700:4700::1111'],
        'empty.example.com' => [],
    ][$host] ?? [];
};
check('a name resolving to a public address is callable',
    IDO_League_URL::is_callable_url('https://good.example.com'));
check('a name resolving to loopback is not',
    !IDO_League_URL::is_callable_url('https://evil.example.com'));
check('one bad address among good ones is enough to refuse',
    !IDO_League_URL::is_callable_url('https://mixed.example.com'));
check('an IPv6 name is judged the same way',
    IDO_League_URL::is_callable_url('https://v6.example.com'));
check('a name resolving to nothing fails closed',
    !IDO_League_URL::is_callable_url('https://empty.example.com'));
check('a name that does not resolve fails closed',
    !IDO_League_URL::is_callable_url('https://unknown.example.com'));
check('a literal private address is refused before any lookup',
    !IDO_League_URL::is_callable_url('https://192.168.0.10'));

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
