<?php

namespace App\Contracts\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use Illuminate\Database\Query\Builder;

/**
 * Contract for multi-link aggregated reports (the `/reports` module).
 *
 * Unlike every other Analytics contract in this namespace — all scoped to a
 * single `$linkId` — this interface aggregates across ALL of a user's own,
 * non-demo links. It is the aggregated counterpart of the per-link services
 * (`DashboardAnalyticsInterface` etc.), joining `clicks` to `links` and
 * scoping by `links.user_id`.
 *
 * Concrete implementation: {@see \App\Services\Analytics\ReportsAnalyticsService}.
 * Bound in {@see \App\Providers\AppServiceProvider::register()}.
 *
 * Consumed by {@see \App\Http\Controllers\Reports\ReportsController}.
 */
interface ReportsAnalyticsServiceInterface
{
    /**
     * Returns aggregated KPIs across all of the user's own, non-demo links.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filter state (date range, bot exclusion).
     * @return array{total_clicks: int, unique_visitors: int, total_links: int, active_links: int, avg_clicks_per_day: float, variation_pct: ?float}
     */
    public function getSummary(int $userId, AnalyticsFilters $filters): array;

    /**
     * Returns daily click counts across all of the user's own, non-demo links.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filter state (date range, bot exclusion).
     * @return array<int, array{date: string, clicks: int}>
     */
    public function getTimeseries(int $userId, AnalyticsFilters $filters): array;

    /**
     * Returns the user's top links by click count within the filter window.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filter state (date range, bot exclusion).
     * @param  int  $limit  Maximum number of links to return.
     * @return array<int, array{link_id: int, title: ?string, slug: string, short_domain: ?string, clicks: int, unique_visitors: int}>
     */
    public function getTopLinks(int $userId, AnalyticsFilters $filters, int $limit = 10): array;

    /**
     * Returns a click breakdown by a whitelisted dimension across all of the
     * user's own, non-demo links.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  string  $dimension  One of: country, device, browser, navigation_context, quality_tier.
     * @param  AnalyticsFilters  $filters  Filter state (date range, bot exclusion).
     * @param  int  $limit  Maximum number of rows to return.
     * @return array<int, array{label: string, clicks: int, pct: float}>
     *
     * @throws \InvalidArgumentException When `$dimension` is not whitelisted.
     */
    public function getBreakdown(int $userId, string $dimension, AnalyticsFilters $filters, int $limit = 10): array;

    /**
     * Returns the underlying query for the clicks CSV export — same
     * user/is_demo scope and filters as every other aggregation, selecting
     * only the columns safe to export.
     *
     * NEVER selects `clicks.ip` — the CSV export must stay LGPD-compliant.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filter state (date range, bot exclusion).
     * @return Builder Query builder selecting: created_at, title, slug, country, city, device, browser, os, referer, navigation_context, quality_tier.
     */
    public function exportClicksQuery(int $userId, AnalyticsFilters $filters): Builder;

    /**
     * Returns the user's own, non-demo links ranked by clicks in the filter
     * window — the portfolio "leaderboard". Unlike {@see getTopLinks()},
     * each row also carries the variation vs. the immediately preceding
     * period of equal length and this link's share of the user's TOTAL
     * clicks in the window (not just among the returned rows).
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filter state (date range, bot exclusion).
     * @param  int  $limit  Maximum number of links to return.
     * @return array<int, array{link_id: int, title: ?string, slug: string, short_domain: ?string, clicks: int, variation_pct: ?float, share_pct: float}>
     */
    public function getLinkPerformance(int $userId, AnalyticsFilters $filters, int $limit = 10): array;

    /**
     * Returns portfolio-level (account-wide) computed insights — best
     * performing link, fastest growing link, top-3 traffic concentration and
     * overall account growth vs. the previous period. These are distinct
     * from the per-link callouts in `InsightsAnalyticsService`: every value
     * here only makes sense aggregated across the user's whole portfolio of
     * links, not for a single one.
     *
     * Values are raw and language-agnostic — the frontend maps `key` to a
     * localized label + icon. `value` is `null` when the insight cannot be
     * computed (e.g. no clicks in the window, or no comparable previous
     * period for the growth-based insights).
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filter state (date range, bot exclusion).
     * @return array<int, array{key: string, value: string|int|float|null, unit: ?string, meta: ?array}>
     */
    public function getInsights(int $userId, AnalyticsFilters $filters): array;
}
