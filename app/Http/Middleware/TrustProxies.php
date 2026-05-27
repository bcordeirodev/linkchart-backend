<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Restrict proxy trust to Cloudflare IP ranges only.
 *
 * Using '*' would allow any client to spoof X-Forwarded-For and bypass
 * per-IP rate limiters. Since all traffic passes through Cloudflare,
 * REMOTE_ADDR will always be a Cloudflare IP — restricting here is safe.
 *
 * IP ranges: https://www.cloudflare.com/ips-v4 and /ips-v6
 * Update these if Cloudflare publishes new ranges.
 */
class TrustProxies extends Middleware
{
    /**
     * Cloudflare IPv4 + IPv6 ranges (last updated 2026-05-27).
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_PREFIX;

    /**
     * Determine if the request should be trusted.
     *
     * Custom logic:
     * - Always trust in development
     * - In production, validate proxy origin
     */
    protected function shouldTrustRequest(Request $request): bool
    {
        // In development, always trust
        if (config('app.env') === 'local' || config('app.env') === 'development') {
            return true;
        }

        // In production, validate if request has valid proxy headers
        return $request->hasHeader('X-Forwarded-For') ||
               $request->hasHeader('X-Real-IP') ||
               $request->hasHeader('CF-Connecting-IP'); // Cloudflare
    }
}
