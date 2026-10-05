<?php
/**
 * Integration test: masterless empires striking back.
 *
 * The rule being pinned is that nothing here is a special case. A province
 * marches through exactly the same attack() a ruler marches through, and is
 * held to the crown truce, the net worth band and the daily limit on hitting one
 * target in exactly the same way. The only thing it is handed is the turns.
 *
 * And the negative that matters most: a province that nobody has attacked must
 * never march on anybody.
 *
 *   php tests/integration-retaliation.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-retaliation.php /path/to/wordpress [db-host]'); exit(2); }
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

global $wpdb;
$round = IDO_Rounds::current();
if (!$round) { say('No round is running.'); exit(1); }
$rid = (int) $round->id;

$was_settings = get_option(IDO_Settings::OPTION);
IDO_Rivals::retire($rid);

$tag = 'Retal' . wp_rand(1000, 9999);
$wpdb->insert(IDO_DB::t('kingdoms'), [
    'round_id' => $rid, 'user_id' => 955001,
    'kingdom_name' => $tag, 'ruler_name' => $tag . ' Ruler',
    'turns' => 200, 'last_turn_grant' => IDO_Game::today(),
    'land' => 900, 'gold' => 5000000, 'grain' => 3000000, 'iron' => 500000,
    'peasants' => 25000, 'b_homestead' => 200, 'b_farmstead' => 200, 'b_fortification' => 60,
    'u_centurion' => 9000, 'u_legionnaire' => 4000, 'u_pawn' => 4000,
    'created_at' => IDO_Game::now(),
]);
$me = (int) $wpdb->insert_id;
if (!$me) { say('Could not create the ruler: ' . $wpdb->last_error); exit(1); }
IDO_Kingdom::recalc_networth(IDO_Kingdom::find($me));

register_shutdown_function(static function () use ($me, $rid, $was_settings, $tag) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('battles')
        . ' WHERE attacker_kingdom_id = %d OR defender_kingdom_id = %d', $me, $me));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . IDO_DB::t('news') . ' WHERE message LIKE %s', '%' . $tag . '%'));
    $wpdb->delete(IDO_DB::t('kingdoms'), ['id' => $me]);
    IDO_Rivals::retire($rid);
    if ($was_settings === false) { delete_option(IDO_Settings::OPTION); }
    else { update_option(IDO_Settings::OPTION, $was_settings); }
});

IDO_Settings::update([
    'rivals_enabled' => 1, 'rival_count' => 10, 'rival_regen_percent' => 10,
    'rival_retaliation' => 1, 'rival_memory_days' => 3, 'rival_attack_chance' => 100,
]);
IDO_Rivals::populate($rid);

say('=== an unprovoked province never marches ===');
$before = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));
for ($i = 0; $i < 5; $i++) IDO_Rivals::retaliate($rid);
$after = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));
check('five nights at full odds and nobody was touched', $after === $before,
    ($after - $before) . ' battle(s)');
check('because nothing holds a grudge',
    array_sum(array_map(static fn($r) => count(IDO_Rivals::grudges($r)), IDO_Rivals::all($rid))) === 0);

say('');
say('=== provoke one ===');
$victim = null;
foreach (IDO_Military::targets(IDO_Kingdom::find($me), 50) as $t) {
    if (IDO_Rivals::is_rival($t)) { $victim = $t; break; }
}
if (!$victim) { say('No masterless empire in band; cannot provoke.'); exit(2); }

IDO_Military::attack(IDO_Kingdom::find($me), (int) $victim->id, 'raid',
    ['centurion' => 4000, 'legionnaire' => 1000], []);

$provoked = IDO_Kingdom::find((int) $victim->id);
check('it now holds a grudge, and against me',
    in_array((string) $me, array_map('strval', IDO_Rivals::grudges($provoked)), true),
    implode(',', IDO_Rivals::grudges($provoked)));
check('and the others still hold none',
    array_sum(array_map(static fn($r) => count(IDO_Rivals::grudges($r)),
        array_filter(IDO_Rivals::all($rid),
            static fn($r) => (int) $r->id !== (int) $victim->id))) === 0);

say('');
say('=== and it marches back ===');
$before = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));
$result = IDO_Rivals::retaliate($rid);
$after = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));

say(sprintf('  marched %d, refused %d', $result['marched'], $result['refused']));
check('a march was carried out against me', $after > $before, ($after - $before) . ' battle(s)');

$battle = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d ORDER BY id DESC LIMIT 1', $me));
check('the attacker was the province I provoked',
    $battle && (int) $battle->attacker_kingdom_id === (int) $victim->id);
check('and there is a report waiting for me to read',
    $battle && trim((string) $battle->defender_report) !== '',
    $battle ? substr(str_replace("\n", ' / ', $battle->defender_report), 0, 90) : '');
check('with an outcome recorded', $battle && in_array($battle->outcome, ['victory', 'repelled'], true),
    $battle->outcome ?? '');

$news = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT message FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s'
    . ' ORDER BY id DESC LIMIT 1', $rid, 'war'));
check('and the gazette carries it', strpos($news, $victim->kingdom_name) !== false, $news);

say('');
say('=== it keeps none of the turns it was handed ===');
check('the province holds no turns afterwards',
    (int) IDO_Kingdom::find((int) $victim->id)->turns === 0,
    (string) IDO_Kingdom::find((int) $victim->id)->turns);

say('');
say('=== nobody is set upon twice in one night ===');
// Several grudges, one night: still one march against this ruler.
foreach (IDO_Rivals::all($rid) as $r) {
    $wpdb->insert(IDO_DB::t('battles'), [
        'round_id' => $rid, 'attacker_kingdom_id' => $me, 'defender_kingdom_id' => (int) $r->id,
        'attack_type' => 'raid', 'outcome' => 'victory', 'created_at' => IDO_Game::now(),
    ]);
}
$before = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));
IDO_Rivals::retaliate($rid);
$after = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));
check('ten provoked provinces, one march', $after - $before <= 1, ($after - $before) . ' battle(s)');

say('');
say('=== a grudge expires ===');
$wpdb->query($wpdb->prepare(
    'UPDATE ' . IDO_DB::t('battles') . ' SET created_at = %s WHERE attacker_kingdom_id = %d',
    date('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS), $me));
check('after the memory window, nobody remembers',
    array_sum(array_map(static fn($r) => count(IDO_Rivals::grudges($r)), IDO_Rivals::all($rid))) === 0);

say('');
say('=== a ruler under truce is left alone ===');
$wpdb->query($wpdb->prepare(
    'UPDATE ' . IDO_DB::t('battles') . ' SET created_at = %s WHERE attacker_kingdom_id = %d',
    IDO_Game::now(), $me));
$wpdb->update(IDO_DB::t('kingdoms'),
    ['protection_until' => date('Y-m-d H:i:s', current_time('timestamp') + 86400)], ['id' => $me]);
$before = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));
for ($i = 0; $i < 3; $i++) IDO_Rivals::retaliate($rid);
$after = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE defender_kingdom_id = %d', $me));
check('three nights under the crown truce and nothing came', $after === $before,
    ($after - $before) . ' battle(s)');

say('');
say('=== and the whole thing is off by default ===');
IDO_Settings::update(['rival_retaliation' => 0]);
check('switched off, nothing marches', IDO_Rivals::retaliate($rid)['marched'] === 0);
check('and it is off out of the box',
    (int) (IDO_Settings::defaults()['rival_retaliation'] ?? 1) === 0);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
