<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

/**
 * Computes geographic analytics (heatmap, countries, states, cities, continents) for a link.
 *
 * @see \App\Contracts\Analytics\GeographicAnalyticsInterface
 *
 * All geo data is sourced from the clicks table columns populated by
 * LinkTrackingService::resolveDetailedLocation() (torann/geoip). Clicks from
 * localhost (127.0.0.1, ::1) are stored with country='localhost' and are
 * explicitly excluded from all geographic aggregations.
 *
 * The heatmap query is capped at 500 location groups to keep response size bounded.
 * Country/state/city top-N queries are capped at 10.
 *
 * Side effects: read-only queries. No cache, no queue, no log calls.
 */
class GeographicAnalyticsService implements \App\Contracts\Analytics\GeographicAnalyticsInterface
{
    private const CONTINENT_NAMES = [
        'NA' => 'América do Norte',
        'SA' => 'América do Sul',
        'EU' => 'Europa',
        'AS' => 'Ásia',
        'AF' => 'África',
        'OC' => 'Oceania',
        'AN' => 'Antártica',
    ];

    /**
     * Returns geographic analytics data and metadata for the given link.
     *
     * Returns empty data arrays when no clicks exist (link must exist or
     * ModelNotFoundException is thrown via Link::findOrFail).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @param  ?string  $continent  ISO 2-letter continent code ('NA','SA','EU','AS','AF','OC'), or null for all continents.
     * @param  int  $minClicks  Omit rows where clicks < $minClicks from aggregated results.
     * @return array{data: array{heatmap_data: array, top_countries: array, top_states: array, top_cities: array, continents: array}, meta: array}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If link does not exist.
     */
    public function getLinkGeographicAnalytics(int $linkId, ?AnalyticsFilters $filters = null, ?string $continent = null, int $minClicks = 0): array
    {
        $link = Link::findOrFail($linkId);
        $filters ??= new AnalyticsFilters;

        if (! $this->baseQuery($linkId, $filters, $continent)->exists()) {
            return [
                'data' => [
                    'heatmap_data' => [],
                    'top_countries' => [],
                    'top_states' => [],
                    'top_cities' => [],
                    'continents' => [],
                ],
                'meta' => $this->buildGeographicMeta($link, [], $linkId, $filters, $continent),
            ];
        }

        $heatmap = $this->getHeatmapData($linkId, $filters, $continent);

        return [
            'data' => [
                'heatmap_data' => $heatmap,
                'top_countries' => $this->filterByMinClicks($this->getTopCountriesOptimized($linkId, $filters, $continent), $minClicks),
                'top_states' => $this->filterByMinClicks($this->getTopStatesOptimized($linkId, $filters, $continent), $minClicks),
                'top_cities' => $this->filterByMinClicks($this->getTopCitiesOptimized($linkId, $filters, $continent), $minClicks),
                'continents' => $this->getTopContinents($linkId, $filters),
            ],
            'meta' => $this->buildGeographicMeta($link, $heatmap, $linkId, $filters, $continent),
        ];
    }

    /**
     * Build a base Eloquent query for geographic clicks, applying filters and continent scope.
     *
     * Excludes clicks with null, empty, or 'localhost' country values — these
     * are produced by LinkTrackingService when the request IP cannot be resolved.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     * @param  ?string  $continent  ISO 2-letter code, or null for all continents.
     * @return \Illuminate\Database\Eloquent\Builder Scoped Eloquent builder on the clicks table.
     */
    private function baseQuery(int $linkId, AnalyticsFilters $filters, ?string $continent = null): \Illuminate\Database\Eloquent\Builder
    {
        $query = $filters->applyToQuery(
            Click::where('link_id', $linkId)
                ->whereNotNull('country')
                ->where('country', '!=', 'localhost')
                ->where('country', '!=', '')
        );

        return $query->when($continent, fn ($q) => $q->where('continent', $continent));
    }

    /**
     * Omit rows where the 'clicks' key is below $minClicks.
     *
     * @param  array  $rows  Array of aggregated rows each containing a 'clicks' int.
     * @param  int  $minClicks  Minimum click threshold (inclusive). Values ≤ 1 are treated as no filter.
     * @return array Filtered and re-indexed array.
     */
    private function filterByMinClicks(array $rows, int $minClicks): array
    {
        if ($minClicks <= 1) {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($r) => ($r['clicks'] ?? 0) >= $minClicks));
    }

    /**
     * Return heatmap data (lat/lng groups) for the given link, respecting filters.
     *
     * Capped at 500 location groups ordered by click count descending.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     * @param  ?string  $continent  Continent scope, or null for all continents.
     * @return array<int, array{lat: float, lng: float, city: string, country: string, clicks: int, iso_code: ?string, currency: ?string, state_name: ?string, continent: ?string, timezone: ?string, last_click: ?string}>
     */
    private function getHeatmapData(int $linkId, AnalyticsFilters $filters, ?string $continent = null): array
    {
        return $this->baseQuery($linkId, $filters, $continent)
            ->toBase()
            ->selectRaw('latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone, COUNT(*) as clicks, MAX(created_at) as last_click')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->groupBy('latitude', 'longitude', 'city', 'country', 'iso_code', 'currency', 'state_name', 'continent', 'timezone')
            ->orderBy('clicks', 'desc')->limit(500)->get()
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
     * Return the top 10 countries by click count, respecting filters.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     * @param  ?string  $continent  Continent scope, or null for all continents.
     * @return array<int, array{country: string, iso_code: ?string, currency: ?string, clicks: int}>
     */
    private function getTopCountriesOptimized(int $linkId, AnalyticsFilters $filters, ?string $continent = null): array
    {
        return $this->baseQuery($linkId, $filters, $continent)
            ->toBase()
            ->selectRaw('country, iso_code, currency, COUNT(*) as clicks')
            ->groupBy('country', 'iso_code', 'currency')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['country' => $r->country, 'iso_code' => $r->iso_code, 'clicks' => (int) $r->clicks, 'currency' => $r->currency])
            ->toArray();
    }

    /**
     * Return the top 10 states/regions by click count, respecting filters.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     * @param  ?string  $continent  Continent scope, or null for all continents.
     * @return array<int, array{country: string, state: ?string, state_name: ?string, clicks: int}>
     */
    private function getTopStatesOptimized(int $linkId, AnalyticsFilters $filters, ?string $continent = null): array
    {
        return $this->baseQuery($linkId, $filters, $continent)
            ->toBase()
            ->selectRaw('country, state, state_name, COUNT(*) as clicks')
            ->whereNotNull('state')
            ->groupBy('country', 'state', 'state_name')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['country' => $r->country, 'state' => $r->state, 'state_name' => $r->state_name, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Return the top 10 cities by click count, respecting filters.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     * @param  ?string  $continent  Continent scope, or null for all continents.
     * @return array<int, array{city: string, country: string, state: ?string, clicks: int}>
     */
    private function getTopCitiesOptimized(int $linkId, AnalyticsFilters $filters, ?string $continent = null): array
    {
        return $this->baseQuery($linkId, $filters, $continent)
            ->toBase()
            ->selectRaw('city, country, state, COUNT(*) as clicks')
            ->whereNotNull('city')
            ->groupBy('city', 'country', 'state')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['city' => $r->city, 'country' => $r->country, 'state' => $r->state, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    /**
     * Return continent breakdown with click counts and percentages.
     *
     * Note: continent filter is intentionally NOT applied here — continents is always
     * a global breakdown to show the full picture even when scoping by a single continent.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     * @return array<int, array{continent: string, continent_name: string, clicks: int, percentage: float}>
     */
    private function getTopContinents(int $linkId, AnalyticsFilters $filters): array
    {
        $results = $filters->applyToQuery(
            Click::where('link_id', $linkId)
                ->whereNotNull('continent')
                ->where('continent', '!=', '')
        )
            ->toBase()
            ->selectRaw('continent, COUNT(*) as clicks')
            ->groupBy('continent')
            ->orderByDesc('clicks')
            ->get();

        $total = $results->sum('clicks');

        return $results->map(function ($row) use ($total) {
            return [
                'continent' => $row->continent,
                'continent_name' => self::CONTINENT_NAMES[$row->continent] ?? $row->continent,
                'clicks' => (int) $row->clicks,
                'percentage' => $total > 0
                    ? round(($row->clicks / $total) * 100, 1)
                    : 0.0,
            ];
        })->values()->toArray();
    }

    /**
     * Build geographic metadata using accurate COUNT(*) / COUNT(DISTINCT …) queries.
     *
     * Previous implementation derived totals from the heatmap array, which is
     * capped at 500 lat/lng groups and requires non-null coordinates. This caused
     * the metric cards to under-count whenever more than 500 distinct locations
     * existed or when clicks lacked geo-coordinates.
     *
     * `max_clicks` and `total_locations` are still sourced from the heatmap
     * because they describe the map visualisation, not the full dataset.
     *
     * @param  Link  $link  Link model instance.
     * @param  array  $heatmap  Pre-computed heatmap rows (may be capped at 500).
     * @param  int  $linkId  Link primary key (for fresh DB queries).
     * @param  AnalyticsFilters  $filters  Active date-range / bot-exclusion filters.
     * @param  ?string  $continent  Active continent scope, or null for all.
     */
    private function buildGeographicMeta(Link $link, array $heatmap, int $linkId, AnalyticsFilters $filters, ?string $continent = null): array
    {
        $base = fn () => $this->baseQuery($linkId, $filters, $continent);

        $clicks = array_column($heatmap, 'clicks');

        return [
            'total_clicks' => $base()->count(),
            'unique_countries' => $base()->distinct()->count('country'),
            'unique_states' => $base()->whereNotNull('state')->where('state', '!=', '')->distinct()->count('state'),
            'unique_cities' => $base()->whereNotNull('city')->where('city', '!=', '')->distinct()->count('city'),
            'max_clicks' => $clicks ? max($clicks) : 0,
            'total_locations' => count($heatmap),
            'last_updated' => now()->toISOString(),
            'link_info' => $this->linkInfo($link),
        ];
    }

    private function linkInfo(Link $link): array
    {
        return [
            'id' => $link->id,
            'title' => $link->title,
            'short_url' => $link->getShortedUrl(),
            'is_active' => $link->is_active,
        ];
    }
}
