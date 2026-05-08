<?php

namespace App\Http\Middleware;

use App\Logging\Context\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a stable request id for the duration of the HTTP request.
 *
 *  - Reuses inbound `X-Request-Id` if present (useful when behind a load
 *    balancer that already issues one).
 *  - Otherwise generates `req_<16 hex chars>`.
 *  - Populates RequestContext so RequestContextProcessor stamps every log
 *    line with the same id.
 *  - Echoes the id back as `X-Request-Id` on the response.
 *  - Clears RequestContext in finally so PHP-FPM reuse is clean.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id') ?? 'req_'.bin2hex(random_bytes(8));

        $route = optional($request->route())->getName() ?? $request->path();
        $userId = $this->resolveUserId($request);

        RequestContext::set(new RequestContext(
            requestId: $requestId,
            userId: $userId,
            ip: $request->ip(),
            route: $route,
        ));

        try {
            $response = $next($request);
            $response->headers->set('X-Request-Id', $requestId);

            return $response;
        } finally {
            RequestContext::clear();
        }
    }

    /**
     * Resolve user id without throwing if the JWT guard is misconfigured.
     */
    private function resolveUserId(Request $request): ?int
    {
        try {
            $user = $request->user();

            return $user?->id;
        } catch (\Throwable) {
            return null;
        }
    }
}
