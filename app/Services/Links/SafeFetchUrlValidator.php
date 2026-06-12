<?php

namespace App\Services\Links;

/**
 * SSRF protection for outbound metadata fetches.
 *
 * Layers:
 *   1. Scheme must be http/https.
 *   2. Hardcoded loopback aliases (localhost, localhost.localdomain, 0.0.0.0).
 *   3. Internal TLD suffixes (*.local, *.internal, *.localhost).
 *   4. Literal IPv4/IPv6 in private or reserved ranges.
 *   5. DNS rebinding mitigation: the hostname's A AND AAAA records are
 *      resolved and the URL is rejected if ANY address is private/reserved.
 *      Resolution failure is allowed through — the subsequent HTTP fetch
 *      fails on its own, and rejecting would break flaky-DNS hosts.
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

        return true;
    }

    /**
     * Whether an IP literal falls outside private and reserved ranges.
     *
     * @param  string  $ip  An IPv4 or IPv6 address string.
     */
    protected function isPublicIp(string $ip): bool
    {
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
