<?php
/**
 * Integration test: what league mode does to local play.
 *
 * The point of this one is that hiding an order from a screen is not the same as
 * refusing it. Every check here calls the service directly, the way a crafted
 * POST would, rather than looking at what a page renders.
 *
 *   php tests/integration-leaguemode.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-leaguemode.php /path/to/wordpress [db-host]'); exit(2); }
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
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

if (IDO_League::tables_exist()) {
    foreach ((array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues')) as $name) {
        if (strpos((string) $name, 'Mode Test') !== 0) {
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
    fwrite(STDERR, 'Test empires removed.' . PHP_EOL);
});

function empire(string $name): object {
    global $wpdb, $round, $made;
    static $user = 999700;
    $user++;
    $wpdb->insert(IDO_DB::t('kingdoms'), [
        'round_id' => (int) $round->id, 'user_id' => $user,
        'kingdom_name' => $name . ' ' . $user, 'ruler_name' => $name . ' Ruler ' . $user,
        'turns' => 50, 'last_turn_grant' => IDO_Game::today(),
        'land' => 500, 'gold' => 5000000, 'grain' => 200000, 'iron' => 100000, 'peasants' => 3000,
        'u_pawn' => 2000, 'u_legionnaire' => 1500, 'u_centurion' => 800,
        'agents' => 1, 'networth' => 1000000,
        'created_at' => IDO_Game::now(),
    ]);
    $id = (int) $wpdb->insert_id;
    $made[] = $id;
    return IDO_Kingdom::find($id);
}

$us   = empire('Mode A');
$them = empire('Mode B');

say('=== with no league, local play is untouched ===');
check('local war is available',
    refused(static fn() => IDO_Military::attack($us, (int) $them->id, 'raid', ['pawn' => 1])) !== 'LEAGUE');
$why = refused(static fn() => IDO_Military::attack($us, (int) $them->id, 'raid', ['pawn' => 1]));
check('and any refusal is about the local rules, not a league',
    stripos($why, 'league') === false, $why ?: 'allowed outright');

say('');
say('=== in league mode, local war is refused in the service ===');
add_filter('home_url', static function () { return 'https://hub.example.com'; }, 99);
IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };
IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();
$league = IDO_League_Setup::found(['league_name' => 'Mode Test ' . wp_rand(100, 999)]);
check('the site is playing a league', IDO_League::active());

$us = IDO_Kingdom::reload($us);
$before = ['turns' => (int) $us->turns, 'pawns' => (int) $us->u_pawn, 'gold' => (int) $us->gold];

$why = refused(static fn() => IDO_Military::attack($us, (int) $them->id, 'raid', ['pawn' => 500]));
check('a march on a neighbour is refused', $why !== '');
check('and says why in the league\'s terms', stripos($why, 'league') !== false, $why);

$us = IDO_Kingdom::reload($us);
check('no turn was spent on it', (int) $us->turns === $before['turns'],
    $us->turns . ' of ' . $before['turns']);
check('no troops moved', (int) $us->u_pawn === $before['pawns']);
check('and the neighbour is untouched',
    (int) IDO_Kingdom::reload($them)->u_pawn === 2000);

foreach (['raid', 'conquest', 'siege'] as $type) {
    check("every kind of march is refused: $type",
        refused(static fn() => IDO_Military::attack($us, (int) $them->id, $type, ['pawn' => 500])) !== '');
}

say('');
say('=== and so is spying on the people you march beside ===');
foreach (['recon', 'burn_granary', 'sabotage_forge', 'incite_revolt'] as $op) {
    $why = refused(static fn() => IDO_Covert::run($us, (int) $them->id, $op));
    check("$op is refused", $why !== '' && stripos($why, 'marching beside') !== false, $why);
}
$us = IDO_Kingdom::reload($us);
check('the agent is still at home', (int) $us->agents === 1);
check('and no gold was spent on bribes', (int) $us->gold === $before['gold']);

say('');
say('=== the muster is reachable, which is the point of turning the rest off ===');
$secret = IDO_League_Crypto::secret();
$wpdb->insert(IDO_DB::t('sites'), [
    'league_id' => (int) $league->id, 'site_uuid' => IDO_League_Crypto::uuid(),
    'site_name' => 'Northmarch', 'site_url' => 'https://member.example.com',
    'secret' => $secret, 'status' => 'active', 'secret_issued_at' => IDO_League::now(),
    'created_at' => IDO_League::now(),
]);
$peer = IDO_League_Queue::peer((int) $wpdb->insert_id);

check('calling a muster works where a local march does not',
    refused(static fn() => IDO_League_Muster::call($us, (int) $peer->id,
        ['legionnaire' => 400], [], true)) === '');
$march = IDO_League_Muster::open();
check('and it is open', $march !== null);

$us = IDO_Kingdom::reload($us);
check('the pledged force left the empire', (int) $us->u_legionnaire === 1100, (string) $us->u_legionnaire);
check('and the agent went with it', (int) $us->agents === 0);
check('the same agent that could not be sent against a neighbour',
    IDO_League_Covert::slot_holder((int) $march->id) === (int) $us->id);

$them_joined = refused(static fn() => IDO_League_Muster::join($them, (int) $march->id, ['pawn' => 300]));
check('another ruler can join it', $them_joined === '', $them_joined);
check('so both empires are contributing to the same army',
    count(IDO_League_Muster::contributions((int) $march->id)) === 2);

say('');
say('=== leaving the league gives local play back ===');
IDO_League_Muster::cancel((int) $march->id, 'Test tidy-up.');
IDO_League_Setup::leave();
IDO_League::forget();
check('the site is no longer playing a league', !IDO_League::active());
$why = refused(static fn() => IDO_Military::attack(IDO_Kingdom::reload($us), (int) $them->id, 'raid', ['pawn' => 1]));
check('and a local march is no longer refused for league reasons',
    stripos($why, 'league') === false, $why ?: 'allowed outright');

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
