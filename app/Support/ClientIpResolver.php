<?php

namespace App\Support;

/**
 * Resolves the real client IP from a set of proxy/CDN headers.
 *
 * This is the SINGLE source of truth for client-IP resolution. It is shared by
 * the redirect hot path: RedirectMetricsCollector (middleware, live Request) and
 * LinkTrackingService::resolveRealUserIP (queued job, serialized payload). Because
 * those two callers have different input shapes, the resolver is intentionally
 * framework-agnostic: it takes already-extracted raw header values plus a fallback
 * IP, never a Request object.
 *
 * Priority chain (first valid public IP wins):
 *   1. ?real_ip query parameter (dev/proxy override sent by the frontend)
 *   2. X-Real-IP header (nginx proxy)
 *   3. X-Forwarded-For header, first token (CDN/load balancer)
 *   4. CF-Connecting-IP header (Cloudflare)
 *   5. fallback IP (Request::ip(), or '127.0.0.1' when that is empty too)
 *
 * In production, private/reserved ranges are excluded via FILTER_FLAG_NO_PRIV_RANGE
 * | FILTER_FLAG_NO_RES_RANGE — this prevents internal proxy IPs from being stored.
 *
 * SECURITY TODO (deliberately NOT fixed here — behavior-preserving dedup only):
 * trusting the FIRST token of X-Forwarded-For is spoofable by clients. Fixing it
 * changes the IP trust semantics and impacts geo/fraud/rate-limiting/LGPD; it
 * requires the production proxy config (trusted-proxy list) and is a separate task.
 */
class ClientIpResolver
{
    /**
     * Ordered source labels for the resolution priority chain. Used both as the
     * iteration order and as the label reported to the optional $onResolved hook.
     */
    public const SOURCE_QUERY_PARAM = 'query_param';

    public const SOURCE_X_REAL_IP = 'X-Real-IP';

    public const SOURCE_X_FORWARDED_FOR = 'X-Forwarded-For';

    public const SOURCE_CF_CONNECTING_IP = 'Cloudflare';

    public const SOURCE_FALLBACK = 'fallback';

    /**
     * Resolves the real client IP from already-extracted header candidates.
     *
     * Candidate keys are the source constants above (excluding SOURCE_FALLBACK).
     * Missing/null/empty candidates are skipped. The X-Forwarded-For value may be a
     * comma-separated chain ("client, proxy1, proxy2"); only the first token is used.
     *
     * The optional $onResolved callback is invoked exactly once with the winning
     * source label, the resolved IP, and a context array (e.g. the full XFF chain),
     * letting callers emit per-source debug logs without duplicating the chain logic.
     *
     * @param  array<string, string|null>  $candidates  Raw header values keyed by source constant.
     * @param  string  $fallback  Fallback IP used when no candidate is a valid public IP.
     * @param  (callable(string $source, string $ip, array<string, mixed> $context): void)|null  $onResolved  Optional hook fired with the winning source.
     * @return string A valid IP address string (never empty; falls back to '127.0.0.1' upstream).
     */
    public function resolve(array $candidates, string $fallback, ?callable $onResolved = null): string
    {
        if ($ip = $this->validCandidate($candidates[self::SOURCE_QUERY_PARAM] ?? null)) {
            $onResolved && $onResolved(self::SOURCE_QUERY_PARAM, $ip, []);

            return $ip;
        }

        if ($ip = $this->validCandidate($candidates[self::SOURCE_X_REAL_IP] ?? null)) {
            $onResolved && $onResolved(self::SOURCE_X_REAL_IP, $ip, []);

            return $ip;
        }

        $forwardedFor = $candidates[self::SOURCE_X_FORWARDED_FOR] ?? null;
        if ($forwardedFor !== null && $forwardedFor !== '') {
            // X-Forwarded-For may carry multiple IPs: "client, proxy1, proxy2".
            $clientIP = trim(explode(',', $forwardedFor)[0]);
            if ($this->isValidIp($clientIP)) {
                $onResolved && $onResolved(self::SOURCE_X_FORWARDED_FOR, $clientIP, ['full_chain' => $forwardedFor]);

                return $clientIP;
            }
        }

        if ($ip = $this->validCandidate($candidates[self::SOURCE_CF_CONNECTING_IP] ?? null)) {
            $onResolved && $onResolved(self::SOURCE_CF_CONNECTING_IP, $ip, []);

            return $ip;
        }

        $fallbackIP = $fallback !== '' ? $fallback : '127.0.0.1';
        $onResolved && $onResolved(self::SOURCE_FALLBACK, $fallbackIP, []);

        return $fallbackIP;
    }

    /**
     * Trims a raw candidate value and returns it only when it is a valid IP.
     *
     * @param  string|null  $raw  Raw header value (may be null/empty).
     * @return string|null The trimmed, valid IP, or null when absent/invalid.
     */
    private function validCandidate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $clean = trim($raw);

        return $this->isValidIp($clean) ? $clean : null;
    }

    /**
     * Validates that an IP is well-formed and, in production, publicly routable.
     *
     * In production, private/reserved ranges are rejected. In every other
     * environment any syntactically valid IP is accepted.
     *
     * @param  string  $ip  The IP address to validate.
     * @return bool True when the IP is acceptable for storage.
     */
    public function isValidIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (config('app.env') === 'production') {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
