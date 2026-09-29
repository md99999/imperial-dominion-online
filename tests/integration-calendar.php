<?php
/**
 * Integration test: the league owning the calendar and the rules.
 *
 * Two claims worth proving properly. That every member computes the same season
 * boundary whatever timezone it keeps, because a season ending at each site's
 * local midnight would end up to a day apart and packets would cross between a
 * fresh round and a finished one. And that a site in a league cannot decide the
 * numbers that decide who wins, even by editing its own settings.
 *
 *   php tests/integration-calendar.php /path/to/wordpress [db-host]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

function say(string $line = ''): void { fwrite(STDERR, $line . PHP_EOL); }

$wp_path = $argv[1] ?? getenv('IDO_WP_PATH');
if (!$wp_path) { say('Usage: php tests/integration-calendar.php /path/to/wordpress [db-host]'); exit(2); }
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
$was_settings = get_option(IDO_Settings::OPTION);
$was_tz = get_option('timezone_string');
$was_offset = get_option('gmt_offset');

if (IDO_League::tables_exist()) {
    foreach ((array) $wpdb->get_col('SELECT league_name FROM ' . IDO_DB::t('leagues')) as $name) {
        if (strpos((string) $name, 'Calendar Test') !== 0) {
            say('This site holds a league this test did not create: ' . $name);
            exit(2);
        }
    }
    IDO_League::drop_tables();
}
// This test changes the live round's end date to prove the league overrides it,
// and every later suite reads that same round. An earlier version put it back at
// the end of the body, which stopped happening the moment that block was edited,
// and two other suites started failing for reasons that had nothing to do with
// them. Restoring belongs in the shutdown handler with everything else.
$borrowed_round = IDO_Rounds::current();
$was_ends_at = $borrowed_round ? (string) $borrowed_round->ends_at : null;
$borrowed_id = $borrowed_round ? (int) $borrowed_round->id : 0;

register_shutdown_function(static function () use ($was_settings, $was_tz, $was_offset,
                                                  $borrowed_id, $was_ends_at) {
    global $wpdb;
    if (IDO_League::tables_exist()) IDO_League::drop_tables();
    if ($was_settings === false) { delete_option(IDO_Settings::OPTION); }
    else { update_option(IDO_Settings::OPTION, $was_settings); }
    update_option('timezone_string', $was_tz);
    update_option('gmt_offset', $was_offset);
    if ($borrowed_id > 0 && $was_ends_at !== null) {
        $wpdb->update(IDO_DB::t('rounds'), ['ends_at' => $was_ends_at], ['id' => $borrowed_id]);
    }
    fwrite(STDERR, 'Settings, timezone and round restored.' . PHP_EOL);
});

add_filter('home_url', static function () { return 'https://hub.example.com'; }, 99);
IDO_League_URL::$resolver = static function (): array { return ['93.184.216.34']; };

IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1, 'turns_per_day' => 10, 'turn_cap' => 30]);
IDO_League::forget();
$league = IDO_League_Setup::found(['league_name' => 'Calendar Test ' . wp_rand(100, 999),
                                   'round_days' => 90]);

say('=== the season is computed, not stored ===');
$season = IDO_League::season();
check('there is a season', is_array($season));
check('it is season one on the day the league is founded', $season['season'] === 1,
    (string) $season['season']);
check('it runs the round length', $season['end'] - $season['start'] === 90 * DAY_IN_SECONDS,
    (string) (($season['end'] - $season['start']) / DAY_IN_SECONDS) . ' days');
check('and the league owns the calendar now', IDO_League::owns_calendar());

say('');
say('=== every timezone computes the same instant ===');
// This is the whole point. A season that ended at each member's local midnight
// would end up to a day apart for members in different countries.
$instants = [];
foreach (['UTC', 'America/New_York', 'Asia/Tokyo', 'Pacific/Kiritimati', 'Pacific/Midway'] as $zone) {
    update_option('timezone_string', $zone);
    IDO_League::forget();
    $s = IDO_League::season();
    $instants[$zone] = $s['end'];
}
check('all five zones agree on when the season ends', count(array_unique($instants)) === 1,
    implode(' / ', array_map(static fn($t) => gmdate('Y-m-d H:i', $t), $instants)));
check('and it is a UTC instant, not a local midnight',
    gmdate('H:i', reset($instants)) === gmdate('H:i', strtotime((string) $league->round_starts_at . ' UTC')),
    gmdate('Y-m-d H:i', reset($instants)) . ' UTC');
update_option('timezone_string', 'UTC');
IDO_League::forget();

say('');
say('=== seasons chain without anybody advancing them ===');
// Wind the founding instant back two and a half seasons. A member that had been
// offline for a rollover must still land on the right season, which it cannot do
// if the date has to be advanced by somebody.
$wpdb->update(IDO_DB::t('leagues'),
    ['round_starts_at' => gmdate('Y-m-d H:i:s', time() - (int) (2.5 * 90 * DAY_IN_SECONDS))],
    ['id' => (int) $league->id]);
IDO_League::forget();
$later = IDO_League::season();
check('it is season three', $later['season'] === 3, (string) $later['season']);
check('which has not ended yet', $later['end'] > time());
check('and started before now', $later['start'] < time());
check('the boundaries are exactly a round apart',
    $later['end'] - $later['start'] === 90 * DAY_IN_SECONDS);

// Asking twice never moves it.
$again = IDO_League::season();
check('asking again gives the same answer', $again === $later);

say('');
say('=== the round ends when the season does ===');
$round = IDO_Rounds::current();
if ($round) {
    check('a round mid-season is not expired', !IDO_Rounds::is_expired($round));

    // A local end date in the past no longer ends the round: the league decides.
    $wpdb->update(IDO_DB::t('rounds'),
        ['ends_at' => date('Y-m-d H:i:s', current_time('timestamp') - 86400)], ['id' => (int) $round->id]);
    $round = IDO_Rounds::current();
    check('a local end date is ignored while the league owns the calendar',
        !IDO_Rounds::is_expired($round));

    // A new season having begun does end it. Note what is being asked: not
    // whether the season is over, which is never true because seasons chain, but
    // whether this round belongs to a season that has passed.
    $wpdb->update(IDO_DB::t('leagues'),
        ['round_starts_at' => gmdate('Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS)],
        ['id' => (int) $league->id]);
    IDO_League::forget();
    $new_season = IDO_League::season();
    check('a new season has begun', $new_season['season'] === 2, (string) $new_season['season']);
    check('and the round from the old one is expired', IDO_Rounds::is_expired($round));
    check('the season itself is not over, because it just started',
        $new_season['end'] > time());

    // A round that began *inside* the current season is not expired. Note what
    // the failing version of this check assumed: that putting the season start
    // back to yesterday would settle it. It does not, because this site's round
    // began weeks before that, so it belongs to an earlier season and correctly
    // expires. That is how a site joining a league mid-round adopts the shared
    // calendar: its own round ends at the next tick and the next one is aligned.
    $round_started = strtotime(get_gmt_from_date((string) $round->starts_at) . ' UTC');
    $wpdb->update(IDO_DB::t('leagues'),
        ['round_starts_at' => gmdate('Y-m-d H:i:s', $round_started - DAY_IN_SECONDS)],
        ['id' => (int) $league->id]);
    IDO_League::forget();
    check('a round that began inside the season is not expired',
        !IDO_Rounds::is_expired(IDO_Rounds::current()));
    check('and a site joining mid-round adopts the calendar at the next tick',
        IDO_League::season()['start'] < $round_started,
        gmdate('Y-m-d H:i', IDO_League::season()['start']) . ' vs ' . gmdate('Y-m-d H:i', $round_started));
} else {
    say('SKIP  no round is running on this site.');
}

say('');
say('=== the league owns the numbers that decide who wins ===');
check('turns a day is the league\'s', IDO_League::governs('turns_per_day'));
check('and starting gold', IDO_League::governs('starting_gold'));
check('and the combat percentages', IDO_League::governs('conquest_land_percent'));
check('but not the world name', !IDO_League::governs('dominion_name'));
check('nor whether new empires may be founded', !IDO_League::governs('allow_new_kingdoms'));
check('nor the league switch itself', !IDO_League::governs('league_enabled'));

say('');
say('=== a member cannot simply give itself more turns ===');
$before = IDO_Settings::int('turns_per_day');
IDO_Settings::update(['turns_per_day' => 200]);
check('the setting does not move', IDO_Settings::int('turns_per_day') === $before,
    IDO_Settings::int('turns_per_day') . ' vs ' . $before);

// And what the site keeps for itself is still editable.
IDO_Settings::update(['dominion_name' => 'Editable']);
check('an ungoverned setting still saves', IDO_Settings::get('dominion_name') === 'Editable');

// The raw row is untouched by the overlay, so leaving the league gives the game
// master back whatever they had.
$raw = get_option(IDO_Settings::OPTION);
check('the site\'s own row is not overwritten by the league',
    is_array($raw) && (int) ($raw['turns_per_day'] ?? 0) === $before);

say('');
say('=== a joining member waits for a boundary ===');
IDO_League::drop_tables();
IDO_League_Setup::opt_in();
IDO_Settings::update(['league_endpoint' => 1]);
IDO_League::forget();

$hub_token = bin2hex(random_bytes(32));
IDO_League_Setup::join(IDO_League_Crypto::b64_encode((string) wp_json_encode([
    'v' => 1, 'lid' => IDO_League_Crypto::uuid(), 'name' => 'Calendar Test Far',
    'hub' => 'https://far.example.com', 'token' => $hub_token,
])));
IDO_League::forget();
$joining = IDO_League::league();
$wpdb->update(IDO_DB::t('leagues'), [
    'status' => 'active', 'paused' => 0,
    'ruleset' => wp_json_encode(['turns_per_day' => 99, 'starting_gold' => 1]),
    'round_starts_at' => IDO_League::now(), 'round_days' => 90,
], ['id' => (int) $joining->id]);
IDO_League::forget();

check('the league has published a ruleset', !empty(IDO_League::league()->ruleset));
check('but nothing is in force yet', IDO_League::settings_in_force() === []);
check('so the site still plays its own numbers', IDO_Settings::int('turns_per_day') === $before,
    (string) IDO_Settings::int('turns_per_day'));
check('and no setting reads as the league\'s', !IDO_League::governs('turns_per_day'));

IDO_League::apply_ruleset();
IDO_League::forget();
check('after a boundary the ruleset is in force', IDO_League::settings_in_force() !== []);
check('and the league\'s numbers are the ones played',
    IDO_Settings::int('turns_per_day') === 99, (string) IDO_Settings::int('turns_per_day'));
check('including the ones a site would most like to keep',
    IDO_Settings::int('starting_gold') === 1, (string) IDO_Settings::int('starting_gold'));
check('now the screen shows them as the league\'s', IDO_League::governs('turns_per_day'));

say('');
say('=== leaving gives the site its own numbers back ===');
IDO_League_Setup::leave();
IDO_League::forget();
check('the overlay is gone', IDO_League::settings_in_force() === []);
check('and the site plays what it had', IDO_Settings::int('turns_per_day') === $before,
    (string) IDO_Settings::int('turns_per_day'));

say('');
say($fails === 0 ? 'ALL CHECKS PASSED' : "$fails CHECK(S) FAILED");
exit($fails === 0 ? 0 : 1);
