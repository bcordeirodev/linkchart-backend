<?php

namespace Tests\Feature\Observability;

use App\Http\Middleware\OtelTrace;
use App\Observability\Otel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\TestCase;

/**
 * Verifies OtelTrace middleware behaviour in both disabled and enabled modes.
 *
 * DISABLED path: the standard suite (330 tests, all with OTEL_ENABLED=false)
 * already proves the no-op path. The tests below double-check it locally and
 * add the ENABLED path using an in-process InMemoryExporter — no real OTLP
 * collector needed.
 *
 * Terminate-identity proof: Laravel resolves middleware fresh for terminate().
 * We verify the span is actually finished (landed in exportedSpans) which can
 * only happen if terminate() ran AND found the span — i.e. the request-attribute
 * stash works correctly across instance boundaries.
 */
class OtelTraceMiddlewareTest extends TestCase
{
    private InMemoryExporter $exporter;

    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // Build an in-memory SDK tracer and wire it as the global provider.
        $this->exporter = new InMemoryExporter;
        $processor = new SimpleSpanProcessor($this->exporter);
        $this->tracerProvider = new TracerProvider($processor);

        // Reset any previously cached globals, then register our in-memory provider.
        Globals::reset();
        Globals::registerInitializer(function (Configurator $configurator): Configurator {
            return $configurator->withTracerProvider($this->tracerProvider);
        });

        // Enable telemetry for all tests in this class.
        Config::set('otel.enabled', true);
    }

    protected function tearDown(): void
    {
        // Restore defaults so other tests are never affected.
        Config::set('otel.enabled', false);
        $this->tracerProvider->shutdown();
        Globals::reset();

        parent::tearDown();
    }

    public function test_disabled_path_emits_no_spans(): void
    {
        Config::set('otel.enabled', false);
        $this->assertFalse(Otel::enabled());

        $this->get('/health')->assertStatus(200);

        $this->assertCount(0, $this->exporter->getSpans(), 'No spans must be exported when disabled');
    }

    public function test_enabled_path_exports_a_finished_span(): void
    {
        // /health is a web route registered via ->withRouting(health: '/health').
        $this->get('/health')->assertStatus(200);

        $spans = $this->exporter->getSpans();

        // If terminate() had run on the WRONG instance (stale $this->pending = [])
        // the span would never be ended, SimpleSpanProcessor would never flush it,
        // and getSpans() would return an empty array.
        $this->assertGreaterThanOrEqual(
            1,
            count($spans),
            'Expected at least one finished span. If zero, terminate() ran on a different '.
            'instance and the request-attribute stash fix did not work.'
        );
    }

    public function test_span_carries_http_attributes(): void
    {
        $this->get('/health');

        $span = $this->findSpanByPath('health');
        $this->assertNotNull($span, 'No span with url.path=health found after GET /health');

        $attrs = $span->getAttributes();
        $this->assertSame('GET', $attrs->get('http.request.method'));
        $this->assertSame('health', $attrs->get('url.path'));
    }

    public function test_200_response_does_not_set_error_status(): void
    {
        $this->get('/health');

        $span = $this->findSpanByPath('health');
        $this->assertNotNull($span);

        $this->assertNotSame(
            StatusCode::STATUS_ERROR,
            $span->getStatus()->getCode(),
            'A 200 response must not mark the span as ERROR'
        );
    }

    public function test_5xx_response_sets_error_status(): void
    {
        // Drive the middleware directly through the same in-memory exporter harness.
        // The HTTP kernel can't be used here because this app registers a
        // Route::fallback() in bootstrap/app.php, which swallows throwaway test
        // routes (they 404). Invoking handle()/terminate() exercises the exact
        // 5xx branch — setStatus(STATUS_ERROR) — with a real Request + Response.
        $middleware = new OtelTrace;
        $request = Request::create('/boom', 'GET');

        $middleware->handle($request, fn ($req) => response('boom', 500));
        $middleware->terminate($request, response('boom', 500));

        $spans = $this->exporter->getSpans();
        $this->assertCount(1, $spans, 'Exactly one span must be ended via terminate()');

        $span = $spans[0];

        $this->assertSame(
            StatusCode::STATUS_ERROR,
            $span->getStatus()->getCode(),
            'A 5xx response must mark the span as ERROR'
        );

        $this->assertSame(
            500,
            $span->getAttributes()->get('http.response.status_code'),
            'The span must record the 500 status code attribute'
        );
    }

    /**
     * Find the first exported span whose url.path attribute matches $path.
     */
    private function findSpanByPath(string $path): ?\OpenTelemetry\SDK\Trace\ImmutableSpan
    {
        foreach ($this->exporter->getSpans() as $span) {
            if ($span->getAttributes()->get('url.path') === $path) {
                return $span;
            }
        }

        return null;
    }
}
