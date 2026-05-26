<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use App\Services\Analytics\Support\UserAgentParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the main dashboard analytics payload for a single link.
 *
 * @see \App\Contracts\Analytics\DashboardAnalyticsInterface
 *
 * Produces a combined payload of summary stats, temporal patterns, geographic
 * breakdown, and audience data. Designed to be fetched in a single API call from
 * the frontend dashboard page. All sub-queries are scoped through an
 * {@see AnalyticsFilters} value object that carries date-range and bot-exclusion
 * constraints, replacing the legacy `?Carbon $since` pattern.
 *
 * Aggregates from the clicks table. Contains SQLite/PostgreSQL dual-path expressions
 * for several hour/DOW extractions (used in tests with SQLite :memory:).
 *
 * Side effects: read-only queries. No cache, no queue, no log calls.
 *
 * --- Column → chart mapping (verified against migrations) ---
 * clicks_by_hour         → clicks.hour_of_day (int, nullable, Phase 2 enriched)
 *                          fallback: EXTRACT(HOUR FROM clicks.created_at)
 * clicks_by_day_of_week  → clicks.day_of_week (int 1–7, nullable, Phase 2)
 *                          fallback: EXTRACT(DOW FROM clicks.created_at)
 * device_breakdown       → clicks.device (varchar, nullable)
 * top_countries          → clicks.country (varchar, nullable)
 * utm_top_sources        → link_utms.utm_source (via JOIN clicks ↔ link_utms)
 * viral_rank distribution→ clicks.viral_rank (varchar, nullable, Phase 2 Redis)
 * social_iab             → clicks.navigation_context = 'in_app_webview'
 *                          AND clicks.is_mobile = 1 (Phase 1 field)
 */
class DashboardAnalyticsService implements \App\Contracts\Analytics\DashboardAnalyticsInterface
{
    public function __construct(private readonly UserAgentParser $uaParser) {}

    /**
     * Returns the full dashboard analytics payload for a link.
     *
     * Returns an empty dashboard structure if the link does not exist (no exception).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed> Keyed: summary, link_info, temporal_data, geographic_data, audience_data.
     */
    public function getLinkDashboardAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        $filters ??= new AnalyticsFilters;
        $link = Link::find($linkId);

        if (! $link) {
            return $this->emptyDashboard();
        }

        $totalClicks = $this->countClicks($linkId, $filters);
        $unique = $this->countUnique($linkId, $filters);
        $countries = $this->countCountries($linkId, $filters);

        return [
            'summary' => [
                'total_clicks' => $totalClicks,
                'total_links' => 1,
                'active_links' => $link->is_active ? 1 : 0,
                'unique_visitors' => $unique,
                'avg_response_time' => $this->estimateResponseTime($linkId, $filters),
                'countries_reached' => $countries,
                'links_with_traffic' => $totalClicks > 0 ? 1 : 0,
                'viral_rank' => $this->getViralRankSummary($linkId, $filters),
                'quality' => $this->getQualitySummary($linkId, $filters),
                'utm_top_sources' => $this->getUtmTopSources($linkId, $filters),
                'social_iab' => $this->getSocialIabStats($linkId, $filters),
            ],
            'link_info' => [
                'id' => $link->id,
                'title' => $link->title,
                'short_url' => $link->getShortedUrl(),
                'original_url' => $link->original_url,
                'clicks' => $totalClicks,
                'is_active' => $link->is_active,
                'created_at' => $link->created_at,
            ],
            'temporal_data' => [
                'clicks_by_hour' => $this->getClicksByHour($linkId, $filters),
                'clicks_by_day_of_week' => $this->getClicksByDayOfWeek($linkId, $filters),
                'hourly_patterns_local' => $this->getHourlyPatternsLocal($linkId, $filters),
                'weekend_vs_weekday' => $this->getWeekendVsWeekday($linkId, $filters),
                'business_hours_analysis' => $this->getBusinessHoursAnalysis($linkId, $filters),
            ],
            'geographic_data' => [
                'heatmap_data' => $this->getHeatmapData($linkId, $filters),
                'top_countries' => $this->getTopCountries($linkId, $filters),
                'top_states' => $this->getTopStates($linkId, $filters),
                'top_cities' => $this->getTopCities($linkId, $filters),
            ],
            'audience_data' => [
                'device_breakdown' => $this->getDeviceBreakdown($linkId, $filters),
                'browser_breakdown' => $this->getBrowserBreakdown($linkId, $filters),
                'os_breakdown' => $this->getOsBreakdown($linkId, $filters),
                'browsers' => $this->getBrowserDistribution($linkId, $filters),
                'operating_systems' => $this->getOsDistribution($linkId, $filters),
                'device_performance' => $this->getDevicePerformance($linkId, $filters),
                'languages' => $this->getLanguageDistribution($linkId, $filters),
            ],
        ];
    }

    /**
     * Returns a base Eloquent query for the clicks table scoped to the given link and filters.
     *
     * Every private method should start with this builder and add its own SELECT/WHERE/GROUP BY
     * clauses. Centralising the filter application here ensures date-range and bot-exclusion
     * are consistently applied across all aggregations.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Filter constraints to apply.
     * @return Builder Eloquent builder for Click with link_id and filter scopes already applied.
     */
    private function baseQuery(int $linkId, AnalyticsFilters $filters): Builder
    {
        return $filters->applyToQuery(Click::where('link_id', $linkId));
    }

    private function emptyDashboard(): array
    {
        return [
            'summary' => [
                'total_clicks' => 0,
                'total_links' => 1,
                'active_links' => 0,
                'unique_visitors' => 0,
                'avg_response_time' => 0,
                'countries_reached' => 0,
                'links_with_traffic' => 0,
                'viral_rank' => ['current_rank' => 'cold', 'distribution' => []],
                'quality' => ['organic' => 0, 'suspicious' => 0, 'likely_fraud' => 0, 'unscored' => 0, 'organic_percentage' => 0],
                'utm_top_sources' => [],
                'social_iab' => ['total' => 0, 'percentage' => 0.0, 'ios_pct' => 0.0, 'android_pct' => 0.0, 'navigation_context_available' => false],
            ],
            'link_info' => null,
            'temporal_data' => [
                'clicks_by_hour' => [],
                'clicks_by_day_of_week' => [],
                'hourly_patterns_local' => [],
                'weekend_vs_weekday' => [],
                'business_hours_analysis' => [],
            ],
            'geographic_data' => [
                'heatmap_data' => [],
                'top_countries' => [],
                'top_states' => [],
                'top_cities' => [],
            ],
            'audience_data' => [
                'device_breakdown' => [],
                'browser_breakdown' => [],
                'os_breakdown' => [],
                'browsers' => [],
                'operating_systems' => [],
                'device_performance' => [],
                'languages' => [],
            ],
        ];
    }

    /**
     * Returns the current viral rank and historical distribution for a link.
     *
     * Current rank is the most recent non-null viral_rank value. Distribution
     * shows the count per rank bucket for the requested filter window.
     *
     * @param  int  $linkId  Link ID
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{current_rank: string, distribution: array}
     */
    private function getViralRankSummary(int $linkId, AnalyticsFilters $filters): array
    {
        $latest = $this->baseQuery($linkId, $filters)
            ->whereNotNull('viral_rank')
            ->latest()
            ->value('viral_rank');

        $distribution = $this->baseQuery($linkId, $filters)
            ->selectRaw("COALESCE(viral_rank, 'cold') as rank, COUNT(*) as clicks")
            ->groupBy('viral_rank')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => ['rank' => $r->rank, 'clicks' => (int) $r->clicks])
            ->toArray();

        return [
            'current_rank' => $latest ?? 'cold',
            'distribution' => $distribution,
        ];
    }

    /**
     * Counts all clicks for the given link within the filter constraints.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return int Total click count.
     */
    private function countClicks(int $linkId, AnalyticsFilters $filters): int
    {
        return $this->baseQuery($linkId, $filters)->count();
    }

    /**
     * Counts distinct IPs (unique visitors) for the given link and filter window.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return int Unique visitor count.
     */
    private function countUnique(int $linkId, AnalyticsFilters $filters): int
    {
        return $this->baseQuery($linkId, $filters)->distinct('ip')->count();
    }

    /**
     * Counts distinct non-localhost countries reached for the given link.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return int Country count.
     */
    private function countCountries(int $linkId, AnalyticsFilters $filters): int
    {
        return $this->baseQuery($linkId, $filters)
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->distinct('country')->count();
    }

    /**
     * Returns the average response time across all clicks in the filter window.
     *
     * Returns 0.0 when no clicks have a recorded response_time.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return float Average response time in milliseconds.
     */
    private function estimateResponseTime(int $linkId, AnalyticsFilters $filters): float
    {
        return (float) ($this->baseQuery($linkId, $filters)
            ->whereNotNull('response_time')
            ->avg('response_time') ?? 0);
    }

    /**
     * Returns click counts per hour-of-day (0–23) for the filter window.
     *
     * Provides a dual SQLite/PostgreSQL expression so that the test suite running
     * against SQLite :memory: produces identical output to production.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{hour: int, clicks: int, label: string}>
     */
    private function getClicksByHour(int $linkId, AnalyticsFilters $filters): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $hourExpr = $sqlite
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $rows = $this->baseQuery($linkId, $filters)
            ->selectRaw("{$hourExpr} as hour, count(*) as clicks")
            ->groupByRaw($hourExpr)
            ->orderByRaw('1')
            ->get()->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = [
                'hour' => $h,
                'clicks' => (int) ($rows->get($h)?->clicks ?? 0),
                'label' => sprintf('%02d:00', $h),
            ];
        }

        return $result;
    }

    /**
     * Returns click counts per ISO day-of-week (1=Monday … 7=Sunday).
     *
     * Provides a dual SQLite/PostgreSQL expression for the DOW extraction.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{day: int, day_name: string, clicks: int}>
     */
    private function getClicksByDayOfWeek(int $linkId, AnalyticsFilters $filters): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $dowExpr = $sqlite
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : 'COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)';

        $rows = $this->baseQuery($linkId, $filters)
            ->selectRaw("{$dowExpr} as day, count(*) as clicks")
            ->groupByRaw($dowExpr)->get()->keyBy('day');

        $names = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = [
                'day' => $d,
                'day_name' => $names[$d],
                'clicks' => (int) ($rows->get($d)?->clicks ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Returns heatmap data: geo-clustered click counts with location metadata.
     *
     * Rows are grouped by lat/lng/city/country and ordered by click count desc.
     * Excludes rows without coordinates or with placeholder country values.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{lat: float, lng: float, city: string, country: string, clicks: int, ...}>
     */
    private function getHeatmapData(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw('latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone, COUNT(*) as clicks, MAX(created_at) as last_click')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNotNull('country')->where('country', '!=', 'localhost')->where('country', '!=', '')
            ->groupBy('latitude', 'longitude', 'city', 'country', 'iso_code', 'currency', 'state_name', 'continent', 'timezone')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'city' => $r->city ?: 'Cidade Desconhecida',
                'country' => $r->country,
                'clicks' => (int) $r->clicks,
                'iso_code' => $r->iso_code,
                'currency' => $r->currency,
                'state_name' => $r->state_name,
                'continent' => $r->continent,
                'timezone' => $r->timezone,
                'last_click' => $r->last_click,
            ])
            ->toArray();
    }

    /**
     * Returns the top-10 countries by click count for the filter window.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{country: string, iso_code: string, clicks: int, currency: string}>
     */
    private function getTopCountries(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw('country, iso_code, currency, COUNT(*) as clicks')
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->groupBy('country', 'iso_code', 'currency')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['country' => $r->country, 'iso_code' => $r->iso_code, 'clicks' => (int) $r->clicks, 'currency' => $r->currency])
            ->toArray();
    }

    /**
     * Returns the top-10 states by click count for the filter window.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{country: string, state: string, state_name: string, clicks: int}>
     */
    private function getTopStates(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw('country, state, state_name, COUNT(*) as clicks')
            ->whereNotNull('state')
            ->groupBy('country', 'state', 'state_name')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['country' => $r->country, 'state' => $r->state, 'state_name' => $r->state_name, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Returns the top-10 cities by click count for the filter window.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{city: string, state: string, country: string, clicks: int}>
     */
    private function getTopCities(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw('city, state, country, COUNT(*) as clicks')
            ->whereNotNull('city')
            ->groupBy('city', 'state', 'country')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['city' => $r->city, 'state' => $r->state, 'country' => $r->country, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Returns click counts per device type for the filter window.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{device: string, clicks: int}>
     */
    private function getDeviceBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw('device, COUNT(*) as clicks')
            ->whereNotNull('device')
            ->groupBy('device')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['device' => $r->device, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Returns click counts per browser for the filter window.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{browser: string, clicks: int}>
     */
    private function getBrowserBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw("COALESCE(browser, 'Unknown') as browser, COUNT(*) as clicks")
            ->groupBy('browser')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['browser' => $r->browser, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Returns click counts per operating system for the filter window.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{os: string, clicks: int}>
     */
    private function getOsBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw("COALESCE(os, 'Unknown') as os, COUNT(*) as clicks")
            ->groupBy('os')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['os' => $r->os, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Returns detailed browser distribution with version breakdown and percentages.
     *
     * Uses a window function for percentages on PostgreSQL; falls back to 0.0 on SQLite.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{browser: string, version: string|null, clicks: int, percentage: float}>
     */
    private function getBrowserDistribution(int $linkId, AnalyticsFilters $filters): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            return $this->baseQuery($linkId, $filters)
                ->selectRaw('browser, browser_version, COUNT(*) as clicks')
                ->whereNotNull('browser')
                ->groupBy('browser', 'browser_version')
                ->orderBy('clicks', 'desc')->limit(15)->get()
                ->map(fn ($r) => ['browser' => $r->browser, 'version' => $r->browser_version, 'clicks' => (int) $r->clicks, 'percentage' => 0.0])
                ->toArray();
        }

        return $this->baseQuery($linkId, $filters)
            ->selectRaw('browser, browser_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')
            ->orderBy('clicks', 'desc')->limit(15)->get()
            ->map(fn ($r) => ['browser' => $r->browser, 'version' => $r->browser_version, 'clicks' => (int) $r->clicks, 'percentage' => (float) $r->percentage])
            ->toArray();
    }

    /**
     * Returns detailed OS distribution with version breakdown and percentages.
     *
     * Uses a window function for percentages on PostgreSQL; falls back to 0.0 on SQLite.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{os: string, version: string|null, clicks: int, percentage: float}>
     */
    private function getOsDistribution(int $linkId, AnalyticsFilters $filters): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            return $this->baseQuery($linkId, $filters)
                ->selectRaw('os, os_version, COUNT(*) as clicks')
                ->whereNotNull('os')
                ->groupBy('os', 'os_version')
                ->orderBy('clicks', 'desc')->limit(15)->get()
                ->map(fn ($r) => ['os' => $r->os, 'version' => $r->os_version, 'clicks' => (int) $r->clicks, 'percentage' => 0.0])
                ->toArray();
        }

        return $this->baseQuery($linkId, $filters)
            ->selectRaw('os, os_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->whereNotNull('os')
            ->groupBy('os', 'os_version')
            ->orderBy('clicks', 'desc')->limit(15)->get()
            ->map(fn ($r) => ['os' => $r->os, 'version' => $r->os_version, 'clicks' => (int) $r->clicks, 'percentage' => (float) $r->percentage])
            ->toArray();
    }

    /**
     * Returns per-device performance stats (avg/min/max response_time) for the filter window.
     *
     * Only rows with both device and response_time non-null are included.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{device: string, avg_response_time: float, min_response_time: float, max_response_time: float, total_clicks: int}>
     */
    private function getDevicePerformance(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw('device, AVG(response_time) as avg_response_time, MIN(response_time) as min_response_time, MAX(response_time) as max_response_time, COUNT(*) as total_clicks')
            ->whereNotNull('device')->whereNotNull('response_time')
            ->groupBy('device')->orderBy('avg_response_time', 'asc')->get()
            ->map(fn ($r) => [
                'device' => $r->device,
                'avg_response_time' => round((float) $r->avg_response_time, 2),
                'min_response_time' => round((float) $r->min_response_time, 2),
                'max_response_time' => round((float) $r->max_response_time, 2),
                'total_clicks' => (int) $r->total_clicks,
            ])
            ->toArray();
    }

    /**
     * Returns top-10 language distribution derived from the Accept-Language header.
     *
     * Languages are extracted by {@see UserAgentParser::extractPrimaryLanguage}.
     * Rows without an accept_language value are skipped.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{language: string, clicks: int, percentage: float}>
     */
    private function getLanguageDistribution(int $linkId, AnalyticsFilters $filters): array
    {
        $clicks = $this->baseQuery($linkId, $filters)
            ->select('accept_language')
            ->whereNotNull('accept_language')
            ->get();

        $languageCounts = [];
        foreach ($clicks as $click) {
            $language = $this->uaParser->extractPrimaryLanguage($click->accept_language);
            if ($language) {
                $languageCounts[$language] = ($languageCounts[$language] ?? 0) + 1;
            }
        }

        arsort($languageCounts);
        $total = array_sum($languageCounts);
        if ($total === 0) {
            return [];
        }

        return array_slice(array_map(
            fn ($lang, $cnt) => ['language' => $lang, 'clicks' => $cnt, 'percentage' => round(($cnt / $total) * 100, 2)],
            array_keys($languageCounts),
            $languageCounts
        ), 0, 10);
    }

    /**
     * Returns a summary of click quality tiers for the given link and filter window.
     *
     * Provides counts per tier (organic, suspicious, likely_fraud) plus organic
     * percentage — used by the dashboard "Qualidade" card.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{organic: int, suspicious: int, likely_fraud: int, unscored: int, organic_percentage: float}
     */
    private function getQualitySummary(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->countClicks($linkId, $filters);

        $organic = $this->baseQuery($linkId, $filters)->where('quality_tier', 'organic')->count();
        $suspicious = $this->baseQuery($linkId, $filters)->where('quality_tier', 'suspicious')->count();
        $fraud = $this->baseQuery($linkId, $filters)->where('quality_tier', 'likely_fraud')->count();

        return [
            'organic' => $organic,
            'suspicious' => $suspicious,
            'likely_fraud' => $fraud,
            'unscored' => $total - $organic - $suspicious - $fraud,
            'organic_percentage' => $total > 0 ? round($organic / $total * 100, 1) : 0,
        ];
    }

    /**
     * Returns top 5 utm_source values for the given link and filter window.
     *
     * Joins clicks ↔ link_utms and groups by utm_source. Only rows with a non-null
     * utm_source are included. Returns empty array when no UTM-tagged clicks exist.
     *
     * The `percentage` field is relative to ALL UTM-tagged clicks for the link in the
     * filter window, not just the top-5 subset. A separate COUNT(*) query (clone of the
     * base query, before SELECT/GROUP BY/LIMIT are applied) is used as the denominator
     * so that percentages remain accurate when there are more than 5 UTM sources.
     *
     * Note: this method uses the Query Builder (not Eloquent) because the JOIN cannot
     * be expressed cleanly through `baseQuery()`. Filter constraints are re-applied
     * manually here to mirror what `AnalyticsFilters::applyToQuery()` does.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{source: string, clicks: int, percentage: float}>
     */
    private function getUtmTopSources(int $linkId, AnalyticsFilters $filters): array
    {
        $baseUtmQuery = DB::table('clicks')
            ->join('link_utms', 'clicks.id', '=', 'link_utms.click_id')
            ->where('clicks.link_id', $linkId)
            ->whereNotNull('link_utms.utm_source');

        if ($filters->dateFrom) {
            $baseUtmQuery->whereDate('clicks.created_at', '>=', $filters->dateFrom);
        }
        if ($filters->dateTo) {
            $baseUtmQuery->whereDate('clicks.created_at', '<=', $filters->dateTo);
        }
        if ($filters->excludeBots) {
            $baseUtmQuery->where('clicks.is_bot', false);
        }

        $totalUtmClicks = (clone $baseUtmQuery)->count();

        if ($totalUtmClicks === 0) {
            return [];
        }

        $results = $baseUtmQuery
            ->selectRaw('link_utms.utm_source as source, COUNT(*) as clicks')
            ->groupBy('link_utms.utm_source')
            ->orderByDesc('clicks')
            ->limit(5)
            ->get();

        return $results->map(fn ($r) => [
            'source' => $r->source,
            'clicks' => (int) $r->clicks,
            'percentage' => round($r->clicks / $totalUtmClicks * 100, 1),
        ])->toArray();
    }

    /**
     * Returns stats for clicks originating from mobile in-app browsers (IAB).
     *
     * Filters clicks where navigation_context = 'in_app_webview' AND is_mobile = 1.
     * The percentage is relative to all clicks for the link in the filter window.
     * ios_pct and android_pct are relative to the IAB segment, not total clicks.
     *
     * Also returns `navigation_context_available` — set to `true` when at least 20%
     * of clicks in the filter window have a non-null navigation_context value.
     * When this flag is false the frontend should show a Phase 1 disclaimer because
     * old clicks were recorded before the navigation_context field was introduced.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{total: int, percentage: float, ios_pct: float, android_pct: float, navigation_context_available: bool}
     */
    private function getSocialIabStats(int $linkId, AnalyticsFilters $filters): array
    {
        $allTotal = $this->countClicks($linkId, $filters);

        // Determine whether Phase 1 data (navigation_context) covers enough of the window.
        // At least 20% of clicks must have a non-null navigation_context to consider the
        // field available; below that threshold the data is too sparse to be meaningful
        // and the frontend should display a disclaimer.
        $navigationContextAvailable = false;
        if ($allTotal > 0) {
            $withContext = $this->baseQuery($linkId, $filters)
                ->whereNotNull('navigation_context')
                ->count();
            $navigationContextAvailable = ($withContext / $allTotal) >= 0.20;
        }

        $iabBase = fn () => $this->baseQuery($linkId, $filters)
            ->where('navigation_context', 'in_app_webview')
            ->where('is_mobile', 1);

        $total = $iabBase()->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'percentage' => 0.0,
                'ios_pct' => 0.0,
                'android_pct' => 0.0,
                'navigation_context_available' => $navigationContextAvailable,
            ];
        }

        $iosCount = $iabBase()->where('os', 'iOS')->count();
        $androidCount = $iabBase()->where('os', 'Android')->count();

        return [
            'total' => $total,
            'percentage' => $allTotal > 0 ? round($total / $allTotal * 100, 1) : 0.0,
            'ios_pct' => round($iosCount / $total * 100, 1),
            'android_pct' => round($androidCount / $total * 100, 1),
            'navigation_context_available' => $navigationContextAvailable,
        ];
    }

    /**
     * Returns hourly click patterns from the stored hour_of_day field (local timezone).
     *
     * Only rows with a pre-computed hour_of_day are included. This reflects the
     * server-side local-hour enrichment from Phase 2 of the tracking pipeline.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array<int, array{hour: int, clicks: int, avg_response_time: float, unique_visitors: int}>
     */
    private function getHourlyPatternsLocal(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
            ->selectRaw('hour_of_day, COUNT(*) as clicks, AVG(response_time) as avg_response_time, COUNT(DISTINCT ip) as unique_visitors')
            ->whereNotNull('hour_of_day')
            ->groupBy('hour_of_day')->orderBy('hour_of_day')->get()
            ->map(fn ($r) => [
                'hour' => (int) $r->hour_of_day,
                'clicks' => (int) $r->clicks,
                'avg_response_time' => round((float) $r->avg_response_time, 2),
                'unique_visitors' => (int) $r->unique_visitors,
            ])
            ->toArray();
    }

    /**
     * Returns aggregated weekend vs. weekday click comparison for the filter window.
     *
     * Uses the pre-computed is_weekend boolean column from the tracking pipeline.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{weekend: array, weekday: array}
     */
    private function getWeekendVsWeekday(int $linkId, AnalyticsFilters $filters): array
    {
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
     * Returns aggregated business-hours vs. non-business-hours comparison for the filter window.
     *
     * Uses the pre-computed is_business_hours boolean from the tracking pipeline.
     * Business hours are defined as 09:00–17:00 local time.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{business_hours: array, non_business_hours: array}
     */
    private function getBusinessHoursAnalysis(int $linkId, AnalyticsFilters $filters): array
    {
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
}
