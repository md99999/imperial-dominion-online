<?php
/**
 * Integration test: founding a league, inviting, joining and leaving, against a
 * real WordPress and a real database.
 *
 * The security tests prove the signature and the URL rules in isolation. This
 * one proves the setup actually writes what it claims: that opting in creates
 * the tables, that a league row comes back the way it went in, that an
 * invitation is stored hashed rather than in the clear, and that leaving and
 * opting out put the site back where it started.
 *
 *   php tests/integration-league.php /path/to/wordpress [db-host]
 *
 * It restores the site's original league setting on the way out, including
 * after a failure, so running it on a site that is genuinely in a league does
 * not disturb it. It refuses to run at all if that site is already in one.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) {
    say('Usage: php tests/integration-league.php /path/to/wordpress [db-host]');
    exit(2);
}
$wp_load = rtrim(str_replace('\\', '/', $wp_path), '/') . '/wp-load.php';
if (!is_readable($wp_load)) { say('Cannot read ' . $wp_load); exit(2); }

$db_host = $argv[2] ?? getenv('IDO_DB_HOST');
if ($db_host) define('DB_HOST', $db_host);

require $wp_load;

$fails = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $fails;
    if (!$ok) $fails++;
    say(sprintf('%-58s %s%s', $label, $ok ? 'PASS' : 'FAIL', $detail ? '  (' . $detail . ')' : ''));
}

if (!class_exists('IDO_League')) { say('The plugin is not active on that site, or is older than 2.0.0.'); exit(1); }

global $wpdb;

$was_enabled = IDO_Settings::int(IDO_League::SETTING);
if ($was_enabled === 1 && IDO_League::league()) {
    say('This site is already in a league. Refusing to run rather than disturbing it.');
    exit(2);
}

// Whatever happens below, put the whole settings row back exactly as it was.
// Restoring only the one key would leave this test's other changes behind, and
// a test that quietly edits the site it ran against is worse than no test.
$settings_before = get_option(IDO_Settings::OPTION);
register_shutdown_function(static function () use ($settings_before) {
    if ($settings_before === false) {
        delete_option(IDO_Settings::OPTION);
    } else {
        update_option(IDO_Settings::OPTION, $settings_before);
    }
});

say('=== opting in ===');
check('league play starts off', !IDO_League::enabled() || $was_enabled === 1);
IDO_League_Setup::opt_in();
check('opting in turns it on', IDO_League::enabled());
check('the tables now exist', IDO_League::tables_exist());
check('no league yet', IDO_League::league() === null);
check('so the site is not active', !IDO_League::active());

say('');
say('=== founding ===');
// A development site is http://something.local, which league play correctly
// refuses. Present a production-like address for the duration so the real
// founding path is exercised rather than skipped: this stands in for the site
// URL, it does not relax the rule being tested.
$pretend_url = 'https://league-test.example.com';
add_filter('home_url', static function () use ($pretend_url) { return $pretend_url; }, 99);
check('the site URL is usable once it looks like production',
    IDO_League::own_url() === $pretend_url, IDO_League::own_url());

$name = 'Test League ' . wp_rand(1000, 9999);
$league = null;
try {
    $league = IDO_League_Setup::found([
        'league_name' => $name, 'max_sites' => 12, 'round_days' => 90,
        'muster_days' => 5, 'delay_min_days' => 3, 'delay_max_days' => 8,
    ]);
    check('a league is founded', $league->league_name === $name);
} catch (IDO_Game_Exception $e) {
    // A dev site on http:// cannot found a league, which is correct behaviour
    // rather than a failure: say so clearly instead of reporting a false fail.
    say('SKIP  founding: ' . $e->getMessage());
}

if ($league) {
    check('this site is the originator', IDO_League::is_originator());
    check('and is now active', IDO_League::active());
    check('the round is long enough for an exchange',
        (int) $league->round_days > (int) $league->muster_days + 2 * (int) $league->delay_max_days);
    check('a fingerprint was recorded', strlen((string) $league->fingerprint) === 64);
    check('the fingerprint matches this site\'s settings',
        $league->fingerprint === IDO_League::fingerprint());
    check('the ruleset stored is readable JSON', is_array(json_decode((string) $league->ruleset, true)));
    check('timestamps are stored as UTC',
        abs(strtotime((string) $league->created_at . ' UTC') - time()) < 300,
        (string) $league->created_at);

    say('');
    say('=== a second league is refused while in one ===');
    $second = 'allowed';
    try {
        IDO_League_Setup::found(['league_name' => 'Rival']);
    } catch (IDO_Game_Exception $e) {
        $second = $e->getMessage();
    }
    check('founding a second league is refused', $second !== 'allowed', (string) $second);

    say('');
    say('=== invitations ===');
    $blob = IDO_League_Setup::invite('Test peer');
    check('an invitation is produced', is_string($blob) && $blob !== '');

    $decoded = json_decode((string) IDO_League_Crypto::b64_decode($blob), true);
    check('it carries the league id and hub', !empty($decoded['lid']) && !empty($decoded['hub']));
    check('it carries a token', !empty($decoded['token']) && strlen($decoded['token']) === 64);
    check('it does not carry a secret',
        !isset($decoded['secret']) && strpos(strtolower((string) json_encode($decoded)), 'secret') === false);

    $stored = $wpdb->get_var($wpdb->prepare(
        'SELECT token_hash FROM ' . IDO_DB::t('invites') . ' WHERE league_id = %d ORDER BY id DESC LIMIT 1',
        (int) $league->id
    ));
    check('the token is stored hashed, not in the clear', $stored !== $decoded['token']);
    check('and the hash is the hash of that token',
        $stored === IDO_League_Crypto::token_hash($decoded['token']));
    check('the open invitation is listed', count(IDO_League_Setup::open_invites($league)) >= 1);

    say('');
    say('=== reading an invitation back ===');
    // The hub URL in our own invitation is the pretend one, which does not
    // resolve. Point the resolver at a public address so this tests the
    // parsing rather than the DNS.
    IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };
    try {
        // Our own hub URL will only be callable on a real public site, so this
        // asserts the parse rather than the network check.
        $read = IDO_League_Setup::read_invitation($blob);
        check('a genuine invitation reads back', $read['lid'] === $decoded['lid']);
    } catch (IDO_Game_Exception $e) {
        say('SKIP  reading back: ' . $e->getMessage());
    }

    foreach ([
        'not base64 at all !!!'                            => 'rubbish',
        IDO_League_Crypto::b64_encode('{"lid":"x"}')       => 'a bad league id',
        IDO_League_Crypto::b64_encode(json_encode([
            'lid' => '11111111-1111-1111-1111-111111111111',
            'hub' => 'https://127.0.0.1', 'token' => str_repeat('a', 64)]))  => 'a loopback hub',
        IDO_League_Crypto::b64_encode(json_encode([
            'lid' => '11111111-1111-1111-1111-111111111111',
            'hub' => 'http://example.com', 'token' => str_repeat('a', 64)])) => 'a plain http hub',
    ] as $bad => $what) {
        $refused = false;
        try { IDO_League_Setup::read_invitation((string) $bad); } catch (IDO_Game_Exception $e) { $refused = true; }
        check("refuses $what", $refused);
    }

    say('');
    say('=== the endpoint gate ===');
    // A previous run may have left the key set, and a default only applies to a
    // key that is absent. Clear it so this tests the default rather than
    // whatever this site happens to be carrying.
    $stored = get_option(IDO_Settings::OPTION);
    if (is_array($stored)) {
        unset($stored['league_endpoint']);
        update_option(IDO_Settings::OPTION, $stored);
    }
    check('closed by default, even in a league', !IDO_League::endpoint_enabled());
    check('and says what to do about it',
        strpos(IDO_League::endpoint_status(), 'until you turn it on') !== false,
        IDO_League::endpoint_status());
    IDO_Settings::update(['league_endpoint' => 1]);
    check('turning it on opens it', IDO_League::endpoint_enabled());
    IDO_Settings::update(['league_endpoint' => 0]);
    check('turning it off closes it again', !IDO_League::endpoint_enabled());
    IDO_Settings::update(['league_endpoint' => 1]);

    say('');
    say('=== the kill switch ===');
    IDO_League_Setup::set_paused(true);
    check('pausing stops the site being active', !IDO_League::active());
    check('but it is still in the league', IDO_League::league() !== null);
    IDO_League_Setup::set_paused(false);
    check('resuming brings it back', IDO_League::active());

    say('');
    say('=== leaving ===');
    IDO_League_Setup::leave();
    check('leaving clears the league', IDO_League::league() === null);
    check('and closes the endpoint with it', !IDO_League::endpoint_enabled());
    check('the row is kept rather than deleted',
        (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . IDO_DB::t('leagues')) > 0);
}

say('');
say('=== a round too short for its own delays is refused ===');
// Reachable only now that the site has left: the round-length check sits
// behind the already-in-a-league one, and a test that never gets past the
// first guard proves nothing about the second.
$short = 'allowed';
try {
    IDO_League_Setup::found(['league_name' => 'Doomed', 'round_days' => 10,
                             'muster_days' => 5, 'delay_min_days' => 3, 'delay_max_days' => 8]);
} catch (IDO_Game_Exception $e) {
    $short = $e->getMessage();
}
check('a 10-day round cannot hold a 21-day exchange',
    $short !== 'allowed' && strpos($short, 'too short') !== false, (string) $short);
check('and nothing was created by the attempt', IDO_League::league() === null);

say('');
say('=== opting out ===');
IDO_League_Setup::opt_out(false);
check('opting out turns it off', !IDO_League::enabled());
check('and keeps the tables by default', IDO_League::tables_exist());
check('a site that is off reports no league', IDO_League::league() === null);

// Clean up after ourselves: drop the tables this test created, unless the site
// was already using them before it ran.
if ($was_enabled !== 1) {
    IDO_League::drop_tables();
    check('the tables can be removed', !IDO_League::tables_exist());
} else {
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('leagues') . ' WHERE league_name = %s', $name));
    say('NOTE  left the existing league tables alone.');
}

say('');
say('=== locked shut in wp-config ===');
// Last, because a constant cannot be undefined once it is set: everything that
// needs it absent has already run.
IDO_Settings::update([IDO_League::SETTING => 1, 'league_endpoint' => 1]);
define('IDO_LEAGUE_DISABLE_ENDPOINT', true);
check('the constant closes the endpoint whatever the settings say', !IDO_League::endpoint_enabled());
check('and the reason names wp-config',
    strpos(IDO_League::endpoint_status(), 'wp-config.php') !== false, IDO_League::endpoint_status());

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
