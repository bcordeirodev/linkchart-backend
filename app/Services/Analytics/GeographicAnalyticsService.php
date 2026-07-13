<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;
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
 *
 * Continent codes stored in the `clicks.continent` column are the 2-letter
 * ISO codes emitted by the torann/geoip package (NA, SA, EU, AS, AF, OC, AN).
 * The PHP-side `CONTINENT_NAMES` map was removed in favour of frontend i18n
 * lookups so that continent labels are translated in the user's active locale.
 */
class GeographicAnalyticsService implements \App\Contracts\Analytics\GeographicAnalyticsInterface
{
    /**
     * Returns geographic analytics data and metadata for the given link.
     *
     * Returns empty data arrays when no clicks exist (link must exist or
     * ModelNotFoundException is thrown via Link::findOrFail).
     *
     * The continent scope travels inside `$filters` (`AnalyticsFilters::$continent`)
     * instead of as a loose argument — `baseQuery()` applies it via
     * `AnalyticsFilters::applyToQuery()`/`applyDimensions()`, so it composes
     * correctly with the `country` drill-down filter and participates in the
     * orchestrator's cache key.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion, drill-down dimensions incl. continent). Null = no filter applied.
     * @param  int  $minClicks  Omit rows where clicks < $minClicks from aggregated results.
     * @return array{data: array{heatmap_data: array, top_countries: array, top_states: array, top_cities: array, continents: array}, meta: array}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If link does not exist.
     */
    public function getLinkGeographicAnalytics(int $linkId, ?AnalyticsFilters $filters = null, int $minClicks = 0): array
    {
        $link = Link::findOrFail($linkId);
        $filters ??= new AnalyticsFilters;

        if (! $this->baseQuery($linkId, $filters)->exists()) {
            return [
                'data' => [
                    'heatmap_data' => [],
                    'top_countries' => [],
                    'top_states' => [],
                    'top_cities' => [],
                    'continents' => [],
                ],
                'meta' => $this->buildGeographicMeta($link, [], $linkId, $filters),
            ];
        }

        [$heatmap, $heatmapCapped, $totalLocationsAvailable] = $this->getHeatmapData($linkId, $filters);

        return [
            'data' => [
                'heatmap_data' => $heatmap,
                'top_countries' => $this->filterByMinClicks($this->getTopCountriesOptimized($linkId, $filters), $minClicks),
                'top_states' => $this->filterByMinClicks($this->getTopStatesOptimized($linkId, $filters), $minClicks),
                'top_cities' => $this->filterByMinClicks($this->getTopCitiesOptimized($linkId, $filters), $minClicks),
                'continents' => $this->getTopContinents($linkId, $filters),
            ],
            'meta' => $this->buildGeographicMeta($link, $heatmap, $linkId, $filters, $heatmapCapped, $totalLocationsAvailable),
        ];
    }

    /**
     * Build a base Eloquent query for geographic clicks, applying filters (incl. continent).
     *
     * Excludes clicks with null, empty, or 'localhost' country values — these
     * are produced by LinkTrackingService when the request IP cannot be resolved.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range, bot-exclusion and drill-down (incl. continent) constraints.
     * @return \Illuminate\Database\Eloquent\Builder Scoped Eloquent builder on the clicks table.
     */
    private function baseQuery(int $linkId, AnalyticsFilters $filters): \Illuminate\Database\Eloquent\Builder
    {
        return $filters->applyToQuery(
            Click::where('link_id', $linkId)
                ->whereNotNull('country')
                ->where('country', '!=', 'localhost')
                ->where('country', '!=', '')
        );
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
     * Also returns a flag indicating whether the 500-row cap was hit, and
     * the total number of distinct location groups before the cap is applied.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range, bot-exclusion and drill-down (incl. continent) constraints.
     * @return array{0: array<int, array{lat: float, lng: float, city: string, country: string, clicks: int, iso_code: ?string, currency: ?string, state_name: ?string, continent: ?string, timezone: ?string, last_click: ?string}>, 1: bool, 2: int}
     *                                                                                                                                                                                                                                                 Tuple of [heatmap rows, heatmap_capped flag, total_locations_available].
     */
    private function getHeatmapData(int $linkId, AnalyticsFilters $filters): array
    {
        $baseGeoQuery = $this->baseQuery($linkId, $filters)
            ->toBase()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Count all distinct location groups BEFORE the LIMIT so we can expose
        // the real total and detect when the 500-row cap is active.
        $totalLocationsAvailable = (clone $baseGeoQuery)
            ->selectRaw('COUNT(*) OVER () as cnt')
            ->selectRaw('latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone')
            ->groupBy('latitude', 'longitude', 'city', 'country', 'iso_code', 'currency', 'state_name', 'continent', 'timezone')
            ->get()
            ->count();

        $heatmap = (clone $baseGeoQuery)
            ->selectRaw('latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone, COUNT(*) as clicks, MAX(created_at) as last_click')
            ->groupBy('latitude', 'longitude', 'city', 'country', 'iso_code', 'currency', 'state_name', 'continent', 'timezone')
            ->orderBy('clicks', 'desc')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'city' => $r->city ?: 'Unknown City',
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

        $heatmapCapped = count($heatmap) >= 500;

        return [$heatmap, $heatmapCapped, $totalLocationsAvailable];
    }

    /**
     * Return the top 10 countries by click count, respecting filters.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range, bot-exclusion and drill-down (incl. continent) constraints.
     * @return array<int, array{country: string, iso_code: ?string, currency: ?string, clicks: int}>
     */
    private function getTopCountriesOptimized(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
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
     * @param  AnalyticsFilters  $filters  Date-range, bot-exclusion and drill-down (incl. continent) constraints.
     * @return array<int, array{country: string, state: ?string, state_name: ?string, clicks: int}>
     */
    private function getTopStatesOptimized(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->baseQuery($linkId, $filters)
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
     * Includes the most common postal code observed for each city group, computed
     * via a subquery that counts occurrences of each distinct postal code within
     * the (city, country, state) partition and picks the one with the highest
     * count. This is a portable approximation of PostgreSQL's `MODE() WITHIN GROUP`.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range, bot-exclusion and drill-down (incl. continent) constraints.
     * @return array<int, array{city: string, country: string, state: ?string, clicks: int, most_common_postal_code: ?string}>
     */
    private function getTopCitiesOptimized(int $linkId, AnalyticsFilters $filters): array
    {
        $rows = $this->baseQuery($linkId, $filters)
            ->toBase()
            ->selectRaw('city, country, state, COUNT(*) as clicks')
            ->whereNotNull('city')
            ->groupBy('city', 'country', 'state')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get();

        return $rows->map(function ($r) use ($linkId, $filters) {
            // Resolve the most common postal code for this (city, country, state) group.
            $postalQuery = $this->baseQuery($linkId, $filters)
                ->toBase()
                ->selectRaw('postal_code, COUNT(*) as cnt')
                ->whereNotNull('city')
                ->whereNotNull('postal_code')
                ->where('postal_code', '!=', '')
                ->where('city', $r->city)
                ->where('country', $r->country)
                ->where('state', $r->state)
                ->groupBy('postal_code')
                ->orderByDesc('cnt')
                ->limit(1)
                ->first();

            return [
                'city' => $r->city,
                'country' => $r->country,
                'state' => $r->state,
                'clicks' => (int) $r->clicks,
                'most_common_postal_code' => $postalQuery?->postal_code ?? null,
            ];
        })->toArray();
    }

    /**
     * Return continent breakdown with click counts and percentages.
     *
     * Note: the continent filter is intentionally NOT applied here — this
     * breakdown is the continent *selector* the frontend's ContinentBreakdown
     * draws as a donut and highlights via `activeContinentCode`, so it must
     * always show every continent, even while one is selected, or the
     * highlight loses its meaning (see `AnalyticsFilters::withoutContinent()`).
     * Every other dimension (country, device, channel, bots, dates) is still
     * honoured — filtering by device must show the continent split of that
     * device's clicks.
     *
     * The `continent` column stores 2-letter ISO codes as emitted by the
     * torann/geoip package (NA, SA, EU, AS, AF, OC, AN). No server-side name
     * translation is performed; the frontend maps codes to localised names via
     * `geographic.continents.<CODE>` i18n keys.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     * @return array<int, array{continent_code: string, clicks: int, percentage: float}>
     */
    private function getTopContinents(int $linkId, AnalyticsFilters $filters): array
    {
        $results = $filters->withoutContinent()->applyToQuery(
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
                'continent_code' => $row->continent,
                // Keep the legacy `continent` key so existing clients that read
                // `c.continent` (e.g. ContinentBreakdown activeContinentCode) still work.
                'continent' => $row->continent,
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
     * `last_updated` reflects the `MAX(created_at)` of all clicks matching the
     * active filters, not `now()`.  Returns `null` when no clicks match.
     *
     * @param  Link  $link  Link model instance.
     * @param  array  $heatmap  Pre-computed heatmap rows (may be capped at 500).
     * @param  int  $linkId  Link primary key (for fresh DB queries).
     * @param  AnalyticsFilters  $filters  Active date-range, bot-exclusion and drill-down (incl. continent) filters.
     * @param  bool  $heatmapCapped  Whether the heatmap hit the 500-row cap.
     * @param  int  $totalLocationsAvailable  Total distinct location groups before the cap.
     */
    private function buildGeographicMeta(
        Link $link,
        array $heatmap,
        int $linkId,
        AnalyticsFilters $filters,
        bool $heatmapCapped = false,
        int $totalLocationsAvailable = 0,
    ): array {
        $base = fn () => $this->baseQuery($linkId, $filters);

        $clicks = array_column($heatmap, 'clicks');

        // Use MAX(created_at) from actual filtered clicks — never `now()`.
        $lastClick = $base()->toBase()->max('created_at');

        return [
            'total_clicks' => $base()->count(),
            'unique_countries' => $base()->distinct()->count('country'),
            'unique_states' => $base()->whereNotNull('state')->where('state', '!=', '')->distinct()->count('state'),
            'unique_cities' => $base()->whereNotNull('city')->where('city', '!=', '')->distinct()->count('city'),
            'max_clicks' => $clicks ? max($clicks) : 0,
            'total_locations' => count($heatmap),
            'heatmap_capped' => $heatmapCapped,
            'total_locations_available' => $totalLocationsAvailable,
            'last_updated' => $lastClick ? Carbon::parse($lastClick)->toISOString() : null,
            'link_info' => $this->linkInfo($link),
        ];
    }

    /**
     * Build the link_info sub-object embedded in geographic meta.
     *
     * @param  Link  $link  Link model instance.
     * @return array{id: int, title: string, short_url: string, is_active: bool}
     */
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
