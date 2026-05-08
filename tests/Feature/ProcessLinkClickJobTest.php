<?php

namespace Tests\Feature;

use App\Jobs\ProcessLinkClickJob;
use App\Models\Click;
use App\Models\LinkUtm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

class ProcessLinkClickJobTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ip' => '8.8.8.8',
            'user_agent' => 'Mozilla/5.0 (iPhone) AppleWebKit Mobile',
            'referer' => 'https://www.google.com/search',
            'accept_language' => 'en-US',
            'query_params' => [],
            'http_response_ms' => 42.5,
        ], $overrides);
    }

    public function test_has_expected_retry_and_timeout_configuration(): void
    {
        $job = new ProcessLinkClickJob(1, []);

        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->backoff);
        $this->assertSame(30, $job->timeout);
    }

    public function test_handles_payload_and_creates_click_record(): void
    {
        $link = $this->makeLink();

        (new ProcessLinkClickJob($link->id, $this->payload()))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $this->assertSame(1, Click::where('link_id', $link->id)->count());

        $click = Click::where('link_id', $link->id)->first();
        $this->assertSame('8.8.8.8', $click->ip);
        $this->assertSame('mobile', $click->device);
        $this->assertSame('search', $click->click_source);
    }

    public function test_persists_utm_from_query_params(): void
    {
        $link = $this->makeLink();

        (new ProcessLinkClickJob($link->id, $this->payload([
            'query_params' => [
                'utm_source' => 'newsletter',
                'utm_medium' => 'email',
                'utm_campaign' => 'launch2026',
            ],
        ])))->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $utm = LinkUtm::where('click_id', $click->id)->first();

        $this->assertNotNull($utm);
        $this->assertSame('newsletter', $utm->utm_source);
        $this->assertSame('email', $utm->utm_medium);
        $this->assertSame('launch2026', $utm->utm_campaign);
    }

    public function test_extracts_utm_from_referer_when_query_params_absent(): void
    {
        $link = $this->makeLink();

        (new ProcessLinkClickJob($link->id, $this->payload([
            'referer' => 'https://partner.example.com/page?utm_source=partner&utm_medium=banner',
            'query_params' => [],
        ])))->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $utm = LinkUtm::where('click_id', $click->id)->first();

        $this->assertNotNull($utm);
        $this->assertSame('partner', $utm->utm_source);
        $this->assertSame('banner', $utm->utm_medium);
    }

    public function test_does_not_create_utm_row_when_no_utm_data(): void
    {
        $link = $this->makeLink();

        (new ProcessLinkClickJob($link->id, $this->payload([
            'referer' => 'https://example.com/without-utm',
            'query_params' => [],
        ])))->handle(app(\App\Services\Links\LinkTrackingService::class));

        $click = Click::where('link_id', $link->id)->first();
        $this->assertNotNull($click);
        $this->assertSame(0, LinkUtm::where('click_id', $click->id)->count());
    }

    public function test_increments_links_clicks_counter(): void
    {
        $link = $this->makeLink(['clicks' => 0]);

        (new ProcessLinkClickJob($link->id, $this->payload()))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $this->assertSame(1, (int) DB::table('links')->where('id', $link->id)->value('clicks'));
    }

    public function test_missing_link_is_logged_without_creating_click(): void
    {
        // Capture the warning call on the 'tracking' channel via a spy.
        $trackingSpy = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('tracking')->andReturn($trackingSpy);
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();

        (new ProcessLinkClickJob(99999, $this->payload()))
            ->handle(app(\App\Services\Links\LinkTrackingService::class));

        $this->assertSame(0, Click::count());
        $trackingSpy->shouldHaveReceived('warning')
            ->with('tracking.link_not_found', \Mockery::on(fn ($ctx) => ($ctx['link_id'] ?? null) === 99999));
    }

    public function test_payload_is_array_serializable_for_queue(): void
    {
        $payload = $this->payload();
        $job = new ProcessLinkClickJob(42, $payload);

        $restored = unserialize(serialize($job));

        $this->assertSame(42, $restored->linkId);
        $this->assertSame($payload, $restored->payload);
    }

    public function test_handle_populates_request_context_from_payload(): void
    {
        $link = $this->makeLink();
        \App\Logging\Context\RequestContext::clear();

        $spy = new class extends \App\Services\Links\LinkTrackingService
        {
            public ?\App\Logging\Context\RequestContext $capturedContext = null;

            public function registrarCliqueFromPayload(int $linkId, array $payload): void
            {
                $this->capturedContext = \App\Logging\Context\RequestContext::current();
            }
        };

        (new ProcessLinkClickJob($link->id, $this->payload(['request_id' => 'req_propagated_xy'])))
            ->handle($spy);

        $this->assertNotNull($spy->capturedContext);
        $this->assertSame('req_propagated_xy', $spy->capturedContext->requestId);
        $this->assertNull(\App\Logging\Context\RequestContext::current(), 'context must be cleared after handle()');
    }

    public function test_handle_generates_fallback_request_id_when_payload_lacks_one(): void
    {
        $link = $this->makeLink();
        \App\Logging\Context\RequestContext::clear();

        $spy = new class extends \App\Services\Links\LinkTrackingService
        {
            public ?\App\Logging\Context\RequestContext $capturedContext = null;

            public function registrarCliqueFromPayload(int $linkId, array $payload): void
            {
                $this->capturedContext = \App\Logging\Context\RequestContext::current();
            }
        };

        (new ProcessLinkClickJob($link->id, $this->payload()))->handle($spy);

        $this->assertNotNull($spy->capturedContext);
        $this->assertStringStartsWith('job_', $spy->capturedContext->requestId);
    }
}
