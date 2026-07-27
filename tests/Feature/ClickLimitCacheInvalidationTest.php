<?php

namespace Tests\Feature;

use App\Jobs\ProcessLinkClickJob;
use App\Models\Link;
use App\Services\Links\LinkTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

/**
 * Guards the click-limit vs slug-cache interaction on the /r/{slug} hot path.
 *
 * Link::findActiveBySlugCached() serves the redirect from a 10-minute cache,
 * and the links.clicks counter is incremented by a direct DB query inside the
 * tracking job precisely so it does NOT bust that cache. Consequence (audit
 * finding): a link at its click_limit kept redirecting for up to 10 minutes,
 * because the cached model still carried the stale counter.
 *
 * The fix: when (and only when) the increment crosses click_limit, the
 * tracking service forgets the slug cache entry, so the very next GET
 * /r/{slug} reloads from the DB and serves the limit-reached page.
 */
class ClickLimitCacheInvalidationTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    /**
     * Minimal serializable click payload, mirroring what
     * RedirectController::dispatchTracking() enqueues.
     *
     * @param  array<string, mixed>  $overrides  Payload keys to override.
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'ip' => '8.8.8.8',
            'user_agent' => 'Mozilla/5.0 (iPhone) AppleWebKit Mobile',
            'referer' => null,
            'accept_language' => 'en-US',
            'query_params' => [],
            'http_response_ms' => 42.5,
        ], $overrides);
    }

    /**
     * Runs the click-tracking job inline for $link, exactly as the queue
     * worker would.
     */
    private function processClick(Link $link): void
    {
        (new ProcessLinkClickJob($link->id, $this->payload()))
            ->handle(app(LinkTrackingService::class));
    }

    /**
     * A link with click_limit=1: after the single allowed click is processed,
     * the NEXT redirect must already serve the limit-reached page — without
     * waiting for the 10-minute slug-cache TTL.
     */
    public function test_next_redirect_after_limit_is_reached_is_blocked_immediately(): void
    {
        $link = $this->makeLink(['click_limit' => 1]);

        // First hit: allowed, primes the slug cache with clicks=0.
        Queue::fake();
        $this->get("/r/{$link->slug}")->assertRedirect($link->original_url);

        // The queued tracking job runs (worker side): counter reaches the limit.
        $this->processClick($link);

        // Next visitor must be blocked NOW, not after the cache TTL.
        $response = $this->get("/r/{$link->slug}");
        $response->assertNotFound();
        $response->assertSee('Este link atingiu o limite de cliques');
    }

    /**
     * Crossing the limit must forget the slug cache entry itself (the
     * mechanism behind the behavior above).
     */
    public function test_crossing_the_limit_forgets_the_slug_cache_entry(): void
    {
        $link = $this->makeLink(['click_limit' => 1]);

        $this->assertNotNull(Link::findActiveBySlugCached($link->slug));
        $this->assertTrue(Cache::has(Link::slugCacheKey($link->slug)));

        $this->processClick($link);

        $this->assertFalse(Cache::has(Link::slugCacheKey($link->slug)));
    }

    /**
     * Guard for the common cases: a click on an unlimited link, or on a
     * limited link still below its limit, must NOT bust the slug cache —
     * cache stability on the hot path is the whole point of the direct
     * counter increment.
     */
    public function test_clicks_below_the_limit_or_without_limit_keep_the_cache(): void
    {
        $unlimited = $this->makeLink(['click_limit' => null]);
        $belowLimit = $this->makeLink(['slug' => 'below1', 'click_limit' => 5]);

        Link::findActiveBySlugCached($unlimited->slug);
        Link::findActiveBySlugCached($belowLimit->slug);

        $this->processClick($unlimited);
        $this->processClick($belowLimit);

        $this->assertTrue(Cache::has(Link::slugCacheKey($unlimited->slug)));
        $this->assertTrue(Cache::has(Link::slugCacheKey($belowLimit->slug)));
    }
}
