<?php

namespace Tests\Unit\Services\Links;

use App\Services\Links\LinkPreviewService;
use App\Services\Links\SafeFetchUrlValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the SSRF fix: LinkPreviewService::fetchPreview must run every target
 * URL through SafeFetchUrlValidator before issuing any outbound HTTP request,
 * so the authenticated url-meta endpoint and FetchLinkPreviewJob cannot be used
 * to reach internal/cloud-metadata hosts.
 */
class LinkPreviewServiceSsrfTest extends TestCase
{
    public function test_unsafe_url_returns_empty_preview_and_makes_no_http_call(): void
    {
        Http::fake();

        // Stub the validator to report the URL as unsafe.
        $this->app->instance(SafeFetchUrlValidator::class, new class extends SafeFetchUrlValidator
        {
            public function isSafe(string $url): bool
            {
                return false;
            }
        });

        $result = (new LinkPreviewService)->fetchPreview('http://169.254.169.254/latest/meta-data/');

        // No outbound fetch happened.
        Http::assertNothingSent();

        // Empty metadata, favicon still derived from the host string (no HTTP).
        $this->assertNull($result['og_title']);
        $this->assertNull($result['og_image_url']);
        $this->assertNull($result['og_description']);
        $this->assertNotNull($result['favicon_url']);
    }

    public function test_real_internal_ip_is_blocked_without_stub(): void
    {
        Http::fake();

        // 169.254.169.254 is a literal link-local IP — SafeFetchUrlValidator
        // rejects it deterministically (no DNS needed).
        $result = (new LinkPreviewService)->fetchPreview('http://169.254.169.254/latest/meta-data/');

        Http::assertNothingSent();
        $this->assertNull($result['og_title']);
    }

    public function test_safe_url_is_fetched_and_parsed(): void
    {
        $this->app->instance(SafeFetchUrlValidator::class, new class extends SafeFetchUrlValidator
        {
            public function isSafe(string $url): bool
            {
                return true;
            }
        });

        // Include og:image so the Twitterbot fallback fetch (triggered only when
        // og_image_url is empty) does not fire — keeps the request count at 1.
        Http::fake([
            '*' => Http::response(
                '<html><head>'
                .'<meta property="og:title" content="Hello">'
                .'<meta property="og:image" content="https://example.com/img.png">'
                .'</head></html>',
                200
            ),
        ]);

        $result = (new LinkPreviewService)->fetchPreview('https://example.com/page');

        Http::assertSentCount(1);
        $this->assertSame('Hello', $result['og_title']);
        $this->assertSame('https://example.com/img.png', $result['og_image_url']);
    }
}
