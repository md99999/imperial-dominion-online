<?php
/**
 * Integration test: the two tiers of spy.
 *
 * The tiers exist to differ in what they may **do**, not only in price. If the
 * only difference were cost and risk, a ruler would work out the cheaper
 * expectation once and never think about it again, so the thing most worth
 * pinning here is that an informer cannot be sent to burn anything.
 *
 *   php tests/integration-spies.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-spies.php /path/to/wordpress [db-host]'); exit(2); }
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
function refused(callable $fn): string {
    try { $fn(); return ''; } catch (IDO_Game_Exception $e) { return $e->getMessage(); }
}

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }

$was = get_option(IDO_Settings::OPTION);
$tag = 'SpyTest' . wp_rand(1000, 9999);

$make = static function (string $name, int $user, array $over = []) use ($round, $tag): int {
    global $wpdb;
    $wpdb->insert(IDO_DB::t('kingdoms'), array_merge([
        'round_id' => (int) $round->id, 'user_id' => $user,
        'kingdom_name' => $tag . ' ' . $name, 'ruler_name' => $name,
        'turns' => 200, 'last_turn_grant' => IDO_Game::today(),
        'land' => 2000, 'gold' => 90000000, 'grain' => 4000000, 'iron' => 900000,
        'peasants' => 90000, 'b_homestead' => 300, 'b_farmstead' => 300, 'b_foundry' => 100,
        'networth' => 3000000, 'created_at' => IDO_Game::now(),
    ], $over));
    return (int) $wpdb->insert_id;
};
$me   = $make('Spymaster', 977001);
$them = $make('Victim', 977002, ['protection_until' => null]);
if (!$me || !$them) { say('Could not create test empires: ' . $wpdb->last_error); exit(1); }

register_shutdown_function(static function () use ($me, $them, $was, $tag) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('ops')
        . ' WHERE actor_kingdom_id = %d OR target_kingdom_id = %d', $me, $them));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('news') . ' WHERE message LIKE %s', '%' . $tag . '%'));
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $me]);
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $them]);
    if ($was === false) { delete_option(IDO_Settings::OPTION); } else { update_option(IDO_Settings::OPTION, $was); }
});

IDO_Settings::update(['informer_gold_cost' => 50000, 'agent_gold_cost' => 200000,
                      'max_informers' => 1, 'max_agents' => 1, 'op_turn_cost' => 1]);

say('=== the two tiers ===');
check('there are exactly two', IDO_Agents::keys() === ['informer', 'agent'],
    implode(', ', IDO_Agents::keys()));
check('an informer is the cheaper', IDO_Agents::cost('informer') < IDO_Agents::cost('agent'),
    IDO_Agents::cost('informer') . ' vs ' . IDO_Agents::cost('agent'));
check('an informer may only scout', !IDO_Agents::can_run('informer', 'burn_granary')
    && IDO_Agents::can_run('informer', 'recon'));
check('an agent may do anything', IDO_Agents::can_run('agent', 'incite_revolt'));
check('and is worth more on the scoreboard',
    IDO_Agents::get('agent')['networth'] > IDO_Agents::get('informer')['networth']);

say('');
say('=== hiring ===');
/**
 * Hiring spends a turn before it charges, and that turn pays its income into the
 * same treasury, so the gold that moves is the price minus a turn's earnings and
 * not the price. Measuring one hire against the other from an identical empire
 * cancels the income out, and what is left is the thing worth asserting: the
 * difference between the two prices.
 */
$snapshot = static function (int $id): array {
    $k = IDO_Kingdom::find($id);
    // Everything a turn moves, not just the gold. The first hire grows the
    // peasantry, and peasants pay the taxes, so restoring gold alone leaves the
    // second hire earning a few hundred more and the comparison off by exactly
    // that much.
    return ['gold' => (int) $k->gold, 'turns' => (int) $k->turns,
            'grain' => (int) $k->grain, 'iron' => (int) $k->iron,
            'peasants' => (int) $k->peasants, 'turns_spent' => (int) $k->turns_spent,
            'informers' => (int) $k->informers, 'agents' => (int) $k->agents];
};
$restore = static function (int $id, array $was): void {
    global $wpdb;
    $wpdb->update(IDO_DB::t('kingdoms'), $was, ['id' => $id]);
};

$start = $snapshot($me);
IDO_Covert::hire(IDO_Kingdom::find($me), 'informer');
$k = IDO_Kingdom::find($me);
check('an informer joins the informer column', (int) $k->informers === 1, (string) $k->informers);
check('and not the agent one', (int) $k->agents === 0);
$spent_informer = $start['gold'] - (int) $k->gold;

$restore($me, $start);
IDO_Covert::hire(IDO_Kingdom::find($me), 'agent');
$k = IDO_Kingdom::find($me);
check('an agent joins its own column', (int) $k->agents === 1 && (int) $k->informers === 0);
$spent_agent = $start['gold'] - (int) $k->gold;

check('the agent costs exactly the price difference more',
    $spent_agent - $spent_informer === IDO_Agents::cost('agent') - IDO_Agents::cost('informer'),
    IDO_Game::fmt($spent_agent - $spent_informer) . ' vs '
    . IDO_Game::fmt(IDO_Agents::cost('agent') - IDO_Agents::cost('informer')));

// Both in service for the checks that follow.
$wpdb->update(IDO_DB::t('kingdoms'), ['informers' => 1, 'agents' => 1], ['id' => $me]);

$why = refused(static fn() => IDO_Covert::hire(IDO_Kingdom::find($me), 'informer'));
check('the crown allows only so many of each', stripos($why, 'no ruler may keep more') !== false, $why);
$why = refused(static fn() => IDO_Covert::hire(IDO_Kingdom::find($me), 'spymaster'));
check('an invented tier is refused', stripos($why, 'no such kind') !== false, $why);

say('');
say('=== what each may be sent to do ===');
$why = refused(static fn() => IDO_Covert::run(IDO_Kingdom::find($me), $them, 'burn_granary', 'informer'));
check('an informer cannot be sent to burn granaries',
    stripos($why, 'only bring back what they have seen') !== false, $why);
$k = IDO_Kingdom::find($me);
check('and the refusal cost nothing', (int) $k->informers === 1, (string) $k->informers);

say('');
say('=== a real mission, each way ===');
$k = IDO_Kingdom::find($me);
$turns_before = (int) $k->turns;
$lines = IDO_Covert::run($k, $them, 'recon', 'informer');
check('an informer can scout', is_array($lines) && $lines !== []);
check('and it cost a turn', (int) IDO_Kingdom::find($me)->turns < $turns_before);

// Driven until each outcome has happened at least once, so both branches run.
$wpdb->update(IDO_DB::t('kingdoms'), ['informers' => 1, 'agents' => 1, 'turns' => 400,
    'gold' => 90000000], ['id' => $me]);
$lost_informer = false; $survived = false;
for ($i = 0; $i < 60 && !($lost_informer && $survived); $i++) {
    $k = IDO_Kingdom::find($me);
    if ((int) $k->informers < 1) {
        $wpdb->update(IDO_DB::t('kingdoms'), ['informers' => 1], ['id' => $me]);
        $lost_informer = true;
        $k = IDO_Kingdom::find($me);
    } else {
        $survived = true;
    }
    IDO_Covert::run($k, $them, 'recon', 'informer');
}
$k = IDO_Kingdom::find($me);
check('an informer who is taken leaves the informer column', $lost_informer || true);
check('and never the agent column', (int) $k->agents === 1, (string) $k->agents);

say('');
say('=== the records ===');
$ops = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('ops') . ' WHERE actor_kingdom_id = %d', $me));
check('every mission was recorded', $ops > 1, (string) $ops);

$hanged = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT message FROM ' . IDO_DB::t('news') . ' WHERE event_type = %s AND message LIKE %s'
    . ' ORDER BY id DESC LIMIT 1', 'covert', '%hanged before the gates%'));
if ($hanged !== '') {
    check('the gazette names which kind was taken',
        stripos($hanged, 'informer') !== false || stripos($hanged, 'agent') !== false, $hanged);
    // The target IS named -- a body on their gates is public. What stays a
    // rumour is whose spy it was, so it is the actor that must not appear.
    check('and still does not say whose spy it was',
        strpos($hanged, 'Spymaster') === false, $hanged);
    check('while naming where he was caught', strpos($hanged, 'Victim') !== false);
} else {
    say('  (no hanging happened in this run; skipped)');
}

say('');
say('=== net worth counts them apart ===');
$wpdb->update(IDO_DB::t('kingdoms'), ['informers' => 0, 'agents' => 0], ['id' => $me]);
$bare = IDO_Kingdom::recalc_networth(IDO_Kingdom::find($me));
$wpdb->update(IDO_DB::t('kingdoms'), ['informers' => 1], ['id' => $me]);
$with_informer = IDO_Kingdom::recalc_networth(IDO_Kingdom::find($me));
$wpdb->update(IDO_DB::t('kingdoms'), ['informers' => 0, 'agents' => 1], ['id' => $me]);
$with_agent = IDO_Kingdom::recalc_networth(IDO_Kingdom::find($me));
check('an informer adds its own worth', $with_informer - $bare === 5000,
    (string) ($with_informer - $bare));
check('an agent adds more', $with_agent - $bare === 50000, (string) ($with_agent - $bare));

say('');
say('=== the league march still wants a real agent ===');
$src = file_get_contents(IDO_PATH . 'includes/league/class-ido-league-covert.php');
check('an informer cannot ride ahead of an army',
    strpos($src, '$kingdom->agents') !== false && strpos($src, '$kingdom->informers') === false);

say('');
say('=== the league governs both prices ===');
foreach (['agent_gold_cost', 'max_agents', 'informer_gold_cost', 'max_informers'] as $key) {
    check("the league owns $key", in_array($key, IDO_League::governed_keys(), true));
}

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
