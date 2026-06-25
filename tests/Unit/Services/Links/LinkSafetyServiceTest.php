<?php

namespace Tests\Unit\Services\Links;

use App\Logging\AppLogger;
use App\Services\Links\LinkSafetyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Covers LinkSafetyService::checkUrl, the Google Safe Browsing v4 integration.
 *
 * The key behaviour under test is the diagnosability of API failures: when the
 * upstream returns a non-2xx response the service must fail open AND log the
 * real HTTP status and response body, so a misconfigured production key surfaces
 * its actual cause (403 PERMISSION_DENIED, 400 API_KEY_INVALID, 429, ...) instead
 * of an opaque "safety_api_error_response".
 */
class LinkSafetyServiceTest extends TestCase
{
    /**
     * A non-2xx response fails open and logs the upstream status + body.
     */
    public function test_non_2xx_response_fails_open_and_logs_status_and_body(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);

        Http::fake([
            '*' => Http::response(
                '{"error":{"code":403,"message":"Safe Browsing API has not been used","status":"PERMISSION_DENIED"}}',
                403,
            ),
        ]);

        $captured = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function (string $event, array $context = []) use (&$captured) {
            $captured = ['event' => $event, 'context' => $context];

            return true;
        });

        $result = (new LinkSafetyService)->checkUrl('https://www.google.com');

        // Fail-open contract preserved.
        $this->assertTrue($result['safe']);
        $this->assertFalse($result['api_available']);

        // The diagnostic context now carries the real status and body.
        $this->assertSame(AppLogger::SAFETY_API_ERROR, $captured['event']);
        $this->assertSame(403, $captured['context']['status'] ?? null);
        $this->assertStringContainsString('PERMISSION_DENIED', (string) ($captured['context']['body'] ?? ''));
    }

    /**
     * A 200 response containing a threat match flags the URL as unsafe.
     */
    public function test_match_flags_url_as_unsafe(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);

        Http::fake([
            '*' => Http::response(
                '{"matches":[{"threatType":"SOCIAL_ENGINEERING"}]}',
                200,
            ),
        ]);

        $result = (new LinkSafetyService)->checkUrl('https://phishing.example');

        $this->assertFalse($result['safe']);
        $this->assertTrue($result['api_available']);
        $this->assertContains('phishing', $result['threats']);
    }

    /**
     * A missing API key skips the check and treats the URL as safe (fail-open).
     */
    public function test_missing_key_skips_check_and_makes_no_http_call(): void
    {
        config(['services.google_safe_browsing.key' => null]);

        Http::fake();

        $result = (new LinkSafetyService)->checkUrl('https://www.google.com');

        Http::assertNothingSent();
        $this->assertTrue($result['safe']);
        $this->assertFalse($result['api_available']);
    }

    /**
     * Otel::recordSafetyCheck is callable without throwing when telemetry is disabled.
     */
    public function test_record_safety_check_is_noop_when_disabled(): void
    {
        config(['otel.enabled' => false]);
        \App\Observability\Otel::recordSafetyCheck('bad_response');
        $this->addToAssertionCount(1);
    }
}
