<?php
/**
 * The two kinds of spy an empire can keep.
 *
 * There used to be one, at 500,000 gold, and the price was not the risk: an
 * agent who succeeds is never lost, so reconnaissance cost about 6,000 gold a
 * mission in expectation. The 500,000 was a wall at the door. At roughly eight
 * days of an established empire's entire mint output, most rulers never got
 * through it, and the whole spy court was late-round content that most of a
 * board never saw.
 *
 * Lowering the price alone would not have fixed that, and raising the risk to
 * match would have fixed nothing at all: cut the price tenfold and raise the
 * risk tenfold and the expected cost per mission is exactly what it was, only
 * noisier. So there are two tiers, and they differ in what they can **do** as
 * well as what they cost. Otherwise a ruler works out the cheaper expectation
 * once and never thinks about it again.
 *
 * An informer is cheap enough for a week-old empire and can only look. An agent
 * costs real money and can act. Each column here is a real column on the
 * ido_kingdoms table, so the keys are part of the schema.
 */
if (!defined('ABSPATH')) exit;

class IDO_Agents {

    /**
     * key => how it is named, what it costs, what it may do, and how it fares.
     *
     * 'ops' is null for a tier that may run anything, or a list of mission keys.
     * 'chance_shift' moves the mission's success chance in points and
     * 'risk_factor' multiplies its chance of being taken on a failure. Both are
     * coded rather than settings: they are what separates the tiers, and a board
     * that could flatten them to equal would have two names for one spy.
     */
    public static function all(): array {
        return [
            'informer' => [
                'label'        => 'Informer',
                'plural'       => 'Informers',
                'column'       => 'informers',
                'cost_setting' => 'informer_gold_cost',
                'max_setting'  => 'max_informers',
                'ops'          => ['recon'],
                'chance_shift' => -15,
                'risk_factor'  => 3.0,
                'networth'     => 5000,
                'note'         => 'A talkative stranger with a good memory. Cheap, and only ever looks.',
            ],
            'agent' => [
                'label'        => 'Agent',
                'plural'       => 'Agents',
                'column'       => 'agents',
                'cost_setting' => 'agent_gold_cost',
                'max_setting'  => 'max_agents',
                'ops'          => null,
                'chance_shift' => 0,
                'risk_factor'  => 1.0,
                'networth'     => 50000,
                'note'         => 'A professional. Costs a fortune, and will do more than watch.',
            ],
        ];
    }

    public static function keys(): array {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key): array {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new IDO_Game_Exception('No such kind of spy.');
        }
        return $all[$key];
    }

    public static function label(string $key): string {
        return self::get($key)['label'];
    }

    public static function column(string $key): string {
        return self::get($key)['column'];
    }

    /** What this tier costs to hire. */
    public static function cost(string $key): int {
        return max(0, IDO_Settings::int(self::get($key)['cost_setting']));
    }

    /** How many of this tier the crown allows. */
    public static function limit(string $key): int {
        return max(1, IDO_Settings::int(self::get($key)['max_setting']));
    }

    /** How many of this tier an empire keeps. */
    public static function held(object $kingdom, string $key): int {
        return (int) ($kingdom->{self::column($key)} ?? 0);
    }

    /** Whether this tier may run a given mission. */
    public static function can_run(string $key, string $op_key): bool {
        $ops = self::get($key)['ops'];
        return $ops === null || in_array($op_key, (array) $ops, true);
    }

    /** The tiers an empire holds that could run this mission, best first. */
    public static function available(object $kingdom, string $op_key): array {
        // Reversed, so the agent is offered before the informer: the better spy
        // is the sensible default and the cheap one is the deliberate choice.
        $out = [];
        foreach (array_reverse(self::keys()) as $key) {
            if (self::held($kingdom, $key) > 0 && self::can_run($key, $op_key)) $out[] = $key;
        }
        return $out;
    }

    /** What the empire's spies are worth on the scoreboard. */
    public static function networth(object $kingdom): int {
        $worth = 0;
        foreach (self::all() as $key => $tier) {
            $worth += self::held($kingdom, $key) * (int) $tier['networth'];
        }
        return $worth;
    }
}
