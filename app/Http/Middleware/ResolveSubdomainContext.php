<?php

namespace App\Http\Middleware;

use App\Models\UserSubdomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the Host header on redirect routes and resolves the subdomain owner.
 *
 * Attaches a 'subdomain_context' attribute to the request:
 *   - null   → request arrived on the root domain (no subdomain processing)
 *   - false  → host matches subdomain pattern but label not registered in DB
 *   - UserSubdomain instance → label found and active
 *
 * RedirectController reads this attribute to validate slug ownership.
 */
class ResolveSubdomainContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appDomain = config('app.domain', 'localhost');
        $host = $request->getHost();

        if ($host === $appDomain || ! str_ends_with($host, '.' . $appDomain)) {
            // Root domain or unrelated host — no subdomain context
            return $next($request);
        }

        $label = substr($host, 0, strlen($host) - strlen('.' . $appDomain));

        // Reserved labels (e.g. 'redirect', 'api', 'www') are system subdomains,
        // not user subdomains — treat them the same as the root domain.
        if (in_array($label, config('app.reserved_subdomains', []), true)) {
            return $next($request);
        }

        $sub = UserSubdomain::findActiveCached($label);

        // false signals: subdomain pattern detected but not registered
        $request->attributes->set('subdomain_context', $sub ?? false);

        return $next($request);
    }
}
