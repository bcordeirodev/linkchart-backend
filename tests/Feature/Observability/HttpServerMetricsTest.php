<?php

namespace Tests\Feature\Observability;

use App\Observability\Otel;
use Tests\TestCase;

class HttpServerMetricsTest extends TestCase
{
    /** recordHttpServer is a no-op (no throw) when telemetry is disabled. */
    public function test_record_http_server_is_noop_when_disabled(): void
    {
        config(['otel.enabled' => false]);
        Otel::recordHttpServer('links.index', 'GET', 200, 0.012);
        $this->assertFalse(Otel::enabled());
    }

    /** status class derivation is correct (covers the cardinality-capping logic). */
    public function test_status_class_buckets(): void
    {
        $this->assertSame('2xx', Otel::statusClass(204));
        $this->assertSame('4xx', Otel::statusClass(404));
        $this->assertSame('5xx', Otel::statusClass(500));
        $this->assertSame('3xx', Otel::statusClass(302));
    }
}
