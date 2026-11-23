<?php
if (!defined('ABSPATH')) exit;

/**
 * Outbound calls to a peer site.
 *
 * Every request to another league member goes through here, so there is one
 * place that decides what this site is willing to dial. The URL rules in
 * IDO_League_URL are the policy; this is the only code that acts on them.
 *
 * wp_safe_remote_post() rather than wp_remote_post(), because the safe variant
 * applies WordPress's own host validation on top of ours and refuses redirects
 * to private addresses. Two checks that disagree is better than one that is
 * wrong, and this is the request an attacker most wants to steer.
 *
 * sslverify is never disabled. It is the one line that would make every other
 * precaution here decorative, and there is no setting for it.
 */
class IDO_League_HTTP {

    /** A peer that cannot answer in this long is down as far as we care. */
    const TIMEOUT = 10;

    /** A peer's answer is small. Anything larger is a peer we do not understand. */
    const MAX_RESPONSE = 65536;

    /**
     * POSTs JSON to a peer route and returns the decoded answer.
     *
     * @param string $base  the peer's site URL, already normalised
     * @param string $route the REST route, e.g. 'hello'
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    public static function post_json(string $base, string $route, array $body): array {
        $json = wp_json_encode($body);
        if (!is_string($json)) return self::fail('That request could not be encoded.');
        return self::send($base, $route, $json, 'application/json; charset=utf-8');
    }

    private static function send(string $base, string $route, string $payload, string $content_type): array {
        $normal = IDO_League_URL::normalize($base);
        if ($normal === null) {
            return self::fail('That address is not one this site will call.');
        }
        $addresses = IDO_League_URL::callable_addresses($normal);
        if ($addresses === null) {
            return self::fail('That address resolves somewhere this site will not call.');
        }
        $dev = IDO_League_URL::dev_mode();
        // The address pin below is a cURL option. Without cURL, WordPress falls
        // back to a transport that resolves the name itself, which would reopen
        // the rebinding window, so outside development mode the call is refused.
        if (!$dev && !function_exists('curl_init')) {
            return self::fail('League traffic needs the PHP cURL extension, and it is not installed.');
        }
        if (!preg_match('/^[a-z-]{1,32}$/', $route)) {
            return self::fail('Bad route.');   // never from a packet; a programming error
        }

        $url = $normal . '/wp-json/ido/v1/' . $route;

        // On a development install pairing with another site on this machine,
        // WordPress's own validation would refuse the private address before our
        // own checks ever ran. The filters are added for this one call and
        // removed immediately, and only when the environment itself says this is
        // a local or development site.
        $allow_local = static function () { return true; };
        if ($dev) {
            add_filter('http_request_host_is_external', $allow_local, 10, 0);
            add_filter('http_request_args', [__CLASS__, 'allow_local_args'], 10, 1);
        }

        // Pin the connection to an address that was just checked, so the
        // library cannot resolve the name a second time and land somewhere
        // else (DNS rebinding). TLS still verifies against the host name, so a
        // pinned address that is not really the peer fails the handshake.
        $pin = null;
        $only_curl = null;
        if (!$dev && $addresses !== []) {
            $host = trim((string) wp_parse_url($normal, PHP_URL_HOST), '[]');
            $port = (int) (wp_parse_url($normal, PHP_URL_PORT) ?: 443);
            $ip   = (string) $addresses[0];
            $entry = $host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip);
            $pin = static function (&$handle) use ($entry): void {
                curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
            };
            $only_curl = static function () { return ['curl']; };
            add_action('http_api_curl', $pin, 10, 1);
            add_filter('http_api_transports', $only_curl, 10, 0);
        }

        $response = wp_safe_remote_post($url, [
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,          // a redirect is where a validated host stops being the host
            'sslverify'   => true,
            'headers'     => ['Content-Type' => $content_type],
            'body'        => $payload,
            'user-agent'  => 'ImperialDominionOnline/' . IDO_VERSION,
            // Stop reading past the cap rather than downloading the lot and
            // then refusing it: a hostile peer can otherwise stream until PHP
            // runs out of memory.
            'limit_response_size' => self::MAX_RESPONSE + 1,
        ]);

        if ($dev) {
            remove_filter('http_request_host_is_external', $allow_local, 10);
            remove_filter('http_request_args', [__CLASS__, 'allow_local_args'], 10);
        }
        if ($pin !== null) {
            remove_action('http_api_curl', $pin, 10);
            remove_filter('http_api_transports', $only_curl, 10);
        }

        if (is_wp_error($response)) {
            // Not the library's own message. Its wording (refused, timed out,
            // reset, certificate) told whoever chose the address what is
            // listening there, and this text is logged and shown. The one case
            // an administrator needs to tell apart is a certificate problem.
            $raw_error = strtolower($response->get_error_message());
            return self::fail(strpos($raw_error, 'ssl') !== false || strpos($raw_error, 'certificate') !== false
                ? 'That peer\'s HTTPS certificate could not be verified.'
                : 'That peer could not be reached.');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        if (strlen($raw) > self::MAX_RESPONSE) {
            return self::fail('That peer sent more than this site will read.');
        }

        // A peer's answer is untrusted input exactly like a packet is. Same
        // rules: JSON only, depth capped, arrays and scalars, never objects.
        $data = [];
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) $data = $decoded;
            } catch (JsonException $e) {
                return self::fail('That peer did not answer in a form this site understands.');
            }
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'data'   => $data,
            'error'  => $status >= 200 && $status < 300 ? '' : self::message($data, $status),
        ];
    }

    /**
     * POSTs a signed packet, which travels as text rather than JSON.
     *
     * text/plain on purpose. With application/json WordPress parses the body into
     * parameters before a handler runs, which is work done on unverified input,
     * and the signature covers the exact bytes so nothing must re-serialise them
     * on the way past.
     *
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    public static function post_text(string $base, string $route, string $wire): array {
        return self::send($base, $route, $wire, 'text/plain; charset=utf-8');
    }

    /**
     * Lets one request reach a local address in development.
     *
     * Only reachable while dev_mode() is true, and the filter is removed as soon
     * as the call returns, so nothing else in WordPress inherits it.
     */
    public static function allow_local_args(array $args): array {
        $args['reject_unsafe_urls'] = false;
        return $args;
    }

    /** A peer's own words, if it sent any we can safely show. */
    private static function message(array $data, int $status): string {
        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : '';
        $reason = preg_replace('/[^\x20-\x7e]/', '', substr($reason, 0, 190));
        return $reason !== '' ? $reason : sprintf('That peer answered with status %d.', $status);
    }

    private static function fail(string $error): array {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => $error];
    }
}
