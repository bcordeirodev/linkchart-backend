<?php

namespace Tests\Feature\Observability;

use App\Jobs\ProcessLinkClickJob;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Tests\TestCase;

class JobSpanTest extends TestCase
{
    use RefreshDatabase;

    private ArrayObject $storage;

    private ScopeInterface $scope;

    protected function setUp(): void
    {
        parent::setUp();
        // Force-enable a local in-memory tracer for this test only.
        config()->set('otel.enabled', true);
        $this->storage = new ArrayObject;
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(new InMemoryExporter($this->storage))
        );
        $this->scope = Configurator::create()->withTracerProvider($tracerProvider)->activate();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
        parent::tearDown();
    }

    public function test_job_emits_a_process_click_span(): void
    {
        $link = \App\Models\Link::factory()->create();

        (new ProcessLinkClickJob($link->id, [
            'request_id' => 'req_test',
            'dedup_key' => 'clk_test',
            'ip' => '8.8.8.8',
            'user_agent' => 'phpunit',
        ]))->handle(app(\App\Services\Links\LinkTrackingService::class));

        $names = array_map(fn ($s) => $s->getName(), iterator_to_array($this->storage));
        $this->assertContains('process-click', $names);
    }
}
