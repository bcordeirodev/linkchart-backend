<?php

namespace Tests\Feature\Observability;

use App\Logging\Processors\OtelContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class LogTraceIdTest extends TestCase
{
    public function test_processor_adds_no_trace_id_when_span_invalid(): void
    {
        $processor = new OtelContextProcessor;
        $record = new LogRecord(new \DateTimeImmutable, 'redirect', Level::Info, 'x', [], []);

        $out = $processor($record);

        // With no active valid span, processor leaves extra untouched (no crash).
        $this->assertArrayNotHasKey('trace_id', $out->extra);
    }
}
