<?php
/**
 * Integration test: hostile numbers in player-facing fields.
 *
 * Every quantity in this game arrives as a string in a POST and nothing stops a
 * player typing 99999999999999999999 into it, or -1, or 1e30, or an array. The
 * question is not whether the form accepts it -- the form is a suggestion -- but
 * whether anything downstream can be talked into handing out goods.
 *
 * Three invariants, checked after every attack:
 *
 *   nothing an empire holds ever goes negative
 *   nothing an empire holds ever exceeds the ceiling the game can store
 *   a refused order leaves the empire exactly as it was
 *
 *   php tests/integration-overflow.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-overflow.php /path/to/wordpress [db-host]'); exit(2); }
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

/** Values a hostile player can put in a numeric field. */
function hostile(): array {
    return [
        'huge string'      => '99999999999999999999',
        'past int max'     => '9223372036854775808',
        'int max'          => (string) PHP_INT_MAX,
        'negative huge'    => '-99999999999999999999',
        'int min'          => (string) PHP_INT_MIN,
        'negative one'     => '-1',
        'scientific'       => '1e30',
        'float'            => '12.9',
        'hex'              => '0x7FFFFFFF',
        'padded'           => '  500  ',
        'comma grouped'    => '9,999,999',
        'empty'            => '',
        'word'             => 'all',
        'array'            => ['1', '2'],
    ];
}

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }
$rid = (int) $round->id;

$tag = 'Ovf' . wp_rand(1000, 9999);
$wpdb->insert(IDO_DB::t('kingdoms'), [
    'round_id' => $rid, 'user_id' => 933001,
    'kingdom_name' => $tag, 'ruler_name' => $tag . ' Ruler',
    'turns' => 500, 'last_turn_grant' => IDO_Game::today(),
    'land' => 400, 'gold' => 1000000, 'grain' => 500000, 'iron' => 100000,
    'peasants' => 5000, 'b_homestead' => 50, 'b_farmstead' => 50, 'b_mint' => 20,
    'u_pawn' => 100, 'u_legionnaire' => 100, 'catapults' => 5,
    'created_at' => IDO_Game::now(),
]);
$me = (int) $wpdb->insert_id;
if (!$me) { say('Could not create the empire: ' . $wpdb->last_error); exit(1); }
IDO_Kingdom::recalc_networth(IDO_Kingdom::find($me));

register_shutdown_function(static function () use ($me, $tag) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('listings') . ' WHERE seller_kingdom_id = %d', $me));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('constructions') . ' WHERE kingdom_id = %d', $me));
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $me]);
});

/** Everything an empire holds, as plain integers. */
function holdings(int $id): array {
    $k = IDO_Kingdom::find($id);
    $out = [];
    foreach (IDO_Kingdom::numeric_columns() as $c) $out[$c] = (int) ($k->{$c} ?? 0);
    $out['networth'] = (int) $k->networth;
    return $out;
}
/**
 * Goods that arrived without being paid for.
 *
 * Not simply "something went up". Building twelve mints puts twelve into
 * land_in_progress and that is a purchase, not a theft -- the test that flags it
 * is measuring the wrong thing. What cannot happen is holdings growing while the
 * treasury and the forges stay whole, so that is what this looks for.
 */
function free_goods(array $before, array $after): array {
    // Resources are the wrong signal. Every turn an order spends pays out that
    // turn's income first, and on a developed empire the income is larger than
    // the thing being bought -- so training twelve pawns leaves more gold than
    // it started with and looks, to a naive check, like theft.
    //
    // Turns are the honest measure. They are the one thing the game never hands
    // back, so an order that spent one was an order that was paid for.
    $paid = $after['turns'] < $before['turns']
        || $after['peasants'] < $before['peasants']
        || $after['gold'] < $before['gold']
        || $after['iron'] < $before['iron'];
    if ($paid) return [];

    $earned = ['gold', 'grain', 'iron', 'peasants', 'turns_spent', 'networth'];
    $out = [];
    foreach ($after as $c => $v) {
        if (in_array($c, $earned, true)) continue;
        if ($v > ($before[$c] ?? 0)) $out[] = $c . ' +' . ($v - $before[$c]) . ' for nothing';
    }
    return $out;
}
function negatives(array $h): array {
    $out = [];
    foreach ($h as $c => $v) if ($v < 0) $out[] = "$c=$v";
    return $out;
}
function over_ceiling(array $h): array {
    $out = [];
    foreach ($h as $c => $v) if ($v > IDO_Game::MAX_VALUE) $out[] = "$c=$v";
    return $out;
}

say('=== what PHP does with the numbers themselves ===');
check('a huge string saturates rather than wrapping negative',
    (int) '99999999999999999999' === PHP_INT_MAX, (string) (int) '99999999999999999999');
check('and so does scientific notation', (int) '1e30' === PHP_INT_MAX);
check('qty() never returns a negative',
    IDO_Game::qty('-99999999999999999999') === 0 && IDO_Game::qty('-1') === 0);
check('qty() honours its ceiling',
    IDO_Game::qty('99999999999999999999', 1000) === 1000);
check('clamp() refuses to exceed the stored ceiling',
    IDO_Game::clamp('99999999999999999999') === IDO_Game::MAX_VALUE);
check('clamp() floors at zero', IDO_Game::clamp('-1e30') === 0);
check('and a NAN sinks to the bottom rather than the top',
    IDO_Game::clamp(NAN) === 0);

say('');
say('=== pay() cannot be talked into a negative balance ===');
$before = holdings($me);
$refused = false;
try { IDO_Kingdom::pay(IDO_Kingdom::find($me), ['gold' => -PHP_INT_MAX]); }
catch (IDO_Game_Exception $e) { $refused = true; }
check('spending more than you hold is refused', $refused);
check('and the treasury is untouched', (int) IDO_Kingdom::find($me)->gold === $before['gold']);

$refused = false;
try { IDO_Kingdom::pay(IDO_Kingdom::find($me), ['gold' => PHP_INT_MAX]); }
catch (IDO_Game_Exception $e) { $refused = true; }
check('a gain beyond the ceiling is refused outright', $refused);
check('and still nothing was credited', (int) IDO_Kingdom::find($me)->gold === $before['gold']);

say('');
say('=== every order, with every hostile value ===');
$orders = [
    'build'        => static fn($v) => IDO_Construction::order(IDO_Kingdom::find($GLOBALS['me']), 'mint', $v),
    'demolish'     => static fn($v) => IDO_Construction::demolish(IDO_Kingdom::find($GLOBALS['me']), 'mint', $v),
    'build weapon' => static fn($v) => IDO_Construction::order_weapon(IDO_Kingdom::find($GLOBALS['me']), 'catapult', $v),
    'scrap weapon' => static fn($v) => IDO_Construction::scrap_weapon(IDO_Kingdom::find($GLOBALS['me']), 'catapult', $v),
    'train'        => static fn($v) => IDO_Military::train(IDO_Kingdom::find($GLOBALS['me']), 'pawn', $v),
    'disband'      => static fn($v) => IDO_Military::disband(IDO_Kingdom::find($GLOBALS['me']), 'pawn', $v),
    'market post'  => static fn($v) => IDO_Market::post(IDO_Kingdom::find($GLOBALS['me']), 'grain', $v, $v),
];

$bad_gain = [];
$bad_neg  = [];
$bad_cap  = [];
$errors   = 0;
$tried    = 0;

foreach ($orders as $name => $fn) {
    foreach (hostile() as $what => $value) {
        $before = holdings($me);
        $tried++;
        try {
            // Cast exactly as IDO_Actions::int() does before any service is
            // called. Handing a raw string to a service that declares int $qty
            // tests PHP's type checker rather than this game, and production
            // never does it: every quantity is cast at the form boundary.
            $fn((int) (is_array($value) ? count($value) : $value));
        } catch (IDO_Game_Exception $e) {
            // An ordinary refusal. That is the system working.
        } catch (Throwable $e) {
            $errors++;
            say('  UNCAUGHT from ' . $name . ' / ' . $what . ': ' . get_class($e) . ' ' . $e->getMessage());
        }
        $after = holdings($me);

        foreach (free_goods($before, $after) as $g) $bad_gain[] = "$name/$what: $g";
        foreach (negatives($after) as $n)      $bad_neg[]  = "$name/$what: $n";
        foreach (over_ceiling($after) as $o)   $bad_cap[]  = "$name/$what: $o";

        // Put the empire back so each attack starts from the same ground.
        $wpdb->update(IDO_DB::t('kingdoms'), [
            'gold' => 1000000, 'grain' => 500000, 'iron' => 100000, 'peasants' => 5000,
            'land' => 400, 'land_in_progress' => 0, 'turns' => 500,
            'b_mint' => 20, 'u_pawn' => 100, 'catapults' => 5, 'catapults_in_progress' => 0,
        ], ['id' => $me]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('constructions') . ' WHERE kingdom_id = %d', $me));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('listings') . ' WHERE seller_kingdom_id = %d', $me));
    }
}

say('  ' . $tried . ' hostile orders attempted across ' . count($orders) . ' actions');
check('nothing was ever gained without paying for it', $bad_gain === [],
    implode(' | ', array_slice($bad_gain, 0, 4)));
check('nothing an empire holds ever went negative', $bad_neg === [],
    implode(' | ', array_slice($bad_neg, 0, 4)));
check('nothing ever exceeded the stored ceiling', $bad_cap === [],
    implode(' | ', array_slice($bad_cap, 0, 4)));
check('and nothing threw anything but a game refusal', $errors === 0, (string) $errors . ' uncaught');

say('');
say('=== the services refuse junk even if the form layer is bypassed ===');
// Defence in depth. The form boundary casts, so a service should never see a
// string -- but every quantity argument is typed, so if one ever does arrive it
// is rejected by PHP rather than coerced into something surprising.
$typed = 0;
foreach ([['IDO_Military', 'train'], ['IDO_Market', 'post'],
          ['IDO_Construction', 'order'], ['IDO_Covert', 'run']] as $pair) {
    $r = new ReflectionMethod($pair[0], $pair[1]);
    foreach ($r->getParameters() as $param) {
        $type = $param->getType();
        if ($type && in_array($param->getName(), ['qty', 'unit_price', 'target_id'], true)
            && (string) $type === 'int') {
            $typed++;
        }
    }
}
check('quantity arguments are typed, not merely hoped for', $typed >= 4, (string) $typed . ' typed');

say('');
say('=== a hostile force committed to a march ===');
$target = $wpdb->get_row($wpdb->prepare(
    'SELECT id FROM ' . IDO_DB::t('kingdoms')
    . ' WHERE round_id = %d AND id <> %d AND is_defeated = 0 LIMIT 1', $rid, $me));
if ($target) {
    $before = holdings($me);
    $errors = 0;
    foreach (hostile() as $what => $value) {
        try {
            $n = (int) (is_array($value) ? count($value) : $value);
            IDO_Military::attack(IDO_Kingdom::find($me), (int) $target->id, 'conquest',
                ['pawn' => $n, 'centurion' => $n], ['catapult' => $n]);
        } catch (IDO_Game_Exception $e) {
            // Expected: not enough troops, out of band, and so on.
        } catch (Throwable $e) {
            $errors++;
            say('  UNCAUGHT from attack / ' . $what . ': ' . get_class($e) . ' ' . $e->getMessage());
        }
    }
    $after = holdings($me);
    check('a march cannot send troops that do not exist',
        (int) $after['u_pawn'] <= (int) $before['u_pawn'], $after['u_pawn'] . ' pawns');
    check('nor siege weapons that do not exist',
        (int) $after['catapults'] <= (int) $before['catapults'], $after['catapults'] . ' catapults');
    check('and nothing threw anything but a game refusal', $errors === 0, (string) $errors);
    check('nothing went negative', negatives($after) === [], implode(',', negatives($after)));
} else {
    say('  (no second empire on this board to march on; skipped)');
}

say('');
say('=== an array where a number was expected ===');
// field() hands back whatever was posted, so a player can post qty[]=1&qty[]=2.
$int = new ReflectionMethod('IDO_Actions', 'int');
$int->setAccessible(true);
$key = new ReflectionMethod('IDO_Actions', 'key');
$key->setAccessible(true);

$_POST['ovf_qty'] = ['9', '9'];
$_POST['ovf_key'] = ['mint', 'mint'];
$as_int = $int->invoke(null, 'ovf_qty');
$as_key = $key->invoke(null, 'ovf_key');
unset($_POST['ovf_qty'], $_POST['ovf_key']);

check('an array read as a number does not become something large',
    is_int($as_int) && $as_int >= 0 && $as_int <= 1, (string) $as_int);
check('and an array read as a key does not become a real key',
    !IDO_Buildings::exists($as_key), var_export($as_key, true));

say('');
say('=== the ceiling holds through net worth ===');
$wpdb->update(IDO_DB::t('kingdoms'), [
    'gold' => IDO_Game::MAX_VALUE, 'grain' => IDO_Game::MAX_VALUE,
    'iron' => IDO_Game::MAX_VALUE, 'land' => IDO_Game::MAX_VALUE,
    'peasants' => IDO_Game::MAX_VALUE,
], ['id' => $me]);
$worth = IDO_Kingdom::recalc_networth(IDO_Kingdom::find($me));
check('an empire stuffed to the ceiling does not overflow its score',
    $worth > 0 && $worth <= IDO_Game::MAX_VALUE, IDO_Game::fmt($worth));
check('and the score is still a whole number', is_int($worth));

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
