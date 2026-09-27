<?php
if (!defined('ABSPATH')) exit;

/**
 * Peer URLs, and the server-side request forgery problem they create.
 *
 * This is the sharpest risk in league play, and it is not in the packet path at
 * all: it is here, where this site takes a URL supplied by a remote party and
 * fetches it. A URL pointing at 127.0.0.1, at an internal admin panel, or at
 * 169.254.169.254 turns this plugin into a proxy for reaching things the
 * attacker cannot reach themselves, from inside the host's own network.
 *
 * So a peer URL has to survive two separate checks. The string is validated
 * here, and the addresses it resolves to are validated here as well, because a
 * hostname that looks public can resolve to a private address and can be made
 * to do so only on the second lookup. Neither check is sufficient alone.
 *
 * None of this replaces the real control, which is that an administrator
 * approves every peer before anything is fetched in anger. It is defence in
 * depth behind a human decision.
 */
class IDO_League_URL {

    /** Overridable so the tests can resolve names without a network. */
    public static $resolver = null;

    /**
     * A peer URL reduced to scheme, host, port and path, or null if it is not
     * one we will ever call.
     *
     * HTTPS only. A league secret and a war packet do not travel in clear text
     * over somebody's hotel wifi because a member typed the wrong scheme.
     */
    public static function normalize(string $url): ?string {
        $url = trim($url);
        if ($url === '' || strlen($url) > 255) return null;

        $parts = wp_parse_url($url);
        if (!is_array($parts)) return null;

        if (($parts['scheme'] ?? '') !== 'https') return null;
        if (isset($parts['user']) || isset($parts['pass'])) return null;   // credentials in a URL are never ours
        if (empty($parts['host'])) return null;

        // A query string or a fragment means the administrator pasted a link
        // rather than a peer URL. Refused rather than stripped: quietly calling
        // a different URL from the one somebody pasted is the wrong habit for
        // the code that decides where signed packets are sent.
        if (isset($parts['query']) || isset($parts['fragment'])) return null;

        $host = strtolower($parts['host']);
        if (!self::valid_host($host)) return null;

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) return null;

        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        if ($path !== '' && !preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path)) return null;

        return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . $path;
    }

    /** A hostname or bracketed IP literal, with nothing exotic in it. */
    private static function valid_host(string $host): bool {
        if (strlen($host) > 253) return false;
        if (str_starts_with($host, '[')) {
            return (bool) filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) return true;
        return (bool) preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $host);
    }

    /**
     * Whether every address this URL resolves to is one we are willing to call.
     *
     * Every address, not the first: a name that resolves to one public address
     * and one loopback address is the same attack with an extra step, and which
     * one a later connection picks is not ours to decide.
     *
     * A name resolving to nothing fails closed. An unresolvable peer is not
     * reachable anyway, and treating "I could not check" as "it is fine" is the
     * wrong way round for a check like this one.
     */
    public static function is_callable_url(string $url): bool {
        $normal = self::normalize($url);
        if ($normal === null) return false;

        $host = (string) (wp_parse_url($normal, PHP_URL_HOST) ?? '');
        $host = trim($host, '[]');

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($addresses === []) return false;

        foreach ($addresses as $address) {
            if (!self::is_public_ip($address)) return false;
        }
        return true;
    }

    /** @return string[] */
    public static function resolve(string $host): array {
        if (is_callable(self::$resolver)) {
            return array_values(array_filter((array) call_user_func(self::$resolver, $host), 'is_string'));
        }

        $addresses = gethostbynamel($host);
        $addresses = is_array($addresses) ? $addresses : [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                if (!empty($record['ipv6'])) $addresses[] = $record['ipv6'];
            }
        }
        return array_values(array_unique($addresses));
    }

    /**
     * Whether an address is out on the public internet.
     *
     * PHP's own reserved-range filters cover most of this, and miss two things
     * that matter, so both are checked explicitly: carrier-grade NAT, which is
     * where a surprising number of hosts actually live, and IPv4 addresses
     * wearing an IPv6 costume, which is the standard way to smuggle 127.0.0.1
     * past a check that only looked at the text.
     */
    public static function is_public_ip(string $address): bool {
        $address = trim($address, '[]');
        $binary  = @inet_pton($address);
        if ($binary === false) return false;

        // ::ffff:127.0.0.1 and friends: unwrap to the address that will actually be dialled.
        if (strlen($binary) === 16 && str_starts_with($binary, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")) {
            $address = inet_ntop(substr($binary, 12));
            if ($address === false) return false;
            $binary = @inet_pton($address);
            if ($binary === false) return false;
        }

        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($public === false) return false;

        if (strlen($binary) === 4) {
            $first  = ord($binary[0]);
            $second = ord($binary[1]);
            if ($first === 100 && $second >= 64 && $second <= 127) return false;   // 100.64.0.0/10, CGNAT
            if ($first === 0 || $first === 127) return false;
            if ($first >= 224) return false;                                        // multicast and above
            return true;
        }

        $first = ord($binary[0]);
        if ($first === 0xff) return false;                                          // ff00::/8, multicast
        if (($first & 0xfe) === 0xfc) return false;                                 // fc00::/7, unique local
        if ($first === 0xfe && (ord($binary[1]) & 0xc0) === 0x80) return false;     // fe80::/10, link local
        if ($binary === str_repeat("\x00", 15) . "\x01") return false;              // ::1
        if ($binary === str_repeat("\x00", 16)) return false;                       // ::
        return true;
    }
}
