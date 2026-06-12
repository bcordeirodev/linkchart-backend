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
 *   5. DNS rebinding mitigation: the hostname's A AND AAAA records are
 *      resolved and the URL is rejected if ANY address is private/reserved.
 *      Resolution failure is allowed through — the subsequent HTTP fetch
 *      fails on its own, and rejecting would break flaky-DNS hosts.
 *   6. System-resolver backstop via gethostbyname: catches integer/hex/octal
 *      IP forms (e.g. http://2130706433/) that DNS queries return nothing for
 *      but the HTTP client's resolver happily normalises, plus /etc/hosts
 *      entries invisible to dns_get_record.
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

        // Layer 5: DNS rebinding mitigation — resolve A and AAAA records and
        // reject if any resolved address falls in a private/reserved range.
        foreach ($this->resolveAddresses($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        // Layer 6 — system-resolver backstop: catches integer/hex/octal IP
        // literal forms (e.g. http://2130706433/) that dns_get_record returns
        // nothing for but which glibc's inet_aton / the HTTP client normalises
        // to a real address, plus /etc/hosts entries invisible to DNS queries.
        $resolved = gethostbyname($host);
        if ($resolved !== $host && ! $this->isPublicIp($resolved)) {
            return false;
        }

        return true;
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
