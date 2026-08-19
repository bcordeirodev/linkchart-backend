<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Metrics service used by the link list endpoints.
 *
 * Computes sparkline and trend metrics for links. The batch variants
 * (getLinkSparklineBatch / getLinkTrendBatch) aggregate every requested id in
 * a single GROUP BY link_id query — they exist for POST /api/links/batch-meta,
 * where the old one-link-at-a-time methods caused ~4 queries per id (N+1).
 * The single-link methods delegate to the batch ones, so both paths share the
 * same aggregation logic and the same cache entries.
 *
 * Results are cached per link with a 5-minute TTL (CACHE_TTL = 300s) using the
 * default cache driver; batch calls only query the ids that missed the cache.
 *
 * Side effects:
 *   - Reads the clicks table.
 *   - Reads/writes Laravel cache (driver-dependent key prefix "meta:*").
 */
class MetricsService
{
    /**
     * Cache key patterns (5-minute TTL for all computed metrics).
     */
    private const CACHE_TTL = 300; // 5 minutos

    /**
     * Returns daily click counts for the last N days (sparkline data for a link).
     *
     * Returns exactly $days entries, filling zeros for days with no clicks.
     * Cached under "meta:sparkline:{linkId}:{days}d". Delegates to
     * {@see self::getLinkSparklineBatch()} so single and batch callers share
     * one aggregation implementation and one cache entry per link.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $days  Number of days to include (default 7).
     * @return array<int, array{date: string, clicks: int}>
     */
    public function getLinkSparkline(int $linkId, int $days = 7): array
    {
        return $this->getLinkSparklineBatch([$linkId], $days)[$linkId];
    }

    /**
     * Returns the sparkline series for many links in ONE grouped query.
     *
     * Cache-aware: ids already cached under "meta:sparkline:{id}:{days}d" are
     * served from cache; only the misses hit the DB (single query grouped by
     * link_id + day), and each computed series is cached individually so the
     * single-link endpoint and subsequent batches reuse it.
     *
     * Every requested id is present in the result — links without clicks get
     * a fully zero-filled series, exactly like getLinkSparkline().
     *
     * @param  array<int, int>  $linkIds  Link primary keys (duplicates ignored).
     * @param  int  $days  Number of days to include (default 7).
     * @return array<int, array<int, array{date: string, clicks: int}>> Map of link id => series.
     */
    public function getLinkSparklineBatch(array $linkIds, int $days = 7): array
    {
        $linkIds = array_values(array_unique(array_map('intval', $linkIds)));

        if ($linkIds === []) {
            return [];
        }

        $result = [];
        $missing = [];

        foreach ($linkIds as $linkId) {
            $cached = Cache::get("meta:sparkline:{$linkId}:{$days}d");

            if ($cached !== null) {
                $result[$linkId] = $cached;
            } else {
                $missing[] = $linkId;
            }
        }

        if ($missing === []) {
            return $result;
        }

        $rows = DB::table('clicks')
            ->whereIn('link_id', $missing)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('link_id, DATE(created_at) as date, COUNT(*) as clicks')
            ->groupBy('link_id', 'date')
            ->get()
            ->groupBy('link_id');

        foreach ($missing as $linkId) {
            $byDate = ($rows->get($linkId) ?? collect())->keyBy('date');

            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $series[] = [
                    'date' => $date,
                    'clicks' => (int) ($byDate->get($date)?->clicks ?? 0),
                ];
            }

            Cache::put("meta:sparkline:{$linkId}:{$days}d", $series, self::CACHE_TTL);
            $result[$linkId] = $series;
        }

        return $result;
    }

    /**
     * Returns a week-over-week click trend for a link.
     *
     * Compares the current $window-day period against the preceding $window-day
     * period. percent_change is +100.0 if previous is 0 and current > 0, or 0.0
     * if both are 0. Cached under "meta:trend:{linkId}:{window}d". Delegates to
     * {@see self::getLinkTrendBatch()} so single and batch callers share one
     * aggregation implementation and one cache entry per link.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $window  Comparison window in days (default 7).
     * @return array{current: int, previous: int, percent_change: float, last_click_at: string|null}
     */
    public function getLinkTrend(int $linkId, int $window = 7): array
    {
        return $this->getLinkTrendBatch([$linkId], $window)[$linkId];
    }

    /**
     * Returns the click trend for many links in ONE grouped query.
     *
     * Replaces the old 3-queries-per-link version (current count, previous
     * count, last click) with a single conditional-aggregation query grouped
     * by link_id. Window semantics are identical to getLinkTrend(): current is
     * created_at >= now - window; previous is the inclusive
     * [now - 2*window, now - window] range (matching the original
     * whereBetween); last_click_at is the raw MAX(created_at) with no time
     * bound, as a driver-formatted string or null.
     *
     * Cache-aware per id under "meta:trend:{id}:{window}d", same TTL and
     * payload shape as the single-link method.
     *
     * @param  array<int, int>  $linkIds  Link primary keys (duplicates ignored).
     * @param  int  $window  Comparison window in days (default 7).
     * @return array<int, array{current: int, previous: int, percent_change: float, last_click_at: string|null}> Map of link id => trend.
     */
    public function getLinkTrendBatch(array $linkIds, int $window = 7): array
    {
        $linkIds = array_values(array_unique(array_map('intval', $linkIds)));

        if ($linkIds === []) {
            return [];
        }

        $result = [];
        $missing = [];

        foreach ($linkIds as $linkId) {
            $cached = Cache::get("meta:trend:{$linkId}:{$window}d");

            if ($cached !== null) {
                $result[$linkId] = $cached;
            } else {
                $missing[] = $linkId;
            }
        }

        if ($missing === []) {
            return $result;
        }

        $now = now();
        $currentFrom = $now->copy()->subDays($window);
        $previousFrom = $now->copy()->subDays($window * 2);

        $rows = DB::table('clicks')
            ->whereIn('link_id', $missing)
            ->selectRaw(
                'link_id, '
                .'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as current_clicks, '
                .'SUM(CASE WHEN created_at >= ? AND created_at <= ? THEN 1 ELSE 0 END) as previous_clicks, '
                .'MAX(created_at) as last_click_at',
                [$currentFrom, $previousFrom, $currentFrom]
            )
            ->groupBy('link_id')
            ->get()
            ->keyBy('link_id');

        foreach ($missing as $linkId) {
            $row = $rows->get($linkId);

            $current = (int) ($row->current_clicks ?? 0);
            $previous = (int) ($row->previous_clicks ?? 0);

            $percentChange = $previous > 0
                ? round((($current - $previous) / $previous) * 100, 1)
                : ($current > 0 ? 100.0 : 0.0);

            $trend = [
                'current' => $current,
                'previous' => $previous,
                'percent_change' => $percentChange,
                'last_click_at' => $row->last_click_at ?? null,
            ];

            Cache::put("meta:trend:{$linkId}:{$window}d", $trend, self::CACHE_TTL);
            $result[$linkId] = $trend;
        }

        return $result;
    }

    /**
     * Returns the traffic-quality aggregate for many links in ONE grouped query.
     *
     * Classifies each link by the share of ORGANIC clicks among its scored
     * clicks (quality_tier NOT NULL — Phase 3 of the tracking enrichment)
     * inside the window:
     *
     *   - organic_pct >= 90  → tier "organic"
     *   - organic_pct >= 50  → tier "suspicious"
     *   - organic_pct <  50  → tier "likely_fraud"
     *   - no scored clicks   → tier null, organic_pct null (UI hides the dot)
     *
     * Pre-Phase-3 clicks (quality_tier NULL) are excluded from the ratio so
     * old unscored traffic can never dilute the signal. Cache-aware per id
     * under "meta:quality:{id}:{days}d", same TTL as the other batch metrics.
     * Consumed by POST /api/links/batch-meta (links list quality dot).
     *
     * @param  array<int, int>  $linkIds  Link primary keys (duplicates ignored).
     * @param  int  $days  Lookback window in days (default 30).
     * @return array<int, array{tier: string|null, organic_pct: float|null}> Map of link id => quality.
     */
    public function getLinkQualityBatch(array $linkIds, int $days = 30): array
    {
        $linkIds = array_values(array_unique(array_map('intval', $linkIds)));

        if ($linkIds === []) {
            return [];
        }

        $result = [];
        $missing = [];

        foreach ($linkIds as $linkId) {
            $cached = Cache::get("meta:quality:{$linkId}:{$days}d");

            if ($cached !== null) {
                $result[$linkId] = $cached;
            } else {
                $missing[] = $linkId;
            }
        }

        if ($missing === []) {
            return $result;
        }

        $from = now()->subDays($days);

        $rows = DB::table('clicks')
            ->whereIn('link_id', $missing)
            ->where('created_at', '>=', $from)
            ->whereNotNull('quality_tier')
            ->selectRaw(
                'link_id, '
                ."SUM(CASE WHEN quality_tier = 'organic' THEN 1 ELSE 0 END) as organic_clicks, "
                .'COUNT(*) as scored_clicks'
            )
            ->groupBy('link_id')
            ->get()
            ->keyBy('link_id');

        foreach ($missing as $linkId) {
            $row = $rows->get($linkId);
            $scored = (int) ($row->scored_clicks ?? 0);

            if ($scored === 0) {
                $quality = ['tier' => null, 'organic_pct' => null];
            } else {
                $organicPct = round(((int) $row->organic_clicks / $scored) * 100, 1);
                $quality = [
                    'tier' => match (true) {
                        $organicPct >= 90 => 'organic',
                        $organicPct >= 50 => 'suspicious',
                        default => 'likely_fraud',
                    },
                    'organic_pct' => $organicPct,
                ];
            }

            Cache::put("meta:quality:{$linkId}:{$days}d", $quality, self::CACHE_TTL);
            $result[$linkId] = $quality;
        }

        return $result;
    }
}
