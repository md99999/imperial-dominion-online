<?php
if (!defined('ABSPATH')) exit;

/**
 * Masterless empires: provinces of the old empire that no living ruler holds.
 *
 * A board with three players is a board with nothing to march on, and the game
 * is about marching. These fill the map: real rows in the empires table, with
 * land, granaries, walls and an army, so that attacking one goes through exactly
 * the same combat, the same spoils, the same catapult wager and the same written
 * report as attacking a person. Not one line of new war code, which is the whole
 * reason they are rows rather than a table of monsters.
 *
 * Three rules shape them.
 *
 * **They are not players.** Every count, ranking and hall of fame in this game
 * means living rulers, so a masterless empire is excluded from all of them. Left
 * in, ten of these would switch the barbarians on for a two-player board, crown
 * one of them the leader in the gazette, and keep a board that every real ruler
 * had abandoned from ever looking ruined.
 *
 * **They are spread across a ladder, not all mature.** A march is only allowed
 * against an empire worth between 40% and 250% of your own, so a field of
 * uniformly powerful empires would be invisible to everybody who is not already
 * powerful -- which is exactly the players this exists for.
 *
 * **They recover.** Each one remembers the empire it was built to be, and the
 * daily tick closes a share of the gap. That makes farming one worth less each
 * time without any rule saying so, because spoils are a share of what is there.
 *
 * Off by default, and never present in league play, where there is no war within
 * a site at all.
 */
class IDO_Rivals {

    /** The most a board may hold, whatever the setting says. */
    const MAX = 50;

    /**
     * The empire a masterless province is built to be, at strength 1.0.
     *
     * Coded rather than settable. These are the shape of the thing, not a
     * balance lever: the levers are how many there are and how fast they
     * recover. Roughly a well-played empire a few weeks into a round.
     */
    public static function profile(): array {
        // Keyed off the data classes rather than written out, so a column name
        // cannot drift from the schema. Writing them by hand cost an afternoon:
        // the ballista legion's column is u_ballista_legion, not u_ballista, and
        // every insert failed on it.
        $profile = [
            'land'     => 600,
            'peasants' => 12000,
            'gold'     => 400000,
            'grain'    => 300000,
            'iron'     => 60000,
        ];

        $buildings = ['homestead' => 150, 'farmstead' => 140, 'mint' => 70,
                      'foundry' => 55, 'barracks' => 20, 'fortification' => 45];
        foreach ($buildings as $key => $n) {
            if (IDO_Buildings::exists($key)) $profile[IDO_Buildings::column($key)] = $n;
        }

        $units = ['pawn' => 800, 'legionnaire' => 400, 'centurion' => 150, 'ballista_legion' => 60];
        foreach ($units as $key => $n) {
            if (IDO_Units::exists($key)) $profile[IDO_Units::column($key)] = $n;
        }

        foreach (IDO_Weapons::keys() as $key) {
            $profile[IDO_Weapons::column($key)] = 10;
        }

        return $profile;
    }

    /**
     * The ladder, weakest first.
     *
     * Five rungs rather than one level, so that a ruler on their third day and a
     * ruler on their thirtieth both have somebody in reach. The weakest rung is
     * deliberately beatable by an empire that has done little more than settle.
     */
    public static function tiers(): array {
        return [0.3, 0.6, 1.0, 1.6, 2.4];
    }

    /** Whether this empire is masterless rather than ruled. */
    public static function is_rival(object $kingdom): bool {
        return (int) ($kingdom->is_rival ?? 0) === 1;
    }

    public static function enabled(): bool {
        // Never in league play: there is no war within a site while the league
        // runs, so an empire nobody can march on would be furniture.
        if (class_exists('IDO_League') && IDO_League::active()) return false;
        return IDO_Settings::int('rivals_enabled') === 1;
    }

    /** How many a board should hold. */
    public static function wanted(): int {
        return max(0, min(self::MAX, IDO_Settings::int('rival_count')));
    }

    public static function count(int $round_id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_rival = 1',
            $round_id
        ));
    }

    public static function all(int $round_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IDO_DB::t('kingdoms')
            . ' WHERE round_id = %d AND is_rival = 1 ORDER BY networth ASC',
            $round_id
        ));
    }

    /**
     * Brings the board up to the number asked for, creating what is missing.
     *
     * Never removes: a masterless empire a player has already fought has a
     * history in the battle reports, and deleting the row behind it would leave
     * a report pointing at nobody. Lowering the count stops new ones appearing
     * and leaves the standing ones be. `retire()` is the explicit way out.
     *
     * @return int how many were created
     */
    public static function populate(int $round_id): int {
        if (!self::enabled()) return 0;

        $have = self::count($round_id);
        $want = self::wanted();
        if ($have >= $want) return 0;

        $tiers = self::tiers();
        $made  = 0;

        for ($i = $have; $i < $want; $i++) {
            // Dealt round the ladder rather than chosen at random, so a board of
            // five has one of each rung instead of five of whatever came up.
            $tier = $tiers[$i % count($tiers)];
            if (self::create($round_id, $tier)) $made++;
        }
        return $made;
    }

    /** Creates one masterless empire at the given strength. */
    public static function create(int $round_id, float $tier): bool {
        global $wpdb;

        $names = self::free_name($round_id);
        if ($names === null) return false;   // could not find an unused name

        $template = self::build_template($tier);

        $row = array_merge($template, [
            'round_id'         => $round_id,
            // Negative and distinct. The table carries a unique index on
            // (round_id, user_id), so every one of these sharing 0 would mean
            // the first insert succeeding and every other failing in silence.
            'user_id'          => self::next_user_id($round_id),
            'kingdom_name'     => $names['kingdom'],
            'ruler_name'       => $names['ruler'],
            'is_rival'         => 1,
            'rival_template'   => wp_json_encode($template),
            'turns'            => 0,
            'last_turn_grant'  => IDO_Game::today(),
            // No truce: these are not new rulers finding their feet, they are
            // old provinces that have been standing a long time.
            'protection_until' => null,
            'created_at'       => IDO_Game::now(),
        ]);

        if (!$wpdb->insert(IDO_DB::t('kingdoms'), $row)) return false;

        $id = (int) $wpdb->insert_id;
        IDO_Kingdom::recalc_networth(IDO_Kingdom::find($id));
        return true;
    }

    /**
     * The template for one empire, scaled and then shaken.
     *
     * The jitter matters more than it looks: five identical empires on a rung
     * read as scenery, and a ruler who has beaten one knows exactly what the
     * next holds. A fifth either way means scouting is worth a turn.
     */
    private static function build_template(float $tier): array {
        $out = [];
        foreach (self::profile() as $column => $base) {
            $scaled = $base * $tier * (wp_rand(80, 120) / 100);
            $out[$column] = max(1, (int) round($scaled));
        }
        return $out;
    }

    /** A user id no empire in this round is using, below zero. */
    private static function next_user_id(int $round_id): int {
        global $wpdb;
        $lowest = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(MIN(user_id), 0) FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d',
            $round_id
        ));
        return min(-1, $lowest - 1);
    }

    /**
     * An unused pair of names, or null if the well has run dry.
     *
     * Both columns carry a unique index per round, so a collision is a failed
     * insert rather than a duplicate, and a failed insert here would be silent.
     * Checked before writing, and given up on rather than looped forever.
     */
    private static function free_name(int $round_id): ?array {
        global $wpdb;

        for ($try = 0; $try < 40; $try++) {
            $kingdom = self::province_name();
            $ruler   = self::ruler_name();

            $taken = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . IDO_DB::t('kingdoms')
                . ' WHERE round_id = %d AND (kingdom_name = %s OR ruler_name = %s)',
                $round_id, $kingdom, $ruler
            ));
            if ($taken === 0) return ['kingdom' => $kingdom, 'ruler' => $ruler];
        }
        return null;
    }

    /** Roman-sounding province names, built from two halves. */
    private static function province_name(): string {
        $stems = ['Aquil', 'Brun', 'Cassi', 'Corn', 'Drus', 'Fabr', 'Gall', 'Hadri',
                  'Iul', 'Lavin', 'Mart', 'Nerv', 'Octav', 'Pomp', 'Quint', 'Rufin',
                  'Sabin', 'Tarqu', 'Ulpi', 'Valer', 'Verg', 'Aurel', 'Claud', 'Flav'];
        $ends  = ['ia', 'ium', 'ana', 'anum', 'ica', 'inum', 'orum', 'ensis', 'etia', 'onia'];
        return $stems[wp_rand(0, count($stems) - 1)] . $ends[wp_rand(0, count($ends) - 1)];
    }

    /** A name for whoever is nominally in charge of a province with no ruler. */
    private static function ruler_name(): string {
        $praenomen = ['Gaius', 'Lucius', 'Marcus', 'Titus', 'Decimus', 'Publius', 'Quintus',
                      'Servius', 'Spurius', 'Aulus', 'Gnaeus', 'Manius'];
        $nomen     = ['Aemilius', 'Caecilius', 'Domitius', 'Fabius', 'Horatius', 'Junius',
                      'Licinius', 'Marius', 'Ovidius', 'Porcius', 'Sulpicius', 'Varro',
                      'Albinus', 'Cato', 'Crassus', 'Galba', 'Rufus', 'Seneca'];
        return $praenomen[wp_rand(0, count($praenomen) - 1)] . ' '
             . $nomen[wp_rand(0, count($nomen) - 1)];
    }

    /**
     * Moves every masterless empire a share of the way back to what it was.
     *
     * Towards the template in both directions. Upwards is the point -- an empire
     * a player has stripped should be worth marching on again in a week rather
     * than never -- and downwards keeps one that has won a few defences from
     * drifting upward out of everybody's reach for good.
     *
     * @return int how many moved
     */
    public static function regenerate(int $round_id): int {
        global $wpdb;

        $share = max(1, min(100, IDO_Settings::int('rival_regen_percent'))) / 100;
        $moved = 0;

        foreach (self::all($round_id) as $rival) {
            $template = json_decode((string) $rival->rival_template, true);
            if (!is_array($template)) continue;

            $fields = [];
            foreach ($template as $column => $target) {
                $target = (int) $target;
                $now    = (int) ($rival->{$column} ?? 0);
                if ($now === $target) continue;

                $step = (int) round(($target - $now) * $share);
                // Always at least one, or a gap of nine never closes at a tenth.
                if ($step === 0) $step = $target > $now ? 1 : -1;
                $fields[$column] = max(0, $now + $step);
            }

            if (!$fields) continue;

            // Written straight rather than through pay(): this is the world
            // repairing itself, not an empire spending anything, and pay()
            // would read it as income and cap it against a turn's allowance.
            $wpdb->update(IDO_DB::t('kingdoms'), $fields, ['id' => (int) $rival->id]);

            $fresh = IDO_Kingdom::find((int) $rival->id);
            if ($fresh) {
                // A province that was beaten flat is standing again, so it is no
                // longer counted among the defeated.
                if ((int) $fresh->is_defeated === 1) {
                    $wpdb->update(IDO_DB::t('kingdoms'), ['is_defeated' => 0], ['id' => (int) $fresh->id]);
                    $fresh = IDO_Kingdom::find((int) $fresh->id);
                }
                IDO_Kingdom::recalc_networth($fresh);
            }
            $moved++;
        }
        return $moved;
    }

    // -- Striking back ------------------------------------------------------

    public static function retaliation_enabled(): bool {
        return self::enabled() && IDO_Settings::int('rival_retaliation') === 1;
    }

    /**
     * Players a masterless empire has reason to march on.
     *
     * Read out of the battle record rather than kept in a flag of its own: that
     * record already says who marched on whom and when, it is the thing a player
     * can see and argue with, and a second copy of the same fact is a second
     * thing to keep in step.
     *
     * The grudge expires. A province that has been left alone for the memory
     * window forgets, which means a player can stop a feud by stopping, and a
     * board does not accumulate permanent enemies from one curious march in week
     * one.
     */
    public static function grudges(object $rival): array {
        global $wpdb;

        $days   = max(1, IDO_Settings::int('rival_memory_days'));
        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);

        return (array) $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT attacker_kingdom_id FROM ' . IDO_DB::t('battles')
            . ' WHERE defender_kingdom_id = %d AND created_at >= %s',
            (int) $rival->id, $cutoff
        ));
    }

    /**
     * The army a province sends when it marches, as a share of what it holds.
     *
     * Never everything. A province that emptied its walls to strike back would
     * be free to anybody passing the next morning, and the point of these is to
     * be worth attacking more than once.
     */
    private static function marching_force(object $rival): array {
        $force = [];
        foreach (IDO_Units::keys() as $key) {
            $held = (int) ($rival->{IDO_Units::column($key)} ?? 0);
            if ($held < 1) continue;
            $send = (int) floor($held * (wp_rand(55, 75) / 100));
            if ($send > 0) $force[$key] = $send;
        }
        return $force;
    }

    /**
     * Lets provoked provinces march, on the daily tick.
     *
     * Deliberately routed through IDO_Military::attack() with nothing special
     * about it. Every rule a player is held to is a rule these are held to: the
     * crown truce, the net worth band, the limit on how often one target may be
     * hit, the lock that stops two battles at once. The only thing handed over
     * is the turns, because a province does not spend its days the way a ruler
     * does -- and handing those over is what keeps everything else honest,
     * rather than teaching the war code about a second kind of attacker.
     *
     * A refusal is not an error here. Out of band, under truce, already hit
     * today: all ordinary answers, and the province simply does not march.
     *
     * @return array{marched:int,refused:int}
     */
    public static function retaliate(int $round_id): array {
        if (!self::retaliation_enabled()) return ['marched' => 0, 'refused' => 0];

        $chance    = max(0, min(100, IDO_Settings::int('rival_attack_chance')));
        $turn_cost = max(1, IDO_Settings::int('attack_turn_cost'));
        $types     = array_keys(IDO_Military::attack_types());

        $marched = 0;
        $refused = 0;
        $hit_today = [];   // one province per player per night, whatever the dice say

        foreach (self::all($round_id) as $rival) {
            if ($chance < 1 || wp_rand(1, 100) > $chance) continue;

            $targets = self::grudges($rival);
            shuffle($targets);

            foreach ($targets as $target_id) {
                $target_id = (int) $target_id;
                if (isset($hit_today[$target_id])) continue;

                $target = IDO_Kingdom::find($target_id);
                if (!$target || (int) $target->round_id !== $round_id) continue;
                if ((int) $target->is_defeated === 1) continue;
                if (self::is_rival($target)) continue;          // they do not fight each other
                if (IDO_Kingdom::is_protected($target)) continue;

                $force = self::marching_force($rival);
                if (!$force) break;

                // The turns a province does not otherwise have. Granted rather
                // than waived so that attack() charges them, the economy ticks
                // once as it does for anybody, and no rule needs an exception.
                global $wpdb;
                $wpdb->update(IDO_DB::t('kingdoms'), ['turns' => $turn_cost], ['id' => (int) $rival->id]);
                $marching = IDO_Kingdom::find((int) $rival->id);

                try {
                    IDO_Military::attack($marching, $target_id,
                        $types[wp_rand(0, count($types) - 1)], $force, []);
                    $hit_today[$target_id] = true;
                    $marched++;
                } catch (IDO_Game_Exception $e) {
                    $refused++;
                } finally {
                    // Whatever happened, it keeps none of them.
                    $wpdb->update(IDO_DB::t('kingdoms'), ['turns' => 0], ['id' => (int) $rival->id]);
                }
                break;   // one march a night each
            }
        }

        return ['marched' => $marched, 'refused' => $refused];
    }

    /**
     * Removes every masterless empire from a round.
     *
     * Only ever from an explicit choice on the admin screen. Battle reports
     * naming one will outlive it, which is the cost of the decision and is why
     * nothing does this on its own.
     */
    public static function retire(int $round_id): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . IDO_DB::t('kingdoms') . ' WHERE round_id = %d AND is_rival = 1',
            $round_id
        ));
    }
}
