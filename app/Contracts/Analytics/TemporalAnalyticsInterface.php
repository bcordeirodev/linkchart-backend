<?php

namespace App\Contracts\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;

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
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'. Filters clicks by is_weekend/is_business_hours before aggregating.
     * @return array<string, mixed> Temporal analytics payload as described above.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkTemporalAnalytics(
        int $linkId,
        ?AnalyticsFilters $filters = null,
        string $segment = 'all'
    ): array;

    /**
     * Return advanced temporal analytics with extended pattern analysis for the given link.
     *
     * Loads the filtered `clicks` collection into memory for in-PHP aggregation.
     * Applies the same AnalyticsFilters and segment constraint as getLinkTemporalAnalytics
     * so peak_analysis, heatmap, and timeline reflect the active filters.
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
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'.
     * @return array<string, mixed> Advanced temporal analytics payload as described above.
     */
    public function getAdvancedTemporalAnalytics(
        int $linkId,
        ?AnalyticsFilters $filters = null,
        string $segment = 'all'
    ): array;

    /**
     * Return click counts per hour of day (0–23) as a zero-filled 24-bucket array.
     *
     * Uses COALESCE of the pre-computed `hour_of_day` column with a DB-native
     * extraction from `created_at` so older clicks are included. Granular entry
     * point consumed by DashboardAnalyticsService, which composes its
     * temporal_data block from this service (single source per aggregation).
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'.
     * @return array<int, array{hour: int, label: string, clicks: int}> 24-element array indexed 0–23.
     */
    public function getClicksByHour(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array;

    /**
     * Return click counts per ISO day of week (1=Monday … 7=Sunday) as a
     * zero-filled 7-bucket array with Portuguese day names.
     *
     * Uses COALESCE of the pre-computed `day_of_week` column with a DB-native
     * extraction fallback. Granular entry point consumed by
     * DashboardAnalyticsService (see getClicksByHour).
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'.
     * @return array<int, array{day: int, day_name: string, clicks: int}> 7-element array indexed 1–7.
     */
    public function getClicksByDayOfWeek(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array;

    /**
     * Return hourly click patterns from the pre-computed `hour_of_day` column
     * (visitor-local time, Phase 2 enrichment).
     *
     * Only clicks with a stored local hour are included and only hours with
     * traffic are returned (no zero-filled scaffold). Granular entry point
     * consumed by DashboardAnalyticsService (see getClicksByHour).
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'.
     * @return array<int, array{hour: int, clicks: int, avg_response_time: float, unique_visitors: int}> Ordered by hour ascending.
     */
    public function getHourlyPatternsLocal(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array;

    /**
     * Return the weekend vs. weekday comparison built from the pre-computed
     * `is_weekend` boolean (visitor-local time) — the dashboard variant.
     *
     * Distinct from the DOW-derived weekend_vs_weekday of the tab payload:
     * clicks with NULL is_weekend (pre-Phase 2) match neither bucket, and the
     * shape carries avg_response_time with a fixed `percentage => 0`
     * placeholder kept for frontend compatibility.
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array{weekend: array{clicks: int, unique_visitors: int, avg_response_time: float, percentage: int}, weekday: array{clicks: int, unique_visitors: int, avg_response_time: float, percentage: int}}
     */
    public function getWeekendVsWeekdayLocal(int $linkId, ?AnalyticsFilters $filters = null): array;

    /**
     * Return the business-hours vs. off-hours comparison built from the
     * pre-computed `is_business_hours` boolean (visitor-local time) — the
     * dashboard variant.
     *
     * Distinct from the hour-derived business_hours_analysis of the tab
     * payload: clicks with NULL is_business_hours match neither bucket, and
     * the shape adds avg_session_depth plus fixed time_range labels
     * ('09:00-17:00' / '17:01-08:59').
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array{business_hours: array{clicks: int, unique_visitors: int, avg_response_time: float, avg_session_depth: float, time_range: string}, non_business_hours: array{clicks: int, unique_visitors: int, avg_response_time: float, avg_session_depth: float, time_range: string}}
     */
    public function getBusinessHoursAnalysisLocal(int $linkId, ?AnalyticsFilters $filters = null): array;
}
