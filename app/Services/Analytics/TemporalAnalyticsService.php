<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

/**
 * Computes temporal analytics (hourly/daily patterns, weekends, business hours,
 * holidays, seasons) for a link.
 *
 * @see \App\Contracts\Analytics\TemporalAnalyticsInterface
 *
 * getLinkTemporalAnalytics — standard API endpoint payload.
 * getAdvancedTemporalAnalytics — richer payload used by the heatmap endpoint,
 *   loads all clicks into memory for pattern computation.
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
     * Loads all clicks for the link into a Laravel Collection in memory — may be
     * expensive for high-traffic links. Used by the heatmap API endpoint.
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed> Keys: hourly_patterns, daily_patterns, weekly_trends, monthly_trends, peak_analysis, timezone_analysis, heatmap_data, daily_timeline, device_by_period, holiday_impact, seasonal_distribution.
     */
    public function getAdvancedTemporalAnalytics(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        $filters ??= new AnalyticsFilters;
        $clicks = $this->baseQuery($linkId, $filters, $segment)->get();

        return [
            'hourly_patterns' => $this->getHourlyPatterns($clicks),
            'daily_patterns' => $this->getDailyPatterns($clicks),
            'weekly_trends' => $this->getWeeklyTrends($clicks),
            'monthly_trends' => $this->getMonthlyTrends($clicks),
            'peak_analysis' => $this->getPeakAnalysis($clicks),
            'timezone_analysis' => $this->getTimezoneAnalysis($clicks),
            'heatmap_data' => $this->getHourDayHeatmap($clicks),
            'daily_timeline' => $this->getDailyTimeline($linkId, $filters, $segment),
            'device_by_period' => $this->getDeviceByPeriodWithKeys($clicks),
            'holiday_impact' => $this->getHolidayImpact($linkId, $filters, $segment),
            'seasonal_distribution' => $this->getSeasonalDistribution($linkId, $filters, $segment),
        ];
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Aggregate click counts grouped by hour of day (0–23).
     *
     * Uses COALESCE of the pre-computed hour_of_day column with a DB-native
     * extraction fallback so older clicks without the column are included.
     * Respects AnalyticsFilters (date range, bot exclusion) and segment filtering.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Applied filter state. Null = no filter.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{hour: int, label: string, clicks: int}> 24-element array indexed 0–23.
     */
    private function getClicksByHour(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        $filters ??= new AnalyticsFilters;
        $expr = $this->isSqlite()
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

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
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Applied filter state. Null = no filter.
     * @param  string  $segment  Segment constraint passed to baseQuery.
     * @return array<int, array{day: int, day_name: string, clicks: int}> 7-element array indexed 1–7.
     */
    private function getClicksByDayOfWeek(int $linkId, ?AnalyticsFilters $filters = null, string $segment = 'all'): array
    {
        $filters ??= new AnalyticsFilters;
        $expr = $this->isSqlite()
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : 'COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)';

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

    private function getHourlyPatternsLocal(int $linkId, AnalyticsFilters $filters, string $segment = 'all'): array
    {
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
        $expr = $this->isSqlite()
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : 'COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)';

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
        $expr = $this->isSqlite()
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

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

    // Advanced methods migrated from UserAgentAnalyticsService

    private function getHourlyPatterns($clicks): array
    {
        $patterns = array_fill(0, 24, 0);
        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            if ($h >= 0 && $h <= 23) {
                $patterns[$h]++;
            }
        }
        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'clicks' => $patterns[$h]];
        }

        return $result;
    }

    private function getDailyPatterns($clicks): array
    {
        $patterns = array_fill(1, 7, 0);
        foreach ($clicks as $click) {
            $d = $click->day_of_week ?? (int) $click->created_at->format('N');
            if ($d >= 1 && $d <= 7) {
                $patterns[$d] = ($patterns[$d] ?? 0) + 1;
            }
        }
        $names = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = ['day' => $d, 'name' => $names[$d], 'clicks' => $patterns[$d]];
        }

        return $result;
    }

    private function getWeeklyTrends($clicks): array
    {
        $weekly = [];
        foreach ($clicks as $click) {
            $w = $click->created_at->startOfWeek()->format('Y-W');
            $weekly[$w] = ($weekly[$w] ?? 0) + 1;
        }
        ksort($weekly);

        return array_map(fn ($w, $n) => ['week' => $w, 'clicks' => $n], array_keys($weekly), $weekly);
    }

    private function getMonthlyTrends($clicks): array
    {
        $monthly = [];
        foreach ($clicks as $click) {
            $m = $click->created_at->format('Y-m');
            $monthly[$m] = ($monthly[$m] ?? 0) + 1;
        }
        ksort($monthly);

        return array_map(fn ($m, $n) => ['month' => $m, 'clicks' => $n], array_keys($monthly), $monthly);
    }

    private function getPeakAnalysis($clicks): array
    {
        if ($clicks->isEmpty()) {
            return ['peak_hour' => null, 'peak_day' => null, 'peak_day_name' => null, 'peak_hour_clicks' => 0, 'peak_day_clicks' => 0];
        }

        $hourly = array_fill(0, 24, 0);
        $daily = array_fill(1, 7, 0);
        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            $d = $click->day_of_week ?? (int) $click->created_at->format('N');
            if ($h >= 0 && $h <= 23) {
                $hourly[$h]++;
            }
            if ($d >= 1 && $d <= 7) {
                $daily[$d] = ($daily[$d] ?? 0) + 1;
            }
        }
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

    private function getTimezoneAnalysis($clicks): array
    {
        $tzs = [];
        foreach ($clicks as $click) {
            $tz = $click->timezone ?? 'Unknown';
            $tzs[$tz] = ($tzs[$tz] ?? 0) + 1;
        }
        arsort($tzs);

        return array_map(fn ($tz, $n) => ['name' => $tz, 'clicks' => $n], array_keys($tzs), $tzs);
    }

    private function getHourDayHeatmap($clicks): array
    {
        // 7 days × 24 hours matrix
        $matrix = [];
        for ($d = 1; $d <= 7; $d++) {
            $matrix[$d] = array_fill(0, 24, 0);
        }

        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            $d = $click->day_of_week ?? (int) $click->created_at->format('N');
            if ($h >= 0 && $h <= 23 && $d >= 1 && $d <= 7) {
                $matrix[$d][$h]++;
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
     * @param  \Illuminate\Support\Collection  $clicks  Click collection (already filtered).
     * @return array<int, array{period: string, desktop: int, mobile: int, tablet: int}>
     */
    private function getDeviceByPeriodWithKeys($clicks): array
    {
        $periods = [
            'dawn'      => ['range' => [0, 5],   'desktop' => 0, 'mobile' => 0, 'tablet' => 0],
            'morning'   => ['range' => [6, 11],  'desktop' => 0, 'mobile' => 0, 'tablet' => 0],
            'afternoon' => ['range' => [12, 17], 'desktop' => 0, 'mobile' => 0, 'tablet' => 0],
            'evening'   => ['range' => [18, 23], 'desktop' => 0, 'mobile' => 0, 'tablet' => 0],
        ];

        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            $device = strtolower($click->device ?? '');

            foreach ($periods as $key => &$period) {
                if ($h >= $period['range'][0] && $h <= $period['range'][1]) {
                    if (in_array($device, ['desktop', 'mobile', 'tablet'])) {
                        $period[$device]++;
                    }
                    break;
                }
            }
            unset($period);
        }

        return array_values(array_map(fn ($key, $p) => [
            'period'  => $key,
            'desktop' => $p['desktop'],
            'mobile'  => $p['mobile'],
            'tablet'  => $p['tablet'],
        ], array_keys($periods), $periods));
    }

    /**
     * @deprecated Use {@see getDeviceByPeriodWithKeys()} instead.
     *   Kept only to avoid breaking callers that may still reference this method.
     *   Will be removed in a future cleanup pass.
     *
     * @param  \Illuminate\Support\Collection  $clicks  Click collection.
     * @return array<int, array{period: string, label: string, desktop: int, mobile: int, tablet: int}>
     */
    private function getDeviceByPeriod($clicks): array
    {
        $withKeys = $this->getDeviceByPeriodWithKeys($clicks);
        $labels = [
            'dawn' => 'Madrugada', 'morning' => 'Manhã',
            'afternoon' => 'Tarde', 'evening' => 'Noite',
        ];

        return array_map(fn ($p) => array_merge($p, ['label' => $labels[$p['period']] ?? $p['period']]), $withKeys);
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
            'bucket'    => $b[0],
            'label_key' => $b[1],
            'min_sec'   => $b[2],
            'max_sec'   => $b[3],
            'count'     => (int) ($rows->get($b[0])?->count ?? 0),
        ], $buckets);

        return [
            'velocity_distribution' => $distribution,
            'phase2_available'      => $phase2Available,
            'total_with_data'       => $withData,
        ];
    }
}
