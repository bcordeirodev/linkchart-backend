<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\OtelTrace;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for OtelTrace::routeLabel (private static method).
 *
 * The method is the source of truth for span names and the http.route metric
 * label. It must produce stable, low-cardinality strings — never raw paths that
 * embed user IDs — so we test all three branches:
 *
 *  1. Named route          → returns the name as-is.
 *  2. Generated route name → returns the URI template instead.
 *  3. No matched route     → returns "unknown".
 */
class OtelTraceRouteLabelTest extends TestCase
{
    /**
     * Invoke OtelTrace::routeLabel via reflection so we can test the private
     * static method without modifying the production class.
     */
    private function callRouteLabel(Request $request): string
    {
        $method = new ReflectionMethod(OtelTrace::class, 'routeLabel');
        $method->setAccessible(true);

        /** @var string */
        return $method->invoke(null, $request);
    }

    /**
     * A request whose route has a real, human-assigned name should return that
     * name unchanged — it is already low-cardinality and meaningful.
     */
    public function test_named_route_returns_route_name(): void
    {
        $route = new Route('GET', 'api/links/{id}', fn () => null);
        $route->name('links.show');

        $request = Request::create('/api/links/42');
        $request->setRouteResolver(fn () => $route);

        $this->assertSame('links.show', $this->callRouteLabel($request));
    }

    /**
     * Laravel auto-generates names prefixed with "generated::" for routes
     * registered without ->name(). These carry no analytical value, so the
     * method should fall back to the URI template (e.g. "api/public/link/{slug}")
     * which retains parameter placeholders and avoids ID explosion.
     */
    public function test_generated_route_name_returns_uri_template(): void
    {
        $route = new Route('GET', 'api/public/link/{slug}', fn () => null);
        $route->name('generated::abc123');

        $request = Request::create('/api/public/link/my-link');
        $request->setRouteResolver(fn () => $route);

        $this->assertSame('api/public/link/{slug}', $this->callRouteLabel($request));
    }

    /**
     * When no route matched the request (404 / middleware short-circuit),
     * $request->route() is null. The method must return "unknown" — never the
     * raw request path, which may contain arbitrary user data and would cause
     * unbounded cardinality in metrics labels.
     */
    public function test_no_matched_route_returns_unknown(): void
    {
        $request = Request::create('/not/a/real/path/12345');
        // No route resolver set → $request->route() returns null.

        $this->assertSame('unknown', $this->callRouteLabel($request));
    }
}
