<?php

namespace App\Contracts\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;

/**
 * Contract for per-link business insights analytics.
 *
 * Generates automated, human-readable insights from click data using a
 * Strategy-pattern registry of `InsightGeneratorInterface` implementations
 * (Geographic, Device, Temporal, Performance, Diversity, Security,
 * Engagement, Retention). Each generator emits zero or more insight objects
 * with a priority level and a descriptive message.
 *
 * Concrete implementation: {@see \App\Services\Analytics\InsightsAnalyticsService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(InsightsAnalyticsInterface::class, InsightsAnalyticsService::class)`.
 *
 * Consumed by {@see \App\Services\Analytics\LinkAnalyticsOrchestrator} and
 * `AnalyticsController`'s `insights` endpoint.
 */
interface InsightsAnalyticsInterface
{
    /**
     * Return automated business insights and supporting analytics for the given link.
     *
     * Throws `\Illuminate\Database\Eloquent\ModelNotFoundException` if `$linkId`
     * does not exist. When the link has no clicks, returns an empty insights list
     * with all summary counters set to zero.
     *
     * Return shape:
     * ```
     * [
     *   'insights' => array<int, array{type: string, priority: string, message: string, data: array}>,
     *   'summary'  => array{
     *       total_insights: int,
     *       high_priority: int,
     *       medium_priority: int,
     *       low_priority: int,
     *   },
     *   'analytics_data' => array{
     *       retention: array,
     *       session_depth: array,
     *       traffic_sources: array,
     *       navigation_context: array,
     *       http_protocol: array,
     *       quality: array,
     *   },
     * ]
     * ```
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed> Insights payload as described above.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkInsightsAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array;
}
