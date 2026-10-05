<?php
/**
 * Integration test: what an order costs in turns.
 *
 * The interesting case is zero. spend_turns() forced a minimum of one until
 * these became settings, so "free" could not be expressed at all, and free has
 * to mean free in both directions: no turn leaves the pool and no income
 * arrives. Turns are the only clock in this game, so an order that advanced the
 * economy without costing one would print gold out of nothing.
 *
 *   php tests/integration-turncosts.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-turncosts.php /path/to/wordpress [db-host]'); exit(2); }
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

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

$was = get_option(IDO_Settings::OPTION);
$name = 'Turn Cost Test ' . wp_rand(1000, 9999);
$wpdb->insert(IDO_DB::t('kingdoms'), [
    'round_id' => (int) $round->id, 'user_id' => 988001,
    'kingdom_name' => $name, 'ruler_name' => $name . ' Ruler',
    'turns' => 60, 'last_turn_grant' => IDO_Game::today(),
    'land' => 3000, 'gold' => 50000000, 'grain' => 5000000, 'iron' => 2000000,
    'peasants' => 200000, 'b_homestead' => 400, 'b_farmstead' => 400,
    'u_pawn' => 5000, 'catapults' => 50,
    'created_at' => IDO_Game::now(),
]);
$id = (int) $wpdb->insert_id;
if (!$id) { say('Could not create the test empire: ' . $wpdb->last_error); exit(1); }

register_shutdown_function(static function () use ($id, $was) {
    global $wpdb;
    $wpdb->delete(IDO_DB::t('constructions'), ['kingdom_id' => $id]);
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $id]);
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
});

/** Turns an action actually costs, measured off the row. */
function costs(int $id, callable $do): int {
    $before = (int) IDO_Kingdom::find($id)->turns;
    $do(IDO_Kingdom::find($id));
    return $before - (int) IDO_Kingdom::find($id)->turns;
}

say('=== the defaults keep the game as it was ===');
IDO_Settings::update(['build_turn_cost' => 1, 'demolish_turn_cost' => 0,
                      'train_turn_cost' => 1, 'disband_turn_cost' => 0]);
check('a building order costs 1', IDO_Settings::int('build_turn_cost') === 1);
check('training costs 1', IDO_Settings::int('train_turn_cost') === 1);
check('pulling down is free', IDO_Settings::int('demolish_turn_cost') === 0);
check('standing down is free', IDO_Settings::int('disband_turn_cost') === 0);

say('');
say('=== and each one is actually charged ===');
check('build spends one turn',
    costs($id, fn($k) => IDO_Construction::order($k, 'mint', 5)) === 1);
check('train spends one turn',
    costs($id, fn($k) => IDO_Military::train($k, 'pawn', 10)) === 1);
check('a siege order spends a build turn',
    costs($id, fn($k) => IDO_Construction::order_weapon($k, 'catapult', 1)) === 1);
check('demolish spends none',
    costs($id, fn($k) => IDO_Construction::demolish($k, 'farmstead', 5)) === 0);
check('disband spends none',
    costs($id, fn($k) => IDO_Military::disband($k, 'pawn', 10)) === 0);
check('scrapping a weapon spends none',
    costs($id, fn($k) => IDO_Construction::scrap_weapon($k, 'catapult', 1)) === 0);

say('');
say('=== free really is free: no turn, and no income either ===');
// The trap: if a zero-cost order still advanced the economy, every demolish
// would be a free turn's income, and gold would come from nowhere.
$before = IDO_Kingdom::find($id);
IDO_Construction::demolish($before, 'farmstead', 1);
$after = IDO_Kingdom::find($id);
$refund = (int) round(1 * IDO_Settings::int('build_gold_per_acre')
    * IDO_Settings::int('demolish_refund_percent') / 100);
check('gold moved by the salvage and nothing else',
    (int) $after->gold - (int) $before->gold === $refund,
    ((int) $after->gold - (int) $before->gold) . ' vs salvage ' . $refund);
check('no grain was produced', (int) $after->grain === (int) $before->grain);
check('no iron was produced', (int) $after->iron === (int) $before->iron);
check('and turns_spent did not move',
    (int) $after->turns_spent === (int) $before->turns_spent);

say('');
say('=== the settings are obeyed, not just stored ===');
IDO_Settings::update(['build_turn_cost' => 2, 'demolish_turn_cost' => 1,
                      'train_turn_cost' => 3, 'disband_turn_cost' => 2]);
check('build at 2 costs 2',
    costs($id, fn($k) => IDO_Construction::order($k, 'mint', 5)) === 2);
check('demolish at 1 costs 1',
    costs($id, fn($k) => IDO_Construction::demolish($k, 'farmstead', 5)) === 1);
check('train at 3 costs 3',
    costs($id, fn($k) => IDO_Military::train($k, 'pawn', 10)) === 3);
check('disband at 2 costs 2',
    costs($id, fn($k) => IDO_Military::disband($k, 'pawn', 10)) === 2);

say('');
say('=== a costed order pays its income, as any turn does ===');
$before = IDO_Kingdom::find($id);
IDO_Construction::demolish($before, 'farmstead', 1);
$after = IDO_Kingdom::find($id);
// Deliberately not asserting that grain went up. This empire feeds 200,000
// peasants off 400 farmsteads, so a turn's net grain is negative, and that is
// the economy working rather than failing. What proves the turn was lived
// through is the counter that only advance() moves.
check('now a turn is charged, the economy advances with it',
    (int) $after->turns_spent === (int) $before->turns_spent + 1,
    ((int) $after->turns_spent - (int) $before->turns_spent) . ' turn(s) recorded');
check('and the stores moved, in whichever direction this empire is heading',
    (int) $after->grain !== (int) $before->grain,
    ((int) $after->grain - (int) $before->grain) . ' grain');

say('');
say('=== and the player is told, in the same breath ===');
$lines = IDO_Construction::demolish(IDO_Kingdom::find($id), 'farmstead', 1);
check('demolish returns the run of messages, not one string', is_array($lines));
check('including the turn that was spent',
    count(array_filter($lines, static fn($l) => strpos(is_array($l) ? $l[1] : $l, 'spent') !== false)) > 0,
    (string) count($lines) . ' line(s)');
check('and what was pulled down',
    count(array_filter($lines, static fn($l) => strpos(is_array($l) ? $l[1] : $l, 'pulled down') !== false)) > 0);

say('');
say('=== not enough turns is refused before anything is paid ===');
$wpdb->update(IDO_DB::t('kingdoms'), ['turns' => 1, 'b_mint' => 50], ['id' => $id]);
$before = IDO_Kingdom::find($id);
$refused = '';
try { IDO_Construction::order($before, 'mint', 1); }
catch (IDO_Game_Exception $e) { $refused = $e->getMessage(); }
$after = IDO_Kingdom::find($id);
check('a 2-turn order with 1 turn in hand is refused',
    strpos($refused, 'needs 2 turns') !== false, $refused);
check('and the treasury was not touched', (int) $after->gold === (int) $before->gold);
check('nor the turn', (int) $after->turns === 1, (string) $after->turns);

say('');
say('=== the league governs them ===');
foreach (['build_turn_cost', 'demolish_turn_cost', 'train_turn_cost', 'disband_turn_cost'] as $key) {
    check("the league owns $key", in_array($key, IDO_League::governed_keys(), true));
}

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
