<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Metrics service used by the link list endpoints.
 *
 * Computes sparkline and trend metrics for a single link. Results are cached
 * with a 5-minute TTL (CACHE_TTL = 300s) using the default cache driver.
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
     * Cached under "meta:sparkline:{linkId}:{days}d".
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $days  Number of days to include (default 7).
     * @return array<int, array{date: string, clicks: int}>
     */
    public function getLinkSparkline(int $linkId, int $days = 7): array
    {
        $cacheKey = "meta:sparkline:{$linkId}:{$days}d";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($linkId, $days) {
            $rows = DB::table('clicks')
                ->where('link_id', $linkId)
                ->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('DATE(created_at) as date, COUNT(*) as clicks')
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->keyBy('date');

            $result = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $result[] = [
                    'date' => $date,
                    'clicks' => (int) ($rows->get($date)?->clicks ?? 0),
                ];
            }

            return $result;
        });
    }

    /**
     * Returns a week-over-week click trend for a link.
     *
     * Compares the current $window-day period against the preceding $window-day
     * period. percent_change is +100.0 if previous is 0 and current > 0, or 0.0
     * if both are 0. Cached under "meta:trend:{linkId}:{window}d".
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $window  Comparison window in days (default 7).
     * @return array{current: int, previous: int, percent_change: float, last_click_at: string|null}
     */
    public function getLinkTrend(int $linkId, int $window = 7): array
    {
        $cacheKey = "meta:trend:{$linkId}:{$window}d";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($linkId, $window) {
            $now = now();

            $current = DB::table('clicks')
                ->where('link_id', $linkId)
                ->where('created_at', '>=', $now->copy()->subDays($window))
                ->count();

            $previous = DB::table('clicks')
                ->where('link_id', $linkId)
                ->whereBetween('created_at', [
                    $now->copy()->subDays($window * 2),
                    $now->copy()->subDays($window),
                ])
                ->count();

            $percentChange = $previous > 0
                ? round((($current - $previous) / $previous) * 100, 1)
                : ($current > 0 ? 100.0 : 0.0);

            $lastClick = DB::table('clicks')
                ->where('link_id', $linkId)
                ->orderByDesc('created_at')
                ->value('created_at');

            return [
                'current' => $current,
                'previous' => $previous,
                'percent_change' => $percentChange,
                'last_click_at' => $lastClick,
            ];
        });
    }
}
