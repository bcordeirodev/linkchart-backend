<?php

namespace App\Contracts\Analytics;

/**
 * Contract for per-link temporal analytics (time-of-day, day-of-week, seasonality).
 *
 * Aggregates click data from the `clicks` table using the temporal columns
 * (`hour_of_day`, `day_of_week`, `is_weekend`, `is_business_hours`, `created_at`,
 * and holiday/season fields) to reveal when clicks happen and how traffic patterns
 * evolve over time.
 *
 * Concrete implementation: {@see \App\Services\Analytics\TemporalAnalyticsService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(TemporalAnalyticsInterface::class, TemporalAnalyticsService::class)`.
 *
 * Consumed by {@see \App\Services\Analytics\LinkAnalyticsOrchestrator} and
 * `AnalyticsController`'s `temporal` endpoint.
 */
interface TemporalAnalyticsInterface
{
    /**
     * Return standard temporal analytics for the given link (all clicks, all time).
     *
     * Throws `\Illuminate\Database\Eloquent\ModelNotFoundException` if `$linkId`
     * does not exist. Returns an array with all keys set to empty values when the
     * link exists but has no recorded clicks.
     *
     * Return shape (keys always present):
     * ```
     * [
     *   'clicks_by_hour'          => array<int, array{hour: int, label: string, clicks: int}>,
     *   'clicks_by_day_of_week'   => array<int, array{day: int, day_name: string, clicks: int}>,
     *   'hourly_patterns_local'   => array<int, array{hour: int, clicks: int, avg_response_time: float, unique_visitors: int}>,
     *   'weekend_vs_weekday'      => array{
     *       weekend: array{clicks: int, unique_visitors: int, avg_response_time: float, percentage: float},
     *       weekday: array{clicks: int, unique_visitors: int, avg_response_time: float, percentage: float},
     *   },
     *   'business_hours_analysis' => array{
     *       business_hours: array{clicks: int, unique_visitors: int, avg_response_time: float, avg_session_depth: float, time_range: string},
     *       non_business_hours: array{clicks: int, unique_visitors: int, avg_response_time: float, avg_session_depth: float, time_range: string},
     *   },
     *   'holiday_impact'          => array{holiday_clicks: int, non_holiday_clicks: int, holiday_percentage: float, top_holidays: array},
     *   'seasonal_distribution'   => array<int, array{season: string, clicks: int, percentage: float}>,
     * ]
     * ```
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @return array<string, mixed> Temporal analytics payload as described above.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkTemporalAnalytics(int $linkId): array;

    /**
     * Return advanced temporal analytics with extended pattern analysis for the given link.
     *
     * Loads the full `clicks` collection into memory for in-PHP aggregation,
     * making this suitable for background/export use but not for per-request
     * latency-sensitive endpoints. Operates over all recorded clicks with no
     * time-window parameter.
     *
     * Return shape:
     * ```
     * [
     *   'hourly_patterns'      => array,
     *   'daily_patterns'       => array,
     *   'weekly_trends'        => array,
     *   'monthly_trends'       => array,
     *   'peak_analysis'        => array,
     *   'timezone_analysis'    => array,
     *   'heatmap_data'         => array,   // hour × day matrix
     *   'daily_timeline'       => array,
     *   'device_by_period'     => array,
     *   'holiday_impact'       => array,
     *   'seasonal_distribution'=> array,
     * ]
     * ```
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @return array<string, mixed> Advanced temporal analytics payload as described above.
     */
    public function getAdvancedTemporalAnalytics(int $linkId): array;
}
