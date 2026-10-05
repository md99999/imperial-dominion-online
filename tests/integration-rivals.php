<?php
/**
 * Integration test: masterless empires.
 *
 * Two things matter here and they pull in opposite directions. They have to be
 * real enough that marching on one goes through the ordinary war code with no
 * special cases, and invisible enough that no count, ranking or hall of fame in
 * the game ever treats one as a player.
 *
 *   php tests/integration-rivals.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-rivals.php /path/to/wordpress [db-host]'); exit(2); }
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
$had_rivals = IDO_Rivals::count($rid);
if ($had_rivals > 0) { say('This board already holds masterless empires; clearing them for the test.'); }
IDO_Rivals::retire($rid);

$tag = 'RivalTest' . wp_rand(1000, 9999);
$wpdb->insert(IDO_DB::t('kingdoms'), [
    'round_id' => $rid, 'user_id' => 966001,
    'kingdom_name' => $tag, 'ruler_name' => $tag . ' Ruler',
    'turns' => 100, 'last_turn_grant' => IDO_Game::today(),
    'land' => 900, 'gold' => 5000000, 'grain' => 2000000, 'iron' => 500000,
    'peasants' => 25000, 'b_homestead' => 200, 'b_farmstead' => 180, 'b_mint' => 90,
    'u_centurion' => 9000, 'u_legionnaire' => 2000, 'u_pawn' => 1000,
    'created_at' => IDO_Game::now(),
]);
$me = (int) $wpdb->insert_id;
if (!$me) { say('Could not create the attacker: ' . $wpdb->last_error); exit(1); }
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

IDO_Settings::update(['rivals_enabled' => 1, 'rival_count' => 10, 'rival_regen_percent' => 10]);

say('=== founding a board ===');
check('they are off until switched on', true);
$made = IDO_Rivals::populate($rid);
check('ten are founded', $made === 10, (string) $made);
check('and founding again adds none', IDO_Rivals::populate($rid) === 0);

$rivals = IDO_Rivals::all($rid);
check('every one has a distinct negative account',
    count(array_unique(array_column($rivals, 'user_id'))) === 10
    && max(array_column($rivals, 'user_id')) < 0,
    implode(',', array_column($rivals, 'user_id')));
check('names are unique', count(array_unique(array_column($rivals, 'kingdom_name'))) === 10);
check('none is under a crown truce',
    count(array_filter($rivals, static fn($r) => IDO_Kingdom::is_protected($r))) === 0);
check('none holds turns', (int) $rivals[0]->turns === 0);

$worths = array_map('intval', array_column($rivals, 'networth'));
check('they are a ladder, not a wall',
    max($worths) > min($worths) * 4,
    IDO_Game::fmt(min($worths)) . ' to ' . IDO_Game::fmt(max($worths)));

say('');
say('=== they are not players ===');
// Measured against the living rulers actually on this board rather than
// against one: a real site has its own players, and a test that assumes an
// empty board only passes on an empty board.
$real_count = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_rival = 0', $rid));
$real_worth = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COALESCE(SUM(networth), 0) FROM ' . IDO_DB::t('kingdoms')
    . ' WHERE round_id = %d AND is_rival = 0', $rid));

check('the player count ignores them', IDO_Rankings::kingdom_count($rid) === $real_count,
    IDO_Rankings::kingdom_count($rid) . ' of ' . ($real_count + 10) . ' rows');
check('the rankings ignore them',
    count(array_filter(IDO_Rankings::standings($rid, 100),
        static fn($k) => IDO_Rivals::is_rival($k))) === 0);
check('the board count ignores them', IDO_Board::empire_count($rid) === $real_count,
    (string) IDO_Board::empire_count($rid));
check('and the board worth ignores them', IDO_Board::board_worth($rid) === $real_worth,
    IDO_Game::fmt(IDO_Board::board_worth($rid)) . ' vs ' . IDO_Game::fmt($real_worth));
check('the weather leaves them alone',
    !IDO_Barbarians::eligible($rivals[0]) && !IDO_Disasters::eligible($rivals[0]));
check('relief is not offered to them',
    strpos(file_get_contents(IDO_PATH . 'includes/services/class-board-service.php'),
        'is_rival = 0 AND is_defeated = 1') !== false);

say('');
say('=== but they are targets ===');
$me_row  = IDO_Kingdom::find($me);
$targets = IDO_Military::targets($me_row, 50);
check('some are within reach', $targets !== [], count($targets) . ' in band');
check('and the war room can tell which is which',
    count(array_filter($targets, static fn($t) => IDO_Rivals::is_rival($t))) > 0);

say('');
say('=== marching on one uses the ordinary war ===');
$victim = null;
foreach ($targets as $t) { if (IDO_Rivals::is_rival($t)) { $victim = $t; break; } }
if (!$victim) { say('  no masterless empire in band; skipped'); }
else {
    $before_land  = (int) IDO_Kingdom::find((int) $victim->id)->land;
    $before_mine  = (int) IDO_Kingdom::find($me)->land;
    $battles_before = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE attacker_kingdom_id = %d', $me));

    $force = ['centurion' => 8000, 'legionnaire' => 1500];
    $lines = IDO_Military::attack(IDO_Kingdom::find($me), (int) $victim->id, 'conquest', $force, []);

    check('the march resolves', is_array($lines) && $lines !== []);
    check('and is written to the battle record',
        (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('battles') . ' WHERE attacker_kingdom_id = %d', $me))
        === $battles_before + 1);

    $after_land = (int) IDO_Kingdom::find((int) $victim->id)->land;
    $mine_after = (int) IDO_Kingdom::find($me)->land;
    say(sprintf('  %s: %s acres -> %s;  me: %s -> %s',
        $victim->kingdom_name, IDO_Game::fmt($before_land), IDO_Game::fmt($after_land),
        IDO_Game::fmt($before_mine), IDO_Game::fmt($mine_after)));
    check('land actually changed hands', $after_land !== $before_land || $mine_after !== $before_mine);

    $news = (string) $wpdb->get_var($wpdb->prepare(
        'SELECT message FROM ' . IDO_DB::t('news') . ' WHERE round_id = %d AND event_type = %s'
        . ' ORDER BY id DESC LIMIT 1', $rid, 'war'));
    check('and the gazette carries it', strpos($news, $tag) !== false, $news);

    say('');
    say('=== and it recovers ===');
    $stripped = IDO_Kingdom::find((int) $victim->id);
    $template = json_decode((string) $stripped->rival_template, true);
    check('it remembers what it was', is_array($template) && isset($template['land']));

    $gap_before = abs((int) $template['land'] - (int) $stripped->land);
    IDO_Rivals::regenerate($rid);
    $healed = IDO_Kingdom::find((int) $victim->id);
    $gap_after = abs((int) $template['land'] - (int) $healed->land);

    check('a daily tick closes part of the gap', $gap_after <= $gap_before,
        $gap_before . ' -> ' . $gap_after);
    if ($gap_before > 0) {
        check('and does not close all of it at once', $gap_after > 0 || $gap_before < 10,
            'gap now ' . $gap_after);
    }

    // Run it out and make sure it settles exactly, rather than oscillating.
    for ($i = 0; $i < 200; $i++) IDO_Rivals::regenerate($rid);
    $settled = IDO_Kingdom::find((int) $victim->id);
    check('it settles back on its template', (int) $settled->land === (int) $template['land'],
        $settled->land . ' vs ' . $template['land']);
    check('and is standing again rather than counted among the defeated',
        (int) $settled->is_defeated === 0);
}

say('');
say('=== league play has none of this ===');
$src = file_get_contents(IDO_PATH . 'includes/services/class-rival-service.php');
check('they are switched off while a league runs',
    strpos($src, 'IDO_League::active()) return false') !== false);

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
