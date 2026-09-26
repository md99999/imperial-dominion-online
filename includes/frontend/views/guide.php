<?php
/**
 * How to Play. Every number on this page is read from live settings and from
 * the building and troop tables, so the rules a player reads are the rules the
 * game is actually running. Change a setting and this page changes with it.
 */
if (!defined('ABSPATH')) exit;

$s          = IDO_Settings::all();
$buildings  = IDO_Buildings::all();
$units      = IDO_Units::all();
$attacks    = IDO_Military::attack_types();
$ops        = IDO_Covert::ops();
$agent      = IDO_Covert::agent_cost();
$round      = IDO_Rounds::current();
$has_kingdom = !empty($kingdom);

// A local copy: reset() and end() take their argument by reference, which a
// class constant cannot satisfy.
$titles       = IDO_Game::TITLES;
$lowest_title = reset($titles);
$highest_title = end($titles);
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">The goal</h3>
    <p>
        You rule one empire in <strong><?php echo esc_html(IDO_Game::dominion()); ?></strong>, among every other
        empire on this site. A round runs <strong><?php echo esc_html((string) $s['round_days']); ?> days</strong>.
        When it ends, the empire with the highest <strong>net worth</strong> is champion, the standings are carved
        into the Hall of Fame, and everyone begins again on equal ground.
    </p>
    <p>
        Net worth counts everything you hold: land, buildings, peasants, your army and your treasury. There is no
        way to win by hiding. A large, rich, undefended empire is simply a target, and the ranking that makes you
        proud is the same ranking that tells your neighbours what you are worth taking.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">What you are trying to do</h3>
    <ul class="ido-list">
        <li><strong>Claim land.</strong> Send settlers into the wilderness. Every acre is somewhere to build.</li>
        <li><strong>Build on it.</strong> Buildings are the only things that produce anything. Bare land is worth little.</li>
        <li><strong>Feed your people.</strong> Peasants pay your taxes and become your soldiers. Starve them and you lose both.</li>
        <li><strong>Raise an army.</strong> Troops to hold what is yours, and troops to take what is not.</li>
        <li><strong>Trade.</strong> Other rulers sell what you are short of, at prices they set themselves.</li>
        <li><strong>Make war.</strong> Past a certain size, taking a neighbour's acres is cheaper than settling your own.</li>
        <li><strong>Climb.</strong> Rise through the titles, from <?php echo esc_html($lowest_title); ?>
            to <?php echo esc_html($highest_title); ?>.</li>
    </ul>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Turns: the thing you actually spend</h3>
    <p>
        Everything costs turns. You are granted <strong><?php echo esc_html((string) $s['turns_per_day']); ?> turns
        a day</strong> and can store up to <strong><?php echo esc_html((string) $s['turn_cap']); ?></strong>.
    </p>
    <p>
        Here is the part that catches new rulers out: <strong>a turn pays you the moment you spend it</strong>.
        Your gold, grain and iron arrive as income when you give an order, not while you sit idle. Turns left
        unspent earn you nothing at all. A ruler who logs in daily and spends everything will out-produce one who
        hoards, every time.
    </p>
    <table class="ido-table">
        <thead><tr><th>Order</th><th class="ido-right">Turns</th></tr></thead>
        <tbody>
            <tr><td>Settle new land</td><td class="ido-right">1</td></tr>
            <tr><td>Order buildings (any quantity)</td><td class="ido-right">1</td></tr>
            <tr><td>Train troops (any quantity)</td><td class="ido-right">1</td></tr>
            <tr><td>March on an empire</td><td class="ido-right"><?php echo esc_html((string) $s['attack_turn_cost']); ?></td></tr>
            <tr><td>Send an agent</td><td class="ido-right"><?php echo esc_html((string) $s['op_turn_cost']); ?></td></tr>
            <tr><td>Post or buy on the market</td><td class="ido-right">0</td></tr>
        </tbody>
    </table>
    <p class="ido-dim">
        Because an order of any size costs one turn, ordering 200 buildings at once costs exactly what ordering one
        does. Place large orders.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Your first day, step by step</h3>
    <ol class="ido-list">
        <li>Claim your empire. You start with <?php echo esc_html(IDO_Game::fmt($s['starting_land'])); ?> acres,
            <?php echo esc_html(IDO_Game::fmt($s['starting_gold'])); ?> gold, and a crown truce of
            <?php echo esc_html((string) $s['protection_hours']); ?> hours that nobody can break.</li>
        <li>Open <strong>Lands</strong> and look at your wilderness: acres with nothing on them, earning nothing.</li>
        <li><strong>Build farmsteads and homesteads first.</strong> Food and people come before everything. Check the
            Empire screen afterwards: if grain per turn is negative, you are heading for starvation.</li>
        <li>Spend a turn or two <strong>settling more land</strong>, then build on that too.</li>
        <li>Open <strong>Muster</strong> and train defenders. Your truce ends, and an undefended empire with a good
            net worth is the most attractive target on the board.</li>
        <li>Spend every remaining turn. Come back tomorrow.</li>
    </ol>
    <p class="ido-dim">
        A reasonable opening is roughly a quarter of your land in farmsteads, a quarter in homesteads, and the rest
        split between mints, foundries and defence. There is no correct answer, which is the point.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Land and buildings</h3>
    <p>
        Each building occupies one acre. Ordering costs
        <strong><?php echo esc_html(IDO_Game::fmt($s['build_gold_per_acre'])); ?> gold</strong> and
        <strong><?php echo esc_html(IDO_Game::fmt($s['build_iron_per_acre'])); ?> iron</strong> per acre, and the work
        finishes on the daily tick<?php if ((int) $s['build_days'] > 0) : ?>,
        <?php echo esc_html(sprintf(_n('%d day later', '%d days later', (int) $s['build_days'], 'imperial-dominion-online'), (int) $s['build_days'])); ?><?php endif; ?>.
        What you order today defends you tomorrow, never tonight.
    </p>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Building</th><th>What it does</th></tr></thead>
        <tbody>
        <?php foreach ($buildings as $building) : ?>
            <tr>
                <td><?php echo esc_html($building['plural']); ?></td>
                <td class="ido-dim"><?php echo esc_html($building['effect']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="ido-dim">
        Settling gets harder as you grow: a scouting party finds fewer acres and charges more for each one. Demolishing a
        building returns <?php echo esc_html((string) $s['demolish_refund_percent']); ?>% of its cost and hands the acre
        back to wilderness.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">The siege yards</h3>
    <p>
        Siege engines are the third thing an empire can own, and they behave like neither of the other two.
        They are built rather than trained, so no peasant leaves the fields for one, and they stand on no acre,
        so they cost you nothing in land. An order costs one turn and finishes on the daily tick, the same as
        a building does.
    </p>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Engine</th><th class="ido-right">Offence</th><th class="ido-right">Defence</th><th class="ido-right">Cost each</th><th>What it does</th></tr></thead>
        <tbody>
        <?php foreach (IDO_Engines::all() as $engine_key => $engine) :
            $engine_cost = IDO_Engines::cost($engine_key); ?>
            <tr>
                <td><?php echo esc_html($engine['plural']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $engine['offence']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $engine['defence']); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($engine_cost['gold'])); ?>g
                    <span class="ido-dim">+ <?php echo esc_html(IDO_Game::fmt($engine_cost['iron'])); ?> iron</span></td>
                <td class="ido-dim"><?php echo esc_html($engine['note']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>
        What an engine costs you is risk. Catapults are the only part of your strength an enemy can take
        away and keep: everything else they destroy, these they drag home. The winner of a battle carries off
        <strong><?php echo esc_html((string) $s['catapult_capture_percent']); ?>%</strong> of whatever the loser
        had at stake, and a further <strong><?php echo esc_html((string) $s['catapult_destroy_percent']); ?>%</strong>
        is smashed on the field, so losing a fight costs
        <strong><?php echo esc_html((string) ((int) $s['catapult_capture_percent'] + (int) $s['catapult_destroy_percent'])); ?>%</strong>
        of what was committed to it.
    </p>
    <ul class="ido-list">
        <li>When you <strong>attack</strong>, your stake is the train you send and nothing else. Engines left
            at home are not in the wager.</li>
        <li>When you are <strong>attacked</strong>, your stake is every engine you own, because every engine
            you own is on the wall.</li>
        <li>So a large train wins fights you would otherwise lose, and hands a rival a siege park if it does not.
            That is the whole decision, and there is no safe answer to it.</li>
    </ul>
    <p class="ido-dim">
        Scrapping an engine returns <?php echo esc_html((string) $s['demolish_refund_percent']); ?>% of its cost,
        the same as demolishing a building.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">People and food</h3>
    <p>
        Peasants pay your taxes, and every soldier you train is a peasant taken out of the fields. Homesteads set how
        many you can house; your population grows toward that ceiling on its own.
    </p>
    <p class="ido-warning">
        If your grain runs out, peasants flee and troops desert, every single turn you spend. Watch the grain line in
        the Empire screen. If it is negative, build farmsteads or buy grain on the market before you spend anything else.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">The army</h3>
    <p>
        Offence counts only when you attack. Defence counts only when you are attacked. An army built for one job is
        nearly useless at the other, and that trade-off is the whole war game.
    </p>
    <table class="ido-table ido-table-wide">
        <thead>
            <tr><th>Troops</th><th class="ido-right">Offence</th><th class="ido-right">Defence</th>
                <th class="ido-right">Gold</th><th class="ido-right">Iron</th><th>Notes</th></tr>
        </thead>
        <tbody>
        <?php foreach ($units as $unit) : ?>
            <tr>
                <td><?php echo esc_html($unit['plural']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $unit['offence']); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $unit['defence']); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($unit['gold'])); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($unit['iron'])); ?></td>
                <td class="ido-dim"><?php echo esc_html($unit['note']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">War</h3>
    <p>
        A march costs <strong><?php echo esc_html((string) $s['attack_turn_cost']); ?> turns</strong> and resolves the
        instant you commit, against whatever the defender has standing at that moment. There is no waiting and no
        warning. Both sides receive a written report.
    </p>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Kind of attack</th><th>What it takes</th></tr></thead>
        <tbody>
        <?php foreach ($attacks as $attack) : ?>
            <tr>
                <td><?php echo esc_html($attack['label']); ?></td>
                <td class="ido-dim"><?php echo esc_html($attack['note']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <h4>The rules of engagement</h4>
    <ul class="ido-list">
        <li>You may only attack empires worth between
            <strong><?php echo esc_html((string) $s['target_min_percent']); ?>%</strong> and
            <strong><?php echo esc_html((string) $s['target_max_percent']); ?>%</strong> of your own net worth.
            Nobody can farm a beginner, and nobody is safe purely by being large.</li>
        <li>You may strike the same empire at most
            <strong><?php echo esc_html((string) $s['max_hits_per_target']); ?></strong> times a day.</li>
        <li>New empires hold a crown truce for
            <strong><?php echo esc_html((string) $s['protection_hours']); ?> hours</strong>. It ends the moment they
            march on somebody, so you cannot raid from behind it.</li>
        <li>Losses are permanent on both sides. Winning a battle still costs you soldiers.</li>
        <li>Fortifications lift your defence; ballistae legions are built to break through them.</li>
        <li>Catapults are lost to the winner rather than merely killed. Whatever kind of attack it was,
            the engines at stake change hands on the result.</li>
    </ul>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">The spy court</h3>
    <p>
        An agent costs <strong><?php echo esc_html(IDO_Game::fmt($agent['gold'])); ?> gold</strong>, and no ruler may
        keep more than <strong><?php echo esc_html((string) max(1, (int) $s['max_agents'])); ?></strong>. That price is
        deliberate: knowing what a rival actually holds before you commit an army should be a serious investment, not
        a habit. Missions can fail, and a failed mission often ends with your agent hanged, which means paying the
        full price again.
    </p>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Mission</th><th class="ido-right">Gold</th><th class="ido-right">Success</th><th class="ido-right">Risk</th><th>What it does</th></tr></thead>
        <tbody>
        <?php foreach ($ops as $op) : ?>
            <tr>
                <td><?php echo esc_html($op['label']); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($op['gold'])); ?></td>
                <td class="ido-right"><?php echo esc_html((string) $op['chance']); ?>%</td>
                <td class="ido-right"><?php echo esc_html((string) $op['risk']); ?>%</td>
                <td class="ido-dim"><?php echo esc_html($op['note']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">The market</h3>
    <p>
        Rulers set their own prices. Post grain, iron or troops for sale and name what you want for them; the goods
        leave your stores at once and come back if the lot expires or you withdraw it. Buying and selling cost no
        turns, so the market is the one place you can act freely when your turns are gone.
    </p>
    <p class="ido-dim">
        The crown takes <?php echo esc_html((string) $s['market_tax_percent']); ?>% of every sale. Lots expire after
        <?php echo esc_html(sprintf(_n('%d day', '%d days', (int) $s['listing_days'], 'imperial-dominion-online'), (int) $s['listing_days'])); ?>,
        and you may keep <?php echo esc_html((string) $s['max_listings_per_kingdom']); ?> open at a time. An empire
        that farms grain well and sells the surplus can fund an army it could never have trained on its own.
    </p>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Titles</h3>
    <p class="ido-dim">Your title follows your net worth. It is worth nothing in itself, and everything to look at.</p>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Title</th><th class="ido-right">Net worth</th></tr></thead>
        <tbody>
        <?php foreach (IDO_Game::TITLES as $threshold => $title) : ?>
            <tr>
                <td><?php echo esc_html($title); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($threshold)); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="ido-panel">
    <h3 class="ido-panel-title">Rounds</h3>
    <p>
        A round lasts <strong><?php echo esc_html((string) $s['round_days']); ?> days</strong><?php
        if ($round && IDO_Rounds::days_left($round) !== null) {
            $left = (int) IDO_Rounds::days_left($round);
            echo '. ' . esc_html(sprintf(
                '%s is running now, with %s.',
                $round->round_name,
                sprintf(_n('%d day left', '%d days left', $left, 'imperial-dominion-online'), $left)
            ));
        } else {
            echo '.';
        }
        ?>
        When the clock runs out the standings are archived to the Hall of Fame, every empire is retired, and a new
        round opens. Nobody carries an advantage across, so arriving late in a round costs you nothing but this round.
    </p>
</div>

<?php if (!$has_kingdom) : ?>
    <div class="ido-panel">
        <h3 class="ido-panel-title">Take an empire</h3>
        <?php if (!is_user_logged_in()) : ?>
            <p>Sign in to claim your empire.</p>
            <p>
                <a class="ido-btn" href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">Sign in</a>
                <?php if (get_option('users_can_register')) : ?>
                    <a class="ido-btn ido-btn-alt" href="<?php echo esc_url(wp_registration_url()); ?>">Register</a>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <p><a class="ido-btn" href="<?php echo esc_url(IDO_UI::url('empire')); ?>">Claim your empire</a></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
