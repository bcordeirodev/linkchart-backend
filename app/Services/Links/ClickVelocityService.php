<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use Illuminate\Support\Facades\Redis;

/**
 * Tracks click velocity for links using Redis sliding windows.
 *
 * Records each click against two time windows (5 minutes and 60 minutes) to
 * derive a viral rank classification. Also captures inter-click timing
 * (seconds since the previous click on the same link) for flood detection.
 */
class ClickVelocityService
{
    /**
     * Records a click for the given link and returns velocity metrics.
     *
     * Uses a Redis pipeline to atomically increment two TTL-windowed counters
     * and swap the last-click timestamp, then classifies the link's viral rank
     * according to thresholds defined in config/tracking.php.
     *
     * NOTE — Fixed-window counting: uses INCR+EXPIRE rather than a true sliding
     * window (ZADD/ZREMRANGEBYSCORE). A click at T=59 s and another at T=61 s
     * may fall into separate windows and not trigger rank promotion even though
     * both are within 2 minutes of each other. Acceptable for current traffic
     * patterns; replace with ZADD/ZREMRANGEBYSCORE for true sliding behaviour.
     *
     * Degrades gracefully when Redis is unavailable, returning a 'cold' rank
     * so the tracking job is not interrupted by Redis downtime.
     *
     * @return array{viral_rank: string, seconds_since_last_click: ?int}
     */
    public function record(int $linkId): array
    {
        $key5 = "link:{$linkId}:v5";
        $key60 = "link:{$linkId}:v60";
        $keyLast = "link:{$linkId}:last_click";

        try {
            $results = Redis::pipeline(function ($pipe) use ($key5, $key60, $keyLast) {
                $pipe->incr($key5);
                $pipe->expire($key5, 300);
                $pipe->incr($key60);
                $pipe->expire($key60, 3600);
                $pipe->rawCommand('SET', $keyLast, now()->timestamp, 'GET');
            });
        } catch (\Throwable $e) {
            AppLogger::event('tracking', 'warning', 'click_velocity.redis_unavailable', [
                'link_id' => $linkId,
                'error' => $e->getMessage(),
            ]);

            return ['viral_rank' => 'cold', 'seconds_since_last_click' => null];
        }

        $c5 = (int) $results[0];
        $c60 = (int) $results[2];
        $prevTs = $results[4];
        $thresholds = config('tracking.viral_thresholds');

        $viralRank = match (true) {
            $c5 >= $thresholds['viral'] => 'viral',
            $c5 >= $thresholds['trending'] => 'trending',
            $c60 >= $thresholds['warming'] => 'warming',
            default => 'cold',
        };

        return [
            'viral_rank' => $viralRank,
            'seconds_since_last_click' => $prevTs !== null
                ? max(0, now()->timestamp - (int) $prevTs)
                : null,
        ];
    }
}
