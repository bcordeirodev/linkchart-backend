<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\AudienceAnalyticsInterface;
use App\Contracts\Analytics\DashboardAnalyticsInterface;
use App\Contracts\Analytics\GeographicAnalyticsInterface;
use App\Contracts\Analytics\InsightsAnalyticsInterface;
use App\Contracts\Analytics\LinkAnalyticsOrchestratorInterface;
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\Cache;

/**
 * Thin orchestrator that fans out analytics requests to specialized services.
 *
 * @see \App\Contracts\Analytics\LinkAnalyticsOrchestratorInterface
 *
 * Injected by AnalyticsController to serve all analytics endpoints. Each public
 * method delegates directly to the appropriate specialized service:
 *   - getLinkDashboardAnalytics → DashboardAnalyticsInterface
 *   - getLinkGeographicAnalytics → GeographicAnalyticsInterface
 *   - getLinkTemporalAnalytics → TemporalAnalyticsInterface
 *   - getLinkAudienceAnalytics → AudienceAnalyticsInterface
 *   - getLinkInsightsAnalytics → InsightsAnalyticsInterface
 *   - getComprehensiveLinkAnalytics → fans out to all five and merges results
 *
 * No aggregation logic lives in this class — it exists solely to provide a
 * single injectable contract for the controller and to assemble the comprehensive
 * endpoint payload.
 *
 * Side effects: none directly — all side effects belong to the delegate services.
 */
class LinkAnalyticsOrchestrator implements LinkAnalyticsOrchestratorInterface
{
    /** Seconds an analytics payload stays cached. Staleness up to this window is acceptable. */
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly DashboardAnalyticsInterface $dashboard,
        private readonly GeographicAnalyticsInterface $geographic,
        private readonly TemporalAnalyticsInterface $temporal,
        private readonly AudienceAnalyticsInterface $audience,
        private readonly InsightsAnalyticsInterface $insights,
    ) {}

    /**
     * Returns a merged payload combining overview, geographic, temporal, audience, and insights.
     *
     * Returns a short has_data=false payload if the link has no clicks yet.
     * Otherwise fans out to all five specialized services and merges the results.
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed> Keys: has_data, link_info, overview, geographic, temporal, audience, insights.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If link does not exist.
     */
    public function getComprehensiveLinkAnalytics(int $linkId): array
    {
        return $this->remember(__FUNCTION__, $linkId, [], fn () => $this->computeComprehensiveLinkAnalytics($linkId));
    }

    /** Uncached producer for getComprehensiveLinkAnalytics. */
    private function computeComprehensiveLinkAnalytics(int $linkId): array
    {
        $link = Link::findOrFail($linkId);

        if (! Click::where('link_id', $linkId)->exists()) {
            return [
                'has_data' => false,
                'link_info' => $this->linkInfo($link),
                'message' => 'Analytics will be available after the first clicks on your link.',
            ];
        }

        $total = Click::where('link_id', $linkId)->count();
        $unique = Click::where('link_id', $linkId)->distinct('ip')->count();
        $regions = Click::where('link_id', $linkId)->whereNotNull('country')->where('country', '!=', 'localhost')->distinct('country')->count();

        $insightsPayload = $this->insights->getLinkInsightsAnalytics($linkId);

        return [
            'has_data' => true,
            'link_info' => $this->linkInfo($link),
            'overview' => [
                'total_clicks' => $total,
                'unique_visitors' => $unique,
                'countries_reached' => $regions,
                'avg_daily_clicks' => $total > 0 ? round($total / 30, 1) : 0,
            ],
            'geographic' => $this->geographic->getLinkGeographicAnalytics($linkId),
            'temporal' => $this->temporal->getLinkTemporalAnalytics($linkId),
            'audience' => $this->audience->getLinkAudienceAnalytics($linkId),
            'insights' => $insightsPayload['insights'] ?? [],
        ];
    }

    /**
     * Delegates to DashboardAnalyticsService::getLinkDashboardAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed>
     */
    public function getLinkDashboardAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, $linkId, [($filters ?? new AnalyticsFilters)->cacheKey()],
            fn () => $this->dashboard->getLinkDashboardAnalytics($linkId, $filters));
    }

    /**
     * Delegates to GeographicAnalyticsService::getLinkGeographicAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  ?string  $continent  ISO 2-letter continent code ('NA','SA','EU','AS','AF','OC'), or null for all continents.
     * @param  int  $minClicks  Omit rows where clicks < $minClicks from aggregated results.
     * @return array<string, mixed>
     */
    public function getLinkGeographicAnalytics(
        int $linkId,
        ?AnalyticsFilters $filters = null,
        ?string $continent = null,
        int $minClicks = 0
    ): array {
        return $this->remember(__FUNCTION__, $linkId, [($filters ?? new AnalyticsFilters)->cacheKey(), (string) $continent, (string) $minClicks],
            fn () => $this->geographic->getLinkGeographicAnalytics($linkId, $filters, $continent, $minClicks));
    }

    /**
     * Delegates to TemporalAnalyticsService::getLinkTemporalAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'. Filters clicks by is_weekend/is_business_hours before aggregating.
     * @return array<string, mixed>
     */
    public function getLinkTemporalAnalytics(
        int $linkId,
        ?AnalyticsFilters $filters = null,
        string $segment = 'all'
    ): array {
        return $this->remember(__FUNCTION__, $linkId, [($filters ?? new AnalyticsFilters)->cacheKey(), $segment],
            fn () => $this->temporal->getLinkTemporalAnalytics($linkId, $filters, $segment));
    }

    /**
     * Delegates to AudienceAnalyticsService::getLinkAudienceAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed>
     */
    public function getLinkAudienceAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, $linkId, [($filters ?? new AnalyticsFilters)->cacheKey()],
            fn () => $this->audience->getLinkAudienceAnalytics($linkId, $filters));
    }

    /**
     * Delegates to InsightsAnalyticsService::getLinkInsightsAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed>
     */
    public function getLinkInsightsAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        return $this->remember(__FUNCTION__, $linkId, [($filters ?? new AnalyticsFilters)->cacheKey()],
            fn () => $this->insights->getLinkInsightsAnalytics($linkId, $filters));
    }

    /**
     * Delegates to TemporalAnalyticsService::getAdvancedTemporalAnalytics with
     * the standard 60s cache.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state, null = no filter.
     * @param  string  $segment  'all'|'weekday'|'weekend'|'business'.
     * @return array<string, mixed>
     */
    public function getAdvancedTemporalAnalytics(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        return $this->remember(__FUNCTION__, $linkId, [($filters ?? new AnalyticsFilters)->cacheKey(), $segment],
            fn () => $this->temporal->getAdvancedTemporalAnalytics($linkId, $filters, $segment));
    }

    /**
     * Memoize an analytics payload in the cache for CACHE_TTL_SECONDS.
     *
     * Key shape: analytics:{linkId}:{method}:{md5 of extra params}. Three outcomes:
     *   1. Cache hit — returns cached value without calling producer.
     *   2. Cache write fails after producer succeeds — serves the computed result without caching.
     *   3. Producer itself throws — propagates the exception without re-running it.
     *   4. Cache read fails before producer runs — degrades to uncached execution.
     *
     * @param  string  $method  Calling method name (__FUNCTION__).
     * @param  int  $linkId  Link primary key.
     * @param  array<int, string>  $extra  Filter/param strings that shape the payload.
     * @param  \Closure(): array<string, mixed>  $callback  Producer executed on cache miss.
     * @return array<string, mixed>
     */
    private function remember(string $method, int $linkId, array $extra, \Closure $callback): array
    {
        $key = sprintf('analytics:%d:%s:%s', $linkId, $method, md5(implode('|', $extra)));

        $started = false;
        $finished = false;
        $result = [];

        $producer = function () use (&$started, &$finished, &$result, $callback) {
            $started = true;
            $result = $callback();
            $finished = true;

            return $result;
        };

        try {
            return Cache::remember($key, self::CACHE_TTL_SECONDS, $producer);
        } catch (\Throwable $e) {
            if ($finished) {
                // Compute succeeded but the cache write failed — serve the result.
                return $result;
            }

            if ($started) {
                // The producer itself threw — propagate, never re-run it.
                throw $e;
            }

            // Cache read failed before producing — degrade to uncached.
            return $callback();
        }
    }

    private function linkInfo(Link $link): array
    {
        return [
            'id' => $link->id,
            'title' => $link->title,
            'short_url' => $link->getShortedUrl(),
            'original_url' => $link->original_url,
            'clicks' => $link->clicks,
            'is_active' => $link->is_active,
            'created_at' => $link->created_at,
        ];
    }
}
