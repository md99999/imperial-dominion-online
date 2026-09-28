<?php
if (!defined('ABSPATH')) exit;

/**
 * The wire format, and the only place a packet is signed or verified.
 *
 * Packets are signed, not encrypted. Encryption hides content; what a league
 * needs is proof that a packet came from the peer and arrived unaltered, which
 * is authentication, and there is nothing confidential in "Vaelmark sent 800
 * centurions" when the defender is about to be told anyway.
 *
 * The bytes that travel are the bytes that are signed. Signing a re-serialised
 * copy of a parsed structure invites canonicalisation bugs, where two sites
 * disagree about key order or whitespace and every packet fails verification
 * for reasons nobody can see.
 *
 *     ido1.<sender uuid>.<base64url payload>.<hex signature>
 *
 * The version prefix carries the algorithm, and the algorithm is hard-coded
 * against it. A packet never names its own algorithm: letting it do so is how
 * a long list of JWT implementations were broken.
 */
class IDO_League_Crypto {

    /** Bump this when the wire format or the algorithm changes. */
    const WIRE = 'ido1';

    /** Refused before anything else looks at it. A war packet is a few hundred bytes. */
    const MAX_BYTES = 65536;

    /** json_decode depth. Nothing legitimate here is more than four deep. */
    const MAX_DEPTH = 8;

    /** A shared secret: 32 bytes from the CSPRNG, carried as hex. */
    public static function secret(): string {
        return bin2hex(random_bytes(32));
    }

    /** A one-time invitation token. Shown once, stored only as a hash. */
    public static function token(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Tokens are stored hashed, so a database read does not yield a usable
     * invitation. Plain SHA-256 rather than a password hash on purpose: this is
     * 32 bytes of CSPRNG output, not a memorable secret, so there is nothing to
     * brute-force and no reason to pay for stretching on every verification.
     */
    public static function token_hash(string $token): string {
        return hash('sha256', $token);
    }

    public static function uuid(): string {
        if (function_exists('wp_generate_uuid4')) return wp_generate_uuid4();
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Builds the signed wire form of a packet body.
     *
     * Everything security-relevant is inside the signed bytes, including who
     * the packet is *for*: a packet signed for one peer must not verify at
     * another, and a war order must not be replayable as a result.
     */
    public static function pack(array $body, string $from_uuid, string $secret): string {
        $json = wp_json_encode($body);
        if (!is_string($json)) throw new IDO_Game_Exception('This packet could not be encoded.');

        $payload = self::b64_encode($json);
        $signed  = self::WIRE . '.' . $from_uuid . '.' . $payload;

        return $signed . '.' . hash_hmac('sha256', $signed, $secret);
    }

    /**
     * Splits a wire string without verifying anything.
     *
     * Its only job is to find which peer claims to have sent this, so the right
     * secret can be looked up. The claim is worth nothing until the signature
     * verifies, and this method is deliberately incapable of saying otherwise.
     *
     * @return array{version:string,from:string,payload:string,signature:string}|null
     */
    public static function split(string $wire): ?array {
        if ($wire === '' || strlen($wire) > self::MAX_BYTES) return null;

        $parts = explode('.', trim($wire));
        if (count($parts) !== 4) return null;
        [$version, $from, $payload, $signature] = $parts;

        if ($version !== self::WIRE) return null;
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $from)) return null;
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $payload)) return null;
        if (!preg_match('/^[0-9a-f]{64}$/', $signature)) return null;

        return ['version' => $version, 'from' => $from, 'payload' => $payload, 'signature' => $signature];
    }

    /**
     * Whether this wire string was signed with this secret.
     *
     * hash_equals rather than ===, so the comparison does not leak the expected
     * signature one byte at a time through how long it takes to fail.
     */
    public static function verify(string $wire, string $secret): bool {
        $parts = self::split($wire);
        if ($parts === null || $secret === '') return false;

        $signed   = $parts['version'] . '.' . $parts['from'] . '.' . $parts['payload'];
        $expected = hash_hmac('sha256', $signed, $secret);

        return hash_equals($expected, $parts['signature']);
    }

    /**
     * Verifies a packet and returns its decoded body, or null.
     *
     * **The only way to reach the parser.** Verification and decoding used to be
     * two public methods with a comment explaining which order to call them in,
     * which is not a control: a comment cannot stop the next person reaching for
     * the convenient one. Decoding is now private and unreachable except through
     * this method, so a packet whose signature does not verify is never handed
     * to json_decode() at all. The order of operations is the security property,
     * and it is now enforced by the class rather than described by it.
     */
    public static function open(string $wire, string $secret): ?array {
        if (!self::verify($wire, $secret)) return null;
        return self::decode($wire);
    }

    /**
     * Turns verified bytes into an array.
     *
     * Private, and deliberately: see open(). Associative mode and never
     * unserialize(), so json_decode yields arrays and scalars only, there is no
     * object to instantiate and nothing that can run. The depth and size caps
     * stop a small packet expanding into an enormous structure.
     */
    private static function decode(string $wire): ?array {
        $parts = self::split($wire);
        if ($parts === null) return null;

        $json = self::b64_decode($parts['payload']);
        if ($json === null || strlen($json) > self::MAX_BYTES) return null;

        try {
            $data = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** URL-safe base64 without padding, so a packet survives a query string or a mail transport. */
    public static function b64_encode(string $raw): string {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64_decode(string $encoded): ?string {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $encoded)) return null;
        $padded  = strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($padded, true);
        return $decoded === false ? null : $decoded;
    }
}
