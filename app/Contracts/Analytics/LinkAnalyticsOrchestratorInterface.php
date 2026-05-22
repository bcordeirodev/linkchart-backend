<?php

namespace App\Contracts\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;

/**
 * Contract for the analytics orchestrator that fans out to domain-specific services.
 *
 * Acts as a thin façade over five analytics services — dashboard, geographic,
 * temporal, audience, and insights — injected as constructor dependencies. The
 * orchestrator routes each endpoint call to the appropriate service without
 * adding business logic of its own, keeping each service independently testable.
 *
 * Concrete implementation: {@see \App\Services\Analytics\LinkAnalyticsOrchestrator}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(LinkAnalyticsOrchestratorInterface::class, LinkAnalyticsOrchestrator::class)`.
 *
 * Injected by `AnalyticsController` to serve all analytics endpoints under
 * `GET /api/analytics/link/{id}/*`.
 */
interface LinkAnalyticsOrchestratorInterface
{
    /**
     * Return a comprehensive analytics payload merging geographic, temporal,
     * audience, and insights data for the given link.
     *
     * When the link has no clicks, returns a minimal payload with `has_data = false`
     * and a human-readable `message`. When clicks exist, the payload includes an
     * `overview` summary and delegates to the geographic, temporal, audience, and
     * insights services. Dashboard data is NOT included in this response.
     *
     * Throws `\Illuminate\Database\Eloquent\ModelNotFoundException` if `$linkId`
     * does not exist.
     *
     * Return shape:
     * ```
     * [
     *   'has_data'   => bool,
     *   'link_info'  => array{id: int, short_url: string, original_url: string, title: ?string, is_active: bool, created_at: mixed, clicks: int},
     *   'overview'   => array{total_clicks: int, unique_visitors: int, countries_reached: int, avg_daily_clicks: float},   // only when has_data = true
     *   'geographic' => array,   // see GeographicAnalyticsInterface
     *   'temporal'   => array,   // see TemporalAnalyticsInterface
     *   'audience'   => array,   // see AudienceAnalyticsInterface
     *   'insights'   => array,   // insight objects from InsightsAnalyticsInterface
     * ]
     * ```
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @return array<string, mixed> Comprehensive analytics payload.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getComprehensiveLinkAnalytics(int $linkId): array;

    /**
     * Return dashboard analytics for the given link, optionally scoped by filters.
     *
     * Delegates directly to {@see \App\Contracts\Analytics\DashboardAnalyticsInterface::getLinkDashboardAnalytics()}.
     * See that interface for the full return shape.
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed> Dashboard analytics payload.
     */
    public function getLinkDashboardAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array;

    /**
     * Return geographic analytics (heatmap, countries, states, cities) for the given link.
     *
     * Delegates directly to {@see \App\Contracts\Analytics\GeographicAnalyticsInterface::getLinkGeographicAnalytics()}.
     * See that interface for the full return shape.
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  ?string  $continent  ISO 2-letter continent code ('NA','SA','EU','AS','AF','OC'), or null for all continents.
     * @param  int  $minClicks  Omit rows where clicks < $minClicks from aggregated results.
     * @return array<string, mixed> Geographic analytics payload.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkGeographicAnalytics(
        int $linkId,
        ?AnalyticsFilters $filters = null,
        ?string $continent = null,
        int $minClicks = 0
    ): array;

    /**
     * Return temporal analytics (clicks by hour, day of week, seasonality) for the given link.
     *
     * Delegates directly to {@see \App\Contracts\Analytics\TemporalAnalyticsInterface::getLinkTemporalAnalytics()}.
     * See that interface for the full return shape.
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'. Filters clicks by is_weekend/is_business_hours before aggregating.
     * @return array<string, mixed> Temporal analytics payload.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkTemporalAnalytics(
        int $linkId,
        ?AnalyticsFilters $filters = null,
        string $segment = 'all'
    ): array;

    /**
     * Return audience analytics (devices, browsers, OS, engagement) for the given link.
     *
     * Delegates directly to {@see \App\Contracts\Analytics\AudienceAnalyticsInterface::getLinkAudienceAnalytics()}.
     * See that interface for the full return shape.
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed> Audience analytics payload.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkAudienceAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array;

    /**
     * Return business insights (automated insights + summary + supporting analytics) for the given link.
     *
     * Delegates directly to {@see \App\Contracts\Analytics\InsightsAnalyticsInterface::getLinkInsightsAnalytics()}.
     * See that interface for the full return shape.
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed> Insights analytics payload.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkInsightsAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array;
}
