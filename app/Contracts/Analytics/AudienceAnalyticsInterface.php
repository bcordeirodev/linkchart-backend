<?php

namespace App\Contracts\Analytics;

/**
 * Contract for per-link audience analytics (devices, browsers, OS, engagement).
 *
 * Returns breakdowns of visitor characteristics derived from User-Agent strings,
 * Client Hints headers, and behavioural columns stored in the `clicks` table.
 *
 * Concrete implementation: {@see \App\Services\Analytics\AudienceAnalyticsService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(AudienceAnalyticsInterface::class, AudienceAnalyticsService::class)`.
 *
 * Consumed by {@see \App\Services\Analytics\LinkAnalyticsOrchestrator} and
 * `AnalyticsController`'s `audience` endpoint.
 */
interface AudienceAnalyticsInterface
{
    // NOTE: AnalyticsFilters is imported by concrete implementations; the interface
    // uses the fully-qualified class name in the signature below to avoid requiring
    // an import inside the interface file.
    /**
     * Return a full audience analytics payload for the given link.
     *
     * Throws `\Illuminate\Database\Eloquent\ModelNotFoundException` if `$linkId`
     * does not exist. Returns an array with all keys set to empty values when the
     * link exists but has no recorded clicks that match the supplied filters.
     *
     * Return shape (keys always present):
     * ```
     * [
     *   'device_breakdown'              => array<int, array{device: string, clicks: int, percentage: float}>,
     *   'browser_breakdown'             => array<int, array{browser: string, clicks: int}>,
     *   'os_breakdown'                  => array<int, array{os: string, clicks: int}>,
     *   'browsers'                      => array<int, array{browser: string, version: ?string, clicks: int, percentage: float}>,
     *   'operating_systems'             => array<int, array{os: string, version: ?string, clicks: int, percentage: float}>,
     *   'device_performance'            => array<int, array{device: string, avg_response_time: float, min_response_time: float, max_response_time: float, total_clicks: int}>,
     *   'languages'                     => array<int, array{language: string, clicks: int, percentage: float}>,
     *   'language_breakdown'            => array<int, array{language: string, region: ?string, clicks: int, percentage: float}>,
     *   'platform_breakdown'            => array<int, array{platform: string, clicks: int, percentage: float}>,
     *   'data_saver'                    => array{clicks: int, total: int, percentage: float},
     *   'connection_type_breakdown'     => array<int, array{type: string, clicks: int, percentage: float}>,
     *   'rendering_engine'              => array<int, array{engine: string, clicks: int, percentage: float}>,
     *   'navigation_context_breakdown'  => array<int, array{context: string, clicks: int, percentage: float}>,
     *   'return_visitor_stats'          => array{return_rate: float, new_rate: float, avg_session_clicks: float},
     *   'quality_breakdown'             => array{tiers: array, bot_clicks: int, bot_percentage: float, avg_fingerprint_score: float},
     * ]
     * ```
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?\App\DTOs\Analytics\AnalyticsFilters  $filters  Date-range and bot-exclusion filters. Null = no filter applied.
     * @return array<string, mixed> Audience analytics payload as described above.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkAudienceAnalytics(int $linkId, ?\App\DTOs\Analytics\AnalyticsFilters $filters = null): array;
}
