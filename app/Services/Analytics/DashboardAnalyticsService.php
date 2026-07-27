<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\AudienceAnalyticsInterface;
use App\Contracts\Analytics\GeographicAnalyticsInterface;
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
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
 * constraints.
 *
 * Since the 2026-07-27 dedup, this service owns ONLY the summary aggregations
 * (viral rank, quality, UTM, social IAB, counters). The temporal_data,
 * geographic_data and audience_data blocks are composed by delegating to the
 * domain services (TemporalAnalyticsInterface, GeographicAnalyticsInterface,
 * AudienceAnalyticsInterface) — the same single source that feeds the analytics
 * tabs — plus thin shape adapters where the historical dashboard payload
 * diverges (key order, dropped keys, localized labels). Do NOT re-implement an
 * aggregation here that a domain service already provides.
 *
 * Side effects: read-only queries. No cache, no queue, no log calls.
 */
class DashboardAnalyticsService implements \App\Contracts\Analytics\DashboardAnalyticsInterface
{
    /**
     * @param  GeographicAnalyticsInterface  $geographic  Source of heatmap/top-N geo aggregations.
     * @param  TemporalAnalyticsInterface  $temporal  Source of hour/day-of-week/local-pattern aggregations.
     * @param  AudienceAnalyticsInterface  $audience  Source of device/browser/OS/language aggregations.
     */
    public function __construct(
        private readonly GeographicAnalyticsInterface $geographic,
        private readonly TemporalAnalyticsInterface $temporal,
        private readonly AudienceAnalyticsInterface $audience,
    ) {}

    /**
     * Returns the full dashboard analytics payload for a link.
     *
     * Returns an empty dashboard structure if the link does not exist (no exception).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array<string, mixed> Keyed: summary (includes avg_daily_clicks and clicks_variation_pct), link_info, temporal_data, geographic_data, audience_data.
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
        $avgDaily = $this->avgDailyClicks($link, $filters, $totalClicks);

        return [
            'summary' => [
                'total_clicks' => $totalClicks,
                'total_links' => 1,
                'active_links' => $link->is_active ? 1 : 0,
                'unique_visitors' => $unique,
                'avg_daily_clicks' => $avgDaily,
                'clicks_variation_pct' => $this->clicksVariationPct($link, $filters, $totalClicks),
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
            'temporal_data' => $this->buildTemporalData($linkId, $filters),
            'geographic_data' => $this->buildGeographicData($linkId, $filters),
            'audience_data' => $this->buildAudienceData($linkId, $filters),
        ];
    }

    /**
     * Composes the temporal_data block from the temporal domain service.
     *
     * Adapter: clicks_by_hour rows are re-keyed to the historical dashboard
     * order (hour, clicks, label) — the domain service emits (hour, label,
     * clicks) and the frozen payload is byte-sensitive to key order.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{clicks_by_hour: array, clicks_by_day_of_week: array, hourly_patterns_local: array, weekend_vs_weekday: array, business_hours_analysis: array}
     */
    private function buildTemporalData(int $linkId, AnalyticsFilters $filters): array
    {
        return [
            'clicks_by_hour' => array_map(
                fn (array $row): array => ['hour' => $row['hour'], 'clicks' => $row['clicks'], 'label' => $row['label']],
                $this->temporal->getClicksByHour($linkId, $filters)
            ),
            'clicks_by_day_of_week' => $this->temporal->getClicksByDayOfWeek($linkId, $filters),
            'hourly_patterns_local' => $this->temporal->getHourlyPatternsLocal($linkId, $filters),
            'weekend_vs_weekday' => $this->temporal->getWeekendVsWeekdayLocal($linkId, $filters),
            'business_hours_analysis' => $this->temporal->getBusinessHoursAnalysisLocal($linkId, $filters),
        ];
    }

    /**
     * Composes the geographic_data block from the geographic domain service.
     *
     * Adapters over the domain payload (`data` sub-array):
     *  - heatmap_data: the unknown-city fallback label is localized back to
     *    'Cidade Desconhecida' (the domain/tab payload uses 'Unknown City');
     *  - top_cities: rows are re-keyed to the historical dashboard order
     *    (city, state, country, clicks) and the dashboard-unused
     *    most_common_postal_code key is dropped;
     *  - top_countries / top_states: passed through unchanged.
     *
     * Accepted unification deltas (domain semantics now apply): rows with an
     * empty-string country are excluded from the top-N lists (the legacy
     * dashboard queries only excluded NULL/localhost) and heatmap_data
     * inherits the domain's 500-location cap (the legacy query was unbounded).
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{heatmap_data: array, top_countries: array, top_states: array, top_cities: array}
     */
    private function buildGeographicData(int $linkId, AnalyticsFilters $filters): array
    {
        $geo = $this->geographic->getLinkGeographicAnalytics($linkId, $filters)['data'];

        return [
            'heatmap_data' => array_map(function (array $row): array {
                $row['city'] = $row['city'] === 'Unknown City' ? 'Cidade Desconhecida' : $row['city'];

                return $row;
            }, $geo['heatmap_data']),
            'top_countries' => $geo['top_countries'],
            'top_states' => $geo['top_states'],
            'top_cities' => array_map(
                fn (array $row): array => ['city' => $row['city'], 'state' => $row['state'], 'country' => $row['country'], 'clicks' => $row['clicks']],
                $geo['top_cities']
            ),
        ];
    }

    /**
     * Composes the audience_data block from the audience domain service.
     *
     * Adapter: device_breakdown rows drop the domain-side `percentage` key —
     * the historical dashboard shape only carries (device, clicks). Every
     * other breakdown is passed through unchanged.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{device_breakdown: array, browser_breakdown: array, os_breakdown: array, browsers: array, operating_systems: array, device_performance: array, languages: array}
     */
    private function buildAudienceData(int $linkId, AnalyticsFilters $filters): array
    {
        $audience = $this->audience->getLinkAudienceAnalytics($linkId, $filters);

        return [
            'device_breakdown' => array_map(
                fn (array $row): array => ['device' => $row['device'], 'clicks' => $row['clicks']],
                $audience['device_breakdown']
            ),
            'browser_breakdown' => $audience['browser_breakdown'],
            'os_breakdown' => $audience['os_breakdown'],
            'browsers' => $audience['browsers'],
            'operating_systems' => $audience['operating_systems'],
            'device_performance' => $audience['device_performance'],
            'languages' => $audience['languages'],
        ];
    }

    /**
     * Returns a base Eloquent query for the clicks table scoped to the given link and filters.
     *
     * Every private summary method should start with this builder and add its own
     * SELECT/WHERE/GROUP BY clauses. Centralising the filter application here ensures
     * date-range and bot-exclusion are consistently applied across all aggregations.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Filter constraints to apply.
     * @return Builder Eloquent builder for Click with link_id and filter scopes already applied.
     */
    private function baseQuery(int $linkId, AnalyticsFilters $filters): Builder
    {
        return $filters->applyToQuery(Click::where('link_id', $linkId));
    }

    /**
     * Returns the zeroed payload used when the link id does not exist.
     *
     * @return array<string, mixed> Same top-level shape as the real payload with empty/zero leaves.
     */
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
     * Average clicks per day over the active window.
     *
     * Window = the filter date range when set, otherwise from the link's
     * creation date until now. Floored at 1 day to avoid division by zero
     * and to keep brand-new links from reporting an inflated daily average.
     *
     * @param  Link  $link  The link being analysed.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @param  int  $totalClicks  Pre-computed total click count for the window.
     * @return float Clicks per day, rounded to one decimal.
     */
    private function avgDailyClicks(Link $link, AnalyticsFilters $filters, int $totalClicks): float
    {
        $start = $filters->dateFrom ?? $link->created_at;
        $end = $filters->dateTo ?? now();
        $days = max(1, (int) $start->diffInDays($end));

        return round($totalClicks / $days, 1);
    }

    /**
     * Percentage change in clicks between the current window and the equivalent
     * window immediately before it.
     *
     * The current window is `[start, end]` (filter date range, or since the
     * link's creation when unfiltered). The comparison window is the same length
     * directly before `start`, and honours the active drill-down (country, device,
     * channel, continent, bot exclusion) via `AnalyticsFilters::applyDimensions()` —
     * without it, filtering by country would compare that country's current window
     * against the whole link's previous window. Only the date range is NOT reapplied
     * here, since this query defines its own (shifted) window. Returns null when the
     * prior window has zero clicks — there is no meaningful baseline to compare
     * against, so the frontend renders the hero KPI without a variation pill.
     *
     * @param  Link  $link  The link being analysed.
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @param  int  $totalClicks  Pre-computed total click count for the current window.
     * @return float|null Signed percentage change (one decimal), or null when not comparable.
     */
    private function clicksVariationPct(Link $link, AnalyticsFilters $filters, int $totalClicks): ?float
    {
        $start = $filters->dateFrom ?? $link->created_at;
        $end = $filters->dateTo ?? now();
        $windowSeconds = max(1, (int) $start->diffInSeconds($end));
        $previousStart = $start->copy()->subSeconds($windowSeconds);

        $previousClicks = $filters->applyDimensions(
            Click::where('link_id', $link->id)
                ->where('created_at', '>=', $previousStart)
                ->where('created_at', '<', $start)
        )->count();

        if ($previousClicks === 0) {
            return null;
        }

        return round((($totalClicks - $previousClicks) / $previousClicks) * 100, 1);
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
     * be expressed cleanly through `baseQuery()`. Filter constraints are applied via
     * `AnalyticsFilters::applyToQuery()` with the `clicks.` prefix — the JOIN means
     * `created_at`, `is_bot`, `country`, etc. must be qualified or the SQL is ambiguous.
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

        $baseUtmQuery = $filters->applyToQuery($baseUtmQuery, 'clicks.');

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
}
