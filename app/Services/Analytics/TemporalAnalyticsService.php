<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use App\Services\Analytics\Support\SqlDateExpr;
use Carbon\Carbon;

/**
 * Computes temporal analytics (hourly/daily patterns, weekends, business hours,
 * holidays, seasons) for a link.
 *
 * @see \App\Contracts\Analytics\TemporalAnalyticsInterface
 *
 * getLinkTemporalAnalytics — standard API endpoint payload.
 * getAdvancedTemporalAnalytics — richer payload used by the heatmap endpoint.
 *   Every aggregation runs as GROUP BY SQL; only peak_analysis and
 *   weekly_trends are composed in PHP, and strictly from SQL aggregates
 *   (never from raw click rows).
 *
 * Dual-path DB expressions: hour/DOW extraction uses COALESCE of the pre-computed
 * column (hour_of_day / day_of_week, Phase 1) with a fallback to DB-native
 * EXTRACT / strftime so older clicks are still included.
 *
 * Holiday and seasonal data is only present for clicks recorded after the Phase 2
 * migration. Null values in these columns indicate pre-migration clicks.
 *
 * Side effects: read-only. No cache, no queue, no log calls.
 */
class TemporalAnalyticsService implements \App\Contracts\Analytics\TemporalAnalyticsInterface
{
    /**
     * Returns temporal analytics for the given link.
     *
     * Returns empty arrays when no clicks exist (link must exist or
     * ModelNotFoundException is thrown by Link::findOrFail).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  string  $segment  Accepted: 'all'|'weekday'|'weekend'|'business'. Filters clicks by is_weekend/is_business_hours before aggregating.
     * @return array<string, mixed> Keys: clicks_by_hour, clicks_by_day_of_week, hourly_patterns_local, weekend_vs_weekday, business_hours_analysis, holiday_impact, seasonal_distribution, viral_rank_by_day, click_velocity.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If link does not exist.
     */
    public function getLinkTemporalAnalytics(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        Link::findOrFail($linkId);
        $filters ??= new AnalyticsFilters;

        if (! $this->baseQuery($linkId, $filters, $segment)->exists()) {
            return [
                'clicks_by_hour' => [],
                'clicks_by_day_of_week' => [],
                'holiday_impact' => ['holiday_clicks' => 0, 'non_holiday_clicks' => 0, 'holiday_percentage' => 0, 'top_holidays' => []],
                'seasonal_distribution' => [],
                'viral_rank_by_day' => [],
                'click_velocity' => [
                    'velocity_distribution' => [],
                    'phase2_available' => false,
                    'total_with_data' => 0,
                ],
            ];
        }

        return [
            'clicks_by_hour' => $this->getClicksByHour($linkId, $filters, $segment),
            'clicks_by_day_of_week' => $this->getClicksByDayOfWeek($linkId, $filters, $segment),
            'hourly_patterns_local' => $this->getHourlyPatternsLocal($linkId, $filters, $segment),
            'weekend_vs_weekday' => $this->getWeekendVsWeekday($linkId, $filters, $segment),
            'business_hours_analysis' => $this->getBusinessHoursAnalysis($linkId, $filters, $segment),
            'holiday_impact' => $this->getHolidayImpact($linkId, $filters, $segment),
            'seasonal_distribution' => $this->getSeasonalDistribution($linkId, $filters, $segment),
            'viral_rank_by_day' => $this->getViralRankByDay($linkId, $filters, $segment),
            'click_velocity' => $this->getClickVelocityDistribution($linkId, $filters, $segment),
        ];
    }

    /**
     * Build a filtered base query for clicks of a given link.
     *
     * Applies AnalyticsFilters (date range, bot exclusion) and an optional
     * segment constraint (weekday/weekend/business) to an Eloquent builder.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date/bot filter state.
     * @param  string  $segment  One of 'all'|'weekday'|'weekend'|'business'.
     * @return \Illuminate\Database\Eloquent\Builder Constrained builder.
     */
    private function baseQuery(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): \Illuminate\Database\Eloquent\Builder
    {
        $query = $filters->applyToQuery(Click::where('link_id', $linkId));

        return match ($segment) {
            'weekday' => $query->where('is_weekend', false),
            'weekend' => $query->where('is_weekend', true),
            'business' => $query->where('is_business_hours', true),
            default => $query,
        };
    }

    /**
     * Returns an extended temporal analytics payload for heatmap and trend views.
     *
     * All aggregations run as GROUP BY queries in the database — no click rows
     * are loaded into memory. peak_analysis is composed in PHP from the
     * hourly/daily SQL aggregates and weekly_trends from a per-day SQL
     * aggregate (see each helper), so memory stays O(buckets) instead of
     * O(clicks).
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed> Keys: hourly_patterns, daily_patterns, weekly_trends, monthly_trends, peak_analysis, timezone_analysis, heatmap_data, daily_timeline, device_by_period, holiday_impact, seasonal_distribution.
     */
    public function getAdvancedTemporalAnalytics(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        $filters ??= new AnalyticsFilters;

        $hourlyPatterns = $this->getHourlyPatterns($linkId, $filters, $segment);
        $dailyPatterns = $this->getDailyPatterns($linkId, $filters, $segment);
        $hasClicks = $this->baseQuery($linkId, $filters, $segment)->exists();

        return [
            'hourly_patterns' => $hourlyPatterns,
            'daily_patterns' => $dailyPatterns,
            'weekly_trends' => $this->getWeeklyTrends($linkId, $filters, $segment),
            'monthly_trends' => $this->getMonthlyTrends($linkId, $filters, $segment),
            'peak_analysis' => $this->getPeakAnalysis($hourlyPatterns, $dailyPatterns, $hasClicks),
            'timezone_analysis' => $this->getTimezoneAnalysis($linkId, $filters, $segment),
            'heatmap_data' => $this->getHourDayHeatmap($linkId, $filters, $segment),
            'daily_timeline' => $this->getDailyTimeline($linkId, $filters, $segment),
            'device_by_period' => $this->getDeviceByPeriodWithKeys($linkId, $filters, $segment),
            'holiday_impact' => $this->getHolidayImpact($linkId, $filters, $segment),
            'seasonal_distribution' => $this->getSeasonalDistribution($linkId, $filters, $segment),
        ];
    }

    /**
     * Aggregate click counts grouped by hour of day (0–23).
     *
     * Uses COALESCE of the pre-computed hour_of_day column with a DB-native
     * extraction fallback so older clicks without the column are included.
     * Respects AnalyticsFilters (date range, bot exclusion) and segment filtering.
     *
     * Public: also consumed by DashboardAnalyticsService, which composes its
     * temporal_data block from this service instead of re-implementing the
     * aggregation (dashboard/tab dedup, 2026-07-27).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Applied filter state. Null = no filter.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{hour: int, label: string, clicks: int}> 24-element array indexed 0–23.
     */
    public function getClicksByHour(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        $filters ??= new AnalyticsFilters;
        $expr = SqlDateExpr::hourOfDay();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$expr} as hour, count(*) as clicks")
            ->groupByRaw($expr)->orderByRaw('1')
            ->get()->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'label' => sprintf('%02d:00', $h), 'clicks' => (int) ($rows->get($h)?->clicks ?? 0)];
        }

        return $result;
    }

    /**
     * Aggregate click counts grouped by ISO day of week (1=Monday … 7=Sunday).
     *
     * Uses COALESCE of the pre-computed day_of_week column with a DB-native
     * extraction fallback. Respects AnalyticsFilters and segment filtering.
     *
     * Public: also consumed by DashboardAnalyticsService (see getClicksByHour).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Applied filter state. Null = no filter.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{day: int, day_name: string, clicks: int}> 7-element array indexed 1–7.
     */
    public function getClicksByDayOfWeek(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        $filters ??= new AnalyticsFilters;
        $expr = SqlDateExpr::dayOfWeek();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$expr} as dow, count(*) as clicks")
            ->groupByRaw($expr)->get()->keyBy('dow');

        $names = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = ['day' => $d, 'day_name' => $names[$d], 'clicks' => (int) ($rows->get($d)?->clicks ?? 0)];
        }

        return $result;
    }

    /**
     * Aggregate hourly click patterns from the pre-computed hour_of_day column
     * (visitor-local time, Phase 2 enrichment).
     *
     * Unlike getClicksByHour, no created_at fallback is applied: only clicks
     * with a stored local hour are included, and only hours with traffic are
     * returned (no zero-filled 24-bucket scaffold).
     *
     * Public: also consumed by DashboardAnalyticsService (see getClicksByHour).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Applied filter state. Null = no filter.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{hour: int, clicks: int, avg_response_time: float, unique_visitors: int}> Ordered by hour ascending.
     */
    public function getHourlyPatternsLocal(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        $filters ??= new AnalyticsFilters;

        return $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw('hour_of_day, COUNT(*) as clicks, AVG(response_time) as avg_response_time, COUNT(DISTINCT ip) as unique_visitors')
            ->whereNotNull('hour_of_day')
            ->groupBy('hour_of_day')
            ->orderBy('hour_of_day')
            ->get()
            ->map(fn ($r) => ['hour' => (int) $r->hour_of_day, 'clicks' => (int) $r->clicks, 'avg_response_time' => round((float) $r->avg_response_time, 2), 'unique_visitors' => (int) $r->unique_visitors])
            ->toArray();
    }

    private function getWeekendVsWeekday(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $expr = SqlDateExpr::dayOfWeek();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("({$expr}) as dow, count(*) as clicks, count(distinct ip) as unique_visitors")
            ->groupByRaw($expr)
            ->get();
        $weekday = $rows->whereIn('dow', [1, 2, 3, 4, 5])->sum('clicks');
        $weekend = $rows->whereIn('dow', [6, 7])->sum('clicks');
        $uniqueWeekday = $rows->whereIn('dow', [1, 2, 3, 4, 5])->sum('unique_visitors');
        $uniqueWeekend = $rows->whereIn('dow', [6, 7])->sum('unique_visitors');
        $total = $weekday + $weekend;

        return [
            'weekday' => ['clicks' => $weekday, 'unique_visitors' => $uniqueWeekday, 'percentage' => $total > 0 ? round($weekday / $total * 100, 2) : 0],
            'weekend' => ['clicks' => $weekend, 'unique_visitors' => $uniqueWeekend, 'percentage' => $total > 0 ? round($weekend / $total * 100, 2) : 0],
        ];
    }

    private function getBusinessHoursAnalysis(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $expr = SqlDateExpr::hourOfDay();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$expr} as h, count(*) as clicks")
            ->groupByRaw($expr)
            ->get();
        $business = $rows->whereBetween('h', [9, 17])->sum('clicks');
        $after = $rows->sum('clicks') - $business;
        $total = $business + $after;

        return [
            'business_hours' => ['clicks' => $business, 'percentage' => $total > 0 ? round($business / $total * 100, 2) : 0],
            'after_hours' => ['clicks' => $after,    'percentage' => $total > 0 ? round($after / $total * 100, 2) : 0],
        ];
    }

    /**
     * Aggregate weekend vs. weekday clicks from the pre-computed is_weekend
     * boolean (visitor-local time, Phase 2 enrichment) — the dashboard variant.
     *
     * Deliberately DISTINCT from the private DOW-derived getWeekendVsWeekday
     * used in the tab payload: this one trusts the stored local-time flag
     * (clicks with NULL is_weekend — pre-Phase 2 — match neither bucket) and
     * carries avg_response_time, while `percentage` is a fixed 0 placeholder
     * kept for frontend shape compatibility. Moved here from
     * DashboardAnalyticsService (dashboard/tab dedup, 2026-07-27) so every
     * temporal aggregation lives in this service.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Applied filter state. Null = no filter.
     * @return array{weekend: array{clicks: int, unique_visitors: int, avg_response_time: float, percentage: int}, weekday: array{clicks: int, unique_visitors: int, avg_response_time: float, percentage: int}}
     */
    public function getWeekendVsWeekdayLocal(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        $filters ??= new AnalyticsFilters;

        $weekend = $this->baseQuery($linkId, $filters)
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time')
            ->where('is_weekend', true)->first();

        $weekday = $this->baseQuery($linkId, $filters)
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time')
            ->where('is_weekend', false)->first();

        return [
            'weekend' => [
                'clicks' => (int) ($weekend->clicks ?? 0),
                'unique_visitors' => (int) ($weekend->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($weekend->avg_response_time ?? 0), 2),
                'percentage' => 0,
            ],
            'weekday' => [
                'clicks' => (int) ($weekday->clicks ?? 0),
                'unique_visitors' => (int) ($weekday->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($weekday->avg_response_time ?? 0), 2),
                'percentage' => 0,
            ],
        ];
    }

    /**
     * Aggregate business-hours vs. off-hours clicks from the pre-computed
     * is_business_hours boolean (visitor-local time, Phase 2 enrichment) —
     * the dashboard variant.
     *
     * Deliberately DISTINCT from the private hour-derived
     * getBusinessHoursAnalysis used in the tab payload: this one trusts the
     * stored local-time flag (clicks with NULL is_business_hours match neither
     * bucket) and adds avg_session_depth plus the fixed time_range labels.
     * Moved here from DashboardAnalyticsService (dashboard/tab dedup,
     * 2026-07-27). Business hours are defined as 09:00–17:00 local time.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Applied filter state. Null = no filter.
     * @return array{business_hours: array{clicks: int, unique_visitors: int, avg_response_time: float, avg_session_depth: float, time_range: string}, non_business_hours: array{clicks: int, unique_visitors: int, avg_response_time: float, avg_session_depth: float, time_range: string}}
     */
    public function getBusinessHoursAnalysisLocal(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        $filters ??= new AnalyticsFilters;

        $biz = $this->baseQuery($linkId, $filters)
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time, AVG(session_clicks) as avg_session_depth')
            ->where('is_business_hours', true)->first();

        $nonBiz = $this->baseQuery($linkId, $filters)
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time, AVG(session_clicks) as avg_session_depth')
            ->where('is_business_hours', false)->first();

        return [
            'business_hours' => [
                'clicks' => (int) ($biz->clicks ?? 0),
                'unique_visitors' => (int) ($biz->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($biz->avg_response_time ?? 0), 2),
                'avg_session_depth' => round((float) ($biz->avg_session_depth ?? 0), 1),
                'time_range' => '09:00-17:00',
            ],
            'non_business_hours' => [
                'clicks' => (int) ($nonBiz->clicks ?? 0),
                'unique_visitors' => (int) ($nonBiz->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($nonBiz->avg_response_time ?? 0), 2),
                'avg_session_depth' => round((float) ($nonBiz->avg_session_depth ?? 0), 1),
                'time_range' => '17:01-08:59',
            ],
        ];
    }

    // Advanced aggregations (GROUP BY SQL; formerly in-memory over all click rows)

    /**
     * Aggregate click counts by hour of day (0–23) for the advanced payload.
     *
     * Same GROUP BY as {@see getClicksByHour} (COALESCE of the pre-computed
     * hour_of_day column with the driver-native extraction fallback) but
     * without the `label` key, matching the advanced payload shape.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{hour: int, clicks: int}> 24-element array indexed 0–23.
     */
    private function getHourlyPatterns(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $expr = SqlDateExpr::hourOfDay();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$expr} as hour, count(*) as clicks")
            ->groupByRaw($expr)
            ->get()->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'clicks' => (int) ($rows->get($h)?->clicks ?? 0)];
        }

        return $result;
    }

    /**
     * Aggregate click counts by ISO day of week (1=Monday … 7=Sunday) for the
     * advanced payload.
     *
     * Same GROUP BY as {@see getClicksByDayOfWeek} but keyed `name` instead of
     * `day_name`, matching the advanced payload shape.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{day: int, name: string, clicks: int}> 7-element array indexed 1–7.
     */
    private function getDailyPatterns(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $expr = SqlDateExpr::dayOfWeek();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$expr} as dow, count(*) as clicks")
            ->groupByRaw($expr)
            ->get()->keyBy('dow');

        $names = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = ['day' => $d, 'name' => $names[$d], 'clicks' => (int) ($rows->get($d)?->clicks ?? 0)];
        }

        return $result;
    }

    /**
     * Aggregate click counts by ISO week, keyed `{calendar year of the week's
     * Monday}-{ISO week number}` (e.g. "2025-01" for the week of 2025-12-29).
     *
     * Composed in PHP from a per-day SQL aggregate (GROUP BY calendar date) —
     * never from raw click rows — because the legacy key comes from Carbon's
     * `startOfWeek()->format('Y-W')`, whose calendar-year/ISO-week mix has no
     * portable SQLite/Postgres expression (SQLite only gained `%G`/`%V` in
     * 3.46, and PHP's key uses the *calendar* year of the Monday, not the ISO
     * year). Memory is O(distinct days), not O(clicks).
     *
     * The week start is pinned to MONDAY explicitly: `startOfWeek()` without
     * an argument inherits the process-global Carbon locale (pt_BR = Sunday),
     * which shifted every key by -1 week whenever any earlier code path had
     * set the locale. Monday is the only convention consistent with `W`
     * (ISO-8601 week number), and makes the payload environment-independent.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{week: string, clicks: int}> Ordered by week key ascending.
     */
    private function getWeeklyTrends(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $expr = SqlDateExpr::date();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$expr} as date, count(*) as clicks")
            ->groupByRaw($expr)
            ->get();

        $weekly = [];
        foreach ($rows as $row) {
            $w = Carbon::parse($row->date)->startOfWeek(Carbon::MONDAY)->format('Y-W');
            $weekly[$w] = ($weekly[$w] ?? 0) + (int) $row->clicks;
        }
        ksort($weekly);

        return array_map(fn ($w, $n) => ['week' => $w, 'clicks' => $n], array_keys($weekly), $weekly);
    }

    /**
     * Aggregate click counts by calendar month (Y-m), ordered ascending.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{month: string, clicks: int}> Ordered by month key ascending.
     */
    private function getMonthlyTrends(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $expr = SqlDateExpr::yearMonth();

        return $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$expr} as month, count(*) as clicks")
            ->groupByRaw($expr)
            ->orderByRaw('1')
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Determine the peak hour and peak day from the already-computed SQL
     * aggregates.
     *
     * Composed in PHP from the outputs of {@see getHourlyPatterns} and
     * {@see getDailyPatterns} (never from raw rows): an extra MAX query would
     * re-scan the table for data these aggregates already contain, and the
     * first-bucket-wins tie-breaking of `array_search(max(...))` is preserved
     * exactly.
     *
     * @param  array<int, array{hour: int, clicks: int}>  $hourlyPatterns  Output of getHourlyPatterns.
     * @param  array<int, array{day: int, name: string, clicks: int}>  $dailyPatterns  Output of getDailyPatterns.
     * @param  bool  $hasClicks  Whether the filtered scope contains any click at all.
     * @return array{peak_hour: ?int, peak_day: ?int, peak_day_name: ?string, peak_hour_clicks: int, peak_day_clicks: int}
     */
    private function getPeakAnalysis(array $hourlyPatterns, array $dailyPatterns, bool $hasClicks): array
    {
        if (! $hasClicks) {
            return ['peak_hour' => null, 'peak_day' => null, 'peak_day_name' => null, 'peak_hour_clicks' => 0, 'peak_day_clicks' => 0];
        }

        $hourly = array_column($hourlyPatterns, 'clicks', 'hour');
        $daily = array_column($dailyPatterns, 'clicks', 'day');

        $peakHour = (int) array_search(max($hourly), $hourly);
        $peakDay = (int) array_search(max($daily), $daily);
        $names = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

        return [
            'peak_hour' => $peakHour,
            'peak_day' => $peakDay,
            'peak_day_name' => $names[$peakDay] ?? 'Desconhecido',
            'peak_hour_clicks' => $hourly[$peakHour] ?? 0,
            'peak_day_clicks' => $daily[$peakDay] ?? 0,
        ];
    }

    /**
     * Aggregate click counts by timezone, most-clicked first.
     *
     * NULL timezones (pre-Phase 1 clicks) are folded into the 'Unknown'
     * bucket via COALESCE, mirroring the legacy `?? 'Unknown'` fallback.
     * Ties are broken by timezone name ascending so the ordering is
     * deterministic across drivers.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{name: string, clicks: int}> Ordered by clicks descending.
     */
    private function getTimezoneAnalysis(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        return $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("COALESCE(timezone, 'Unknown') as name, count(*) as clicks")
            ->groupByRaw("COALESCE(timezone, 'Unknown')")
            ->orderByRaw('count(*) desc')
            ->orderByRaw("COALESCE(timezone, 'Unknown') asc")
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Build the 7-day × 24-hour click heatmap from a single two-dimension
     * GROUP BY (day of week, hour of day).
     *
     * Rows whose COALESCE'd hour/day fall outside the valid ranges (possible
     * only with corrupt stored values) are silently skipped, matching the
     * legacy in-memory range guard.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{name: string, data: array<int, array{x: string, y: int}>}> 7 series (Seg…Dom) × 24 points.
     */
    private function getHourDayHeatmap(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $hourExpr = SqlDateExpr::hourOfDay();
        $dowExpr = SqlDateExpr::dayOfWeek();

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$dowExpr} as dow, {$hourExpr} as hour, count(*) as clicks")
            ->groupByRaw("{$dowExpr}, {$hourExpr}")
            ->get();

        // 7 days × 24 hours matrix
        $matrix = [];
        for ($d = 1; $d <= 7; $d++) {
            $matrix[$d] = array_fill(0, 24, 0);
        }

        foreach ($rows as $row) {
            $h = (int) $row->hour;
            $d = (int) $row->dow;
            if ($h >= 0 && $h <= 23 && $d >= 1 && $d <= 7) {
                $matrix[$d][$h] = (int) $row->clicks;
            }
        }

        $names = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $data = [];
            for ($h = 0; $h < 24; $h++) {
                $data[] = ['x' => sprintf('%02d:00', $h), 'y' => $matrix[$d][$h]];
            }
            $result[] = ['name' => $names[$d], 'data' => $data];
        }

        return $result;
    }

    /**
     * Returns a day-by-day click timeline for the given link.
     *
     * When no explicit `dateFrom` filter is provided, results are capped to the
     * last 90 days. In that case the response indicates `capped = true` and
     * provides the earliest available click date so the frontend can inform the
     * user and suggest using the date filter to access older data.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array{data: array<int, array{date: string, clicks: int, unique_visitors: int}>, capped: bool, earliest_available_at: string|null}
     */
    private function getDailyTimeline(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $capped = $filters->dateFrom === null;

        // Determine the actual earliest click date before applying the cap so
        // we can surface it in the response regardless of the cap.
        $earliestAvailableAt = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw('DATE(MIN(created_at)) as earliest')
            ->value('earliest');

        $query = $this->baseQuery($linkId, $filters, $segment);

        if ($capped) {
            $query->where('created_at', '>=', now()->subDays(90));
        }

        $data = $query
            ->selectRaw('DATE(created_at) as date, COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date,
                'clicks' => (int) $r->clicks,
                'unique_visitors' => (int) $r->unique_visitors,
            ])
            ->toArray();

        return [
            'data' => $data,
            'capped' => $capped,
            'earliest_available_at' => $earliestAvailableAt,
        ];
    }

    /**
     * Returns a summary of click volume on national holidays vs non-holidays.
     *
     * Only includes clicks where is_holiday is explicitly true (populated after Phase 2
     * migration). Clicks recorded before the migration have null and are excluded.
     *
     * @return array{holiday_clicks: int, non_holiday_clicks: int, holiday_percentage: float, top_holidays: array}
     */
    private function getHolidayImpact(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $total = $this->baseQuery($linkId, $filters, $segment)->count();
        $holiday = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw('holiday_name, COUNT(*) as clicks')
            ->where('is_holiday', true)
            ->whereNotNull('holiday_name')
            ->groupBy('holiday_name')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'holiday' => $r->holiday_name,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();

        $holidayTotal = array_sum(array_column($holiday, 'clicks'));

        return [
            'holiday_clicks' => $holidayTotal,
            'non_holiday_clicks' => $total - $holidayTotal,
            'holiday_percentage' => $total > 0 ? round($holidayTotal / $total * 100, 2) : 0,
            'top_holidays' => $holiday,
        ];
    }

    /**
     * Returns click distribution grouped by calendar season.
     *
     * Seasons are computed server-side in Phase 2 accounting for hemisphere.
     * Only includes clicks where season is populated (after Phase 2 migration).
     *
     * @return array<int, array{season: string, clicks: int, percentage: float}>
     */
    private function getSeasonalDistribution(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $total = $this->baseQuery($linkId, $filters, $segment)->count();

        return $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw('season, COUNT(*) as clicks')
            ->whereNotNull('season')
            ->groupBy('season')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'season' => $r->season,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Returns clicks grouped by day with the peak viral rank for each day.
     *
     * Peak rank order: viral (4) > trending (3) > warming (2) > cold (1).
     * Clicks with NULL viral_rank (pre-Phase 2) are included and mapped to the
     * special value 'unranked' so they are visible in the frontend chart instead
     * of being silently excluded.
     * Results ordered by date ascending.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{date: string, peak_rank: string, click_count: int}>
     */
    private function getViralRankByDay(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $query = $this->baseQuery($linkId, $filters, $segment);

        // Cap to 90 days when no explicit date range is set to avoid loading excessive data.
        if ($filters->dateFrom === null) {
            $query->where('created_at', '>=', now()->subDays(90));
        }

        return $query
            ->selectRaw("
                DATE(created_at) as date,
                COUNT(*) as click_count,
                COALESCE(
                    CASE MAX(CASE viral_rank
                        WHEN 'viral'    THEN 4
                        WHEN 'trending' THEN 3
                        WHEN 'warming'  THEN 2
                        WHEN 'cold'     THEN 1
                        ELSE NULL
                    END)
                        WHEN 4 THEN 'viral'
                        WHEN 3 THEN 'trending'
                        WHEN 2 THEN 'warming'
                        WHEN 1 THEN 'cold'
                        ELSE NULL
                    END,
                    'unranked'
                ) as peak_rank
            ")
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) ASC')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->date,
                'peak_rank' => $r->peak_rank ?? 'unranked',
                'click_count' => (int) $r->click_count,
            ])
            ->toArray();
    }

    /**
     * Aggregates click device types into four time-of-day periods.
     *
     * Returns period keys as lowercase strings ('dawn', 'morning', 'afternoon',
     * 'evening') without translated labels — the frontend is responsible for
     * mapping each key to a localised label via i18n.
     *
     * Single GROUP BY on the period bucket with conditional SUMs per device.
     * LOWER(device) mirrors the legacy `strtolower($click->device ?? '')`;
     * NULL or unrecognised devices match no SUM branch and are not counted,
     * exactly as before.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{period: string, desktop: int, mobile: int, tablet: int}>
     */
    private function getDeviceByPeriodWithKeys(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $hourExpr = SqlDateExpr::hourOfDay();
        $periodExpr = "CASE
            WHEN ({$hourExpr}) BETWEEN 0 AND 5   THEN 'dawn'
            WHEN ({$hourExpr}) BETWEEN 6 AND 11  THEN 'morning'
            WHEN ({$hourExpr}) BETWEEN 12 AND 17 THEN 'afternoon'
            WHEN ({$hourExpr}) BETWEEN 18 AND 23 THEN 'evening'
        END";

        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("{$periodExpr} as period,
                SUM(CASE WHEN LOWER(device) = 'desktop' THEN 1 ELSE 0 END) as desktop,
                SUM(CASE WHEN LOWER(device) = 'mobile'  THEN 1 ELSE 0 END) as mobile,
                SUM(CASE WHEN LOWER(device) = 'tablet'  THEN 1 ELSE 0 END) as tablet")
            ->groupByRaw($periodExpr)
            ->get()->keyBy('period');

        $result = [];
        foreach (['dawn', 'morning', 'afternoon', 'evening'] as $period) {
            $row = $rows->get($period);
            $result[] = [
                'period' => $period,
                'desktop' => (int) ($row?->desktop ?? 0),
                'mobile' => (int) ($row?->mobile ?? 0),
                'tablet' => (int) ($row?->tablet ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Computes a distribution of click velocity (seconds between consecutive clicks)
     * bucketed into five categories: instant, very_fast, fast, moderate, slow.
     *
     * Pre-Phase 2 clicks have NULL in `seconds_since_last_click`. These are counted
     * but excluded from the distribution buckets. The `phase2_available` flag is
     * set to true when at least 50% of clicks carry non-NULL velocity data, which
     * indicates Phase 2 tracking was active for the majority of the link's traffic.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Applied filter state.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array{
     *     velocity_distribution: array<int, array{bucket: string, label_key: string, min_sec: int, max_sec: int|null, count: int}>,
     *     phase2_available: bool,
     *     total_with_data: int
     * }
     */
    private function getClickVelocityDistribution(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
        $totalClicks = $this->baseQuery($linkId, $filters, $segment)->count();

        $withData = $this->baseQuery($linkId, $filters, $segment)
            ->whereNotNull('seconds_since_last_click')
            ->count();

        $phase2Available = $totalClicks > 0 && ($withData / $totalClicks) >= 0.5;

        // Bucket definitions: [bucket, label_key, min_sec, max_sec|null].
        $buckets = [
            ['instant',   'velocity.instant',  0,   1],
            ['very_fast', 'velocity.veryFast', 1,   10],
            ['fast',      'velocity.fast',     10,  60],
            ['moderate',  'velocity.moderate', 60,  300],
            ['slow',      'velocity.slow',     300, null],
        ];

        // Single query with CASE WHEN to bucket all velocities in one pass.
        $rows = $this->baseQuery($linkId, $filters, $segment)
            ->selectRaw("
                CASE
                    WHEN seconds_since_last_click IS NULL THEN NULL
                    WHEN seconds_since_last_click < 1    THEN 'instant'
                    WHEN seconds_since_last_click < 10   THEN 'very_fast'
                    WHEN seconds_since_last_click < 60   THEN 'fast'
                    WHEN seconds_since_last_click < 300  THEN 'moderate'
                    ELSE 'slow'
                END as bucket,
                COUNT(*) as count
            ")
            ->whereNotNull('seconds_since_last_click')
            ->groupByRaw('1')
            ->get()
            ->keyBy('bucket');

        $distribution = array_map(fn ($b) => [
            'bucket' => $b[0],
            'label_key' => $b[1],
            'min_sec' => $b[2],
            'max_sec' => $b[3],
            'count' => (int) ($rows->get($b[0])?->count ?? 0),
        ], $buckets);

        return [
            'velocity_distribution' => $distribution,
            'phase2_available' => $phase2Available,
            'total_with_data' => $withData,
        ];
    }
}
