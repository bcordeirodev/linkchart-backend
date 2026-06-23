<?php

namespace App\Http\Middleware;

use App\Observability\Otel;
use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opens an OpenTelemetry SERVER span for every HTTP request, continuing any
 * inbound W3C trace context (e.g. the `traceparent` header the frontend's
 * Faro SDK injects into /api/* calls).
 *
 * The span and its scope are stashed on the request's attribute bag so that
 * `terminate()` can retrieve them regardless of whether Laravel resolved a
 * new middleware instance (which it does by default — terminate() is called
 * on a fresh container resolution, not on the same instance that ran handle()).
 * This is the "request attributes" strategy, preferred over a singleton bind
 * because it is self-contained and avoids polluting the container lifetime.
 *
 * The span is detached and ended in `terminate()` so neither span work nor
 * the batch-exporter flush sits on the response's critical path.
 *
 * A no-op when OTEL_ENABLED is false.
 */
final class OtelTrace
{
    /** @var string Request attribute key for the active span. */
    private const ATTR_SPAN = 'otel.span';

    /** @var string Request attribute key for the active scope. */
    private const ATTR_SCOPE = 'otel.scope';

    /**
     * Route names for the redirect hot path. Requests matching these routes
     * are subject to a dedicated sampler ratio (otel.redirect_sampler_ratio)
     * that defaults to 0.05, keeping 95 % of redirects span-free.
     *
     * @var list<string>
     */
    private const REDIRECT_ROUTE_NAMES = [
        'public.redirect',
        'public.redirect.clean',
    ];

    /**
     * Start a SERVER span and stash it on the request for use in terminate().
     *
     * Redirect routes (/r/{slug} and /{slug}) are sampled independently via
     * otel.redirect_sampler_ratio to protect the hot path from unnecessary
     * span-creation work. When a redirect request is not sampled in, the
     * middleware is bypassed entirely — no span, no propagation overhead.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Otel::enabled()) {
            return $next($request);
        }

        // Redirect-aware sampling gate: apply a separate, lower sample ratio for
        // the redirect hot path so the global TracerProvider sampler (which runs
        // at ratio 1.0 for normal routes) does not record a SERVER span for every
        // redirect.  lcg_value() returns a float in [0, 1) — when it is >= the
        // configured ratio we skip span creation entirely, keeping the check cheap
        // (one config read + one RNG call) and non-throwing.
        $routeName = $request->route()?->getName();
        if (in_array($routeName, self::REDIRECT_ROUTE_NAMES, true)) {
            $ratio = (float) config('otel.redirect_sampler_ratio', 0.05);
            if (lcg_value() >= $ratio) {
                return $next($request);
            }
        }

        try {
            // Symfony's $request->headers->all() returns each value as an array
            // (e.g. ['traceparent' => ['00-...']]).  ArrayAccessGetterSetter.get()
            // handles this correctly — it takes array_key_first() when the value is
            // an array — so we can pass the raw headers bag directly.
            $parent = Globals::propagator()->extract($request->headers->all());

            // Prefer the route NAME, then the route URI TEMPLATE (e.g.
            // "links/analytics/link/{id}/dashboard"), and only fall back to the
            // raw path as a last resort. Using the template keeps span-name
            // cardinality bounded — the raw path embeds numeric IDs and would
            // explode the number of distinct span names in production.
            $name = $request->method().' '.(
                $request->route()?->getName()
                ?? $request->route()?->uri()
                ?? $request->path()
            );

            $span = Otel::tracer()
                ->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->setParent($parent)
                ->setAttribute('http.request.method', $request->method())
                ->setAttribute('url.path', $request->path())
                ->startSpan();

            $scope = $span->activate();

            // Stash on the request so terminate() finds them on any instance.
            $request->attributes->set(self::ATTR_SPAN, $span);
            $request->attributes->set(self::ATTR_SCOPE, $scope);
        } catch (Throwable) {
            // Tracing must never block a request.
        }

        return $next($request);
    }

    /**
     * End the span after the response has been sent to the client.
     *
     * Laravel may resolve a fresh instance of this class for terminate(), so we
     * read the span/scope from the request's attribute bag rather than from
     * instance state.
     */
    public function terminate(Request $request, Response $response): void
    {
        $span = $request->attributes->get(self::ATTR_SPAN);

        if ($span === null) {
            return;
        }

        try {
            $span->setAttribute('http.response.status_code', $response->getStatusCode());

            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            /** @var \OpenTelemetry\Context\ScopeInterface|null $scope */
            $scope = $request->attributes->get(self::ATTR_SCOPE);
            $scope?->detach();
            $span->end();
        } catch (Throwable) {
            // ignore — tracing must never surface as a request error
        } finally {
            $request->attributes->remove(self::ATTR_SPAN);
            $request->attributes->remove(self::ATTR_SCOPE);
        }
    }
}
