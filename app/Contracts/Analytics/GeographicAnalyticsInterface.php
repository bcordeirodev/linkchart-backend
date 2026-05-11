<?php

namespace App\Contracts\Analytics;

/**
 * Contract for per-link geographic analytics (countries, regions, cities, heatmap).
 *
 * Aggregates click data from the `clicks` table using the GeoIP-enriched columns
 * (`country`, `state`, `city`, `latitude`, `longitude`, `continent`, `iso_code`,
 * `timezone`, `currency`) and returns a structured payload suitable for map
 * visualisations and top-N breakdowns.
 *
 * Concrete implementation: {@see \App\Services\Analytics\GeographicAnalyticsService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()} via
 * `$this->app->bind(GeographicAnalyticsInterface::class, GeographicAnalyticsService::class)`.
 *
 * Consumed by {@see \App\Services\Analytics\LinkAnalyticsOrchestrator} and
 * `AnalyticsController`'s `geographic` endpoint.
 */
interface GeographicAnalyticsInterface
{
    /**
     * Return geographic analytics for the given link.
     *
     * Throws `\Illuminate\Database\Eloquent\ModelNotFoundException` if `$linkId`
     * does not exist. Returns an array with empty sub-arrays when the link exists
     * but has no recorded clicks.
     *
     * Return shape:
     * ```
     * [
     *   'data' => [
     *     'heatmap_data'  => array<int, array{lat: float, lng: float, city: string, country: string,
     *                                         clicks: int, iso_code: ?string, currency: ?string,
     *                                         state_name: ?string, continent: ?string, timezone: ?string,
     *                                         last_click: ?string}>,
     *     'top_countries' => array<int, array{country: string, iso_code: ?string, currency: ?string, clicks: int}>,
     *     'top_states'    => array<int, array{country: string, state: ?string, state_name: ?string, clicks: int}>,
     *     'top_cities'    => array<int, array{city: string, state: ?string, country: string, clicks: int}>,
     *     'continents'    => array<int, array{continent: string, continent_name: string, clicks: int, percentage: float}>,
     *   ],
     *   'meta' => array{total_countries: int, total_cities: int, primary_country: ?string, coverage_percentage: float},
     * ]
     * ```
     *
     * @param  int  $linkId  ID of the link to analyse.
     * @return array<string, mixed> Geographic analytics payload as described above.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If `$linkId` does not exist.
     */
    public function getLinkGeographicAnalytics(int $linkId): array;
}
