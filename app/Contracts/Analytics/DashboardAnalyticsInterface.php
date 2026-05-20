<?php

namespace App\Contracts\Analytics;

/**
 * Contract for the main dashboard analytics view of a single link.
 *
 * Aggregates summary statistics, geographic, temporal, and audience data into
 * a single response used by the frontend dashboard page. Optionally scopes the
 * result to the last N hours via the `$hours` parameter.
 *
 * Concrete implementation: {@see \App\Services\Analytics\DashboardAnalyticsService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(DashboardAnalyticsInterface::class, DashboardAnalyticsService::class)`.
 *
 * Consumed by {@see \App\Services\Analytics\LinkAnalyticsOrchestrator} and
 * `AnalyticsController`'s `dashboard` endpoint.
 */
interface DashboardAnalyticsInterface
{
    /**
     * Return a combined dashboard analytics payload for the given link.
     *
     * When `$hours > 0`, all sub-queries are filtered to the window
     * `[now() - $hours, now()]`. When `$hours = 0` (default), all recorded
     * clicks are included. Returns an array with all keys set to empty / zero
     * values if `$linkId` does not exist or has no clicks.
     *
     * Return shape (top-level keys always present):
     * ```
     * [
     *   'summary'        => array{
     *       total_clicks: int, total_links: int, active_links: int,
     *       unique_visitors: int, success_rate: float, avg_response_time: float,
     *       countries_reached: int, links_with_traffic: int,
     *       viral_rank: array{current_rank: string, distribution: array},
     *       quality: array{organic: int, suspicious: int, likely_fraud: int, unscored: int, organic_percentage: float},
     *   },
     *   'link_info'      => array{id: int, title: ?string, short_url: string, original_url: string, clicks: int, is_active: bool, created_at: mixed}|null,
     *   'temporal_data'  => array{
     *       clicks_by_hour: array<int, array{hour: int, clicks: int, label: string}>,
     *       clicks_by_day_of_week: array<int, array{day: int, day_name: string, clicks: int}>,
     *       hourly_patterns_local: array,
     *       weekend_vs_weekday: array,
     *       business_hours_analysis: array,
     *   },
     *   'geographic_data' => array{
     *       heatmap_data: array, top_countries: array, top_states: array, top_cities: array,
     *   },
     *   'audience_data'  => array{
     *       device_breakdown: array, browser_breakdown: array, os_breakdown: array,
     *       browsers: array, operating_systems: array, device_performance: array, languages: array,
     *   },
     * ]
     * ```
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  int  $hours  Time window in hours (0 = all time).
     * @return array<string, mixed> Dashboard analytics payload as described above.
     */
    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array;
}
