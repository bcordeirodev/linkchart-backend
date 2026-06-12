<?php

namespace App\Services\Links;

/**
 * SSRF protection for outbound metadata fetches.
 *
 * Layers:
 *   1. Scheme must be http/https.
 *   2. Hardcoded loopback aliases (localhost, localhost.localdomain, 0.0.0.0).
 *   3. Internal TLD suffixes (*.local, *.internal, *.localhost).
 *   4. Literal IPv4/IPv6 in private or reserved ranges. IPv4-mapped IPv6
 *      addresses (::ffff:a.b.c.d) are decoded and their embedded IPv4 is
 *      checked, since filter_var treats the mapped form as public even when
 *      the inner address is loopback or RFC-1918.
 *   5. Non-standard numeric IPv4 shorthands (decimal/hex/octal and short
 *      dotted forms like 127.1 or 0x7f000001) that filter_var does not accept
 *      but HTTP clients resolve to real addresses. Canonicalised here with an
 *      inet_aton-equivalent parser and checked against private/reserved ranges.
 *      This is done deterministically rather than delegated to the system
 *      resolver, whose handling of these forms is environment-specific.
 *   6. DNS rebinding mitigation: the hostname's A AND AAAA records are
 *      resolved and the URL is rejected if ANY address is private/reserved.
 *      Resolution failure is allowed through — the subsequent HTTP fetch
 *      fails on its own, and rejecting would break flaky-DNS hosts.
 *
 * Out of scope: /etc/hosts entries that shadow a public hostname to an
 * internal IP — that requires host compromise, and catching it reliably
 * would reintroduce the environment-specific resolver dependency this
 * validator deliberately avoids.
 *
 * Trailing dots in the host are stripped before any check so that
 * "localhost." is treated identically to "localhost".
 *
 * Note: a TOCTOU window remains between this check and the actual fetch
 * (the HTTP client re-resolves). Closing it requires pinning the resolved IP
 * at the transport layer; accepted residual risk for now.
 */
class SafeFetchUrlValidator
{
    /**
     * Whether the URL is safe to fetch server-side (passes all SSRF layers).
     *
     * @param  string  $url  The URL to validate.
     */
    public function isSafe(string $url): bool
    {
        $parsed = parse_url($url);

        if (! $parsed || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Normalise: strip trailing dot ("localhost." == "localhost") so that
        // all subsequent checks see a canonical host string.
        $host = rtrim($host, '.');

        // Layer 2: hardcoded loopback aliases.
        if (in_array($host, ['localhost', 'localhost.localdomain', '0.0.0.0'], true)) {
            return false;
        }

        // Layer 3: internal TLD suffixes.
        if (preg_match('/\.(local|internal|localhost)$/', $host)) {
            return false;
        }

        // Layer 4: literal IP. parse_url keeps brackets around IPv6 addresses
        // (e.g. "http://[::1]/" yields host "[::1]"), so strip them before
        // passing to filter_var. IPv4 addresses are unaffected by trim('[]').
        $hostRaw = trim($host, '[]');
        if (filter_var($hostRaw, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($hostRaw);
        }

        // Layer 5: non-standard numeric IPv4 shorthands (decimal/hex/octal and
        // short dotted forms) that filter_var rejects but HTTP clients resolve
        // to real addresses (e.g. http://2130706433/, http://0x7f000001/,
        // http://127.1/). Canonicalise deterministically and check the result.
        $numericIp = $this->parseNumericIpv4($host);
        if ($numericIp !== null) {
            return $this->isPublicIp($numericIp);
        }

        // Layer 6: DNS rebinding mitigation — resolve A and AAAA records and
        // reject if any resolved address falls in a private/reserved range.
        foreach ($this->resolveAddresses($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonicalise a non-standard numeric IPv4 host into dotted-quad form,
     * replicating inet_aton semantics (the parsing HTTP clients use), or null
     * when the host is not a purely numeric IPv4 shorthand (i.e. it is a real
     * hostname that should go through DNS resolution).
     *
     * Accepts 1–4 dot-separated parts, each decimal, octal (leading 0) or hex
     * (0x prefix). With fewer than 4 parts the final part fills the remaining
     * low-order bytes (so "127.1" → 127.0.0.1 and "2130706433" → 127.0.0.1).
     *
     * @param  string  $host  The URL host component (already lowercased, brackets/trailing dot stripped).
     */
    protected function parseNumericIpv4(string $host): ?string
    {
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count < 1 || $count > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if ($part === '') {
                return null;
            }

            if (preg_match('/^0[x][0-9a-f]+$/', $part)) {
                $value = hexdec($part);
            } elseif (preg_match('/^0[0-7]+$/', $part)) {
                $value = octdec($part);
            } elseif (preg_match('/^(?:0|[1-9][0-9]*)$/', $part)) {
                $value = (int) $part;
            } else {
                // Contains characters that make this a hostname, not a numeric
                // IP shorthand — defer to DNS resolution.
                return null;
            }

            $values[] = (int) $value;
        }

        // Per-part maximums per inet_aton: the final part absorbs all remaining
        // low-order bytes; every preceding part is a single byte.
        $lastMax = (2 ** (8 * (4 - $count + 1))) - 1;

        foreach ($values as $index => $value) {
            $max = $index === $count - 1 ? $lastMax : 0xFF;
            if ($value < 0 || $value > $max) {
                return null;
            }
        }

        $address = $values[$count - 1];
        for ($i = 0; $i < $count - 1; $i++) {
            $address |= $values[$i] << (8 * (3 - $i));
        }

        return long2ip($address);
    }

    /**
     * Whether an IP literal falls outside private and reserved ranges.
     *
     * IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) are decoded and their
     * embedded IPv4 is validated, since filter_var treats the mapped form
     * as public even when the inner address is loopback or private.
     *
     * @param  string  $ip  An IPv4 or IPv6 address string.
     */
    protected function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        // IPv4-mapped IPv6: 80 zero bits, 16 one bits, then the IPv4.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10)."\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Resolve a hostname to all of its A and AAAA addresses. Returns an empty
     * array when resolution fails. Overridable in tests to stub DNS.
     *
     * @param  string  $host  The hostname to resolve.
     * @return array<int, string>
     */
    protected function resolveAddresses(string $host): array
    {
        $ips = [];

        foreach ([[DNS_A, 'ip'], [DNS_AAAA, 'ipv6']] as [$type, $field]) {
            $records = @dns_get_record($host, $type);

            foreach (is_array($records) ? $records : [] as $record) {
                if (! empty($record[$field])) {
                    $ips[] = $record[$field];
                }
            }
        }

        return $ips;
    }
}
