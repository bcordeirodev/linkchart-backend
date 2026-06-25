<?php

namespace Tests\Unit\Services\Links;

use App\Services\Links\RedirectMetadataFetcher;
use App\Services\Links\SafeFetchUrlValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the redirect hot-path latency cap: RedirectMetadataFetcher::fetch()
 * must stop trying additional UA strategies once its wall-clock budget is
 * exhausted, and must return the _fetch_failed contract so the controller can
 * pass the bot through. The clock is injected so the deadline is deterministic.
 */
class RedirectMetadataFetcherTest extends TestCase
{
    /** A validator stub that always reports the URL as safe (no DNS/SSRF in unit tests). */
    private function safeValidator(): SafeFetchUrlValidator
    {
        return new class extends SafeFetchUrlValidator
        {
            public function isSafe(string $url): bool
            {
                return true;
            }
        };
    }

    /** A monotonic fake clock that advances $stepSeconds on every call. */
    private function advancingClock(float $stepSeconds): \Closure
    {
        $now = 0.0;

        return function () use (&$now, $stepSeconds): float {
            $value = $now;
            $now += $stepSeconds;

            return $value;
        };
    }

    public function test_budget_trip_skips_fallback_strategies_and_returns_fetch_failed(): void
    {
        Http::preventStrayRequests();
        // Primary fetch returns 403 -> $metadata is null and a fallback would normally run.
        Http::fake(['*' => Http::response('blocked', 403)]);

        // Advancing 10s per call: start=0, every budget check is >= 4.5 -> tripped.
        $fetcher = new RedirectMetadataFetcher($this->safeValidator(), $this->advancingClock(10.0));

        $result = $fetcher->fetch('https://example.com/dead');

        // Only the primary facebookexternalhit request was sent; fallbacks skipped.
        Http::assertSentCount(1);
        $this->assertTrue($result['_fetch_failed'] ?? false);
    }

    public function test_no_trip_runs_all_three_ua_strategies(): void
    {
        Http::preventStrayRequests();
        // Every fetch returns 403 (no og:image) so all three UA strategies are attempted.
        Http::fake(['*' => Http::response('blocked', 403)]);

        // Clock never advances -> budget never exhausted.
        $fetcher = new RedirectMetadataFetcher($this->safeValidator(), fn (): float => 0.0);

        $result = $fetcher->fetch('https://example.com/slow-but-alive');

        Http::assertSentCount(3);
        $this->assertTrue($result['_fetch_failed'] ?? false);
    }

    public function test_early_success_makes_a_single_request(): void
    {
        Http::preventStrayRequests();
        $html = '<html><head><meta property="og:image" content="https://example.com/i.png">'
            .'<meta property="og:title" content="Hi"></head></html>';
        Http::fake(['*' => Http::response($html, 200)]);

        $fetcher = new RedirectMetadataFetcher($this->safeValidator(), fn (): float => 0.0);

        $result = $fetcher->fetch('https://example.com/good');

        Http::assertSentCount(1);
        $this->assertArrayNotHasKey('_fetch_failed', $result);
        $this->assertSame('https://example.com/i.png', $result['og_image']);
    }
}
