<?php

namespace App\Services\Links;

use Illuminate\Support\Facades\Log;
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
     * Degrades gracefully when Redis is unavailable, returning a 'cold' rank
     * so the tracking job is not interrupted by Redis downtime.
     *
     * @param  int  $linkId
     * @return array{viral_rank: string, seconds_since_last_click: ?int}
     */
    public function record(int $linkId): array
    {
        $key5    = "link:{$linkId}:v5";
        $key60   = "link:{$linkId}:v60";
        $keyLast = "link:{$linkId}:last_click";

        try {
            $results = Redis::pipeline(function ($pipe) use ($key5, $key60, $keyLast) {
                $pipe->incr($key5);
                $pipe->expire($key5, 300);
                $pipe->incr($key60);
                $pipe->expire($key60, 3600);
                $pipe->getset($keyLast, now()->timestamp);
            });
        } catch (\Throwable $e) {
            Log::warning('ClickVelocityService: Redis unavailable, returning cold rank', [
                'link_id' => $linkId,
                'error'   => $e->getMessage(),
            ]);

            return ['viral_rank' => 'cold', 'seconds_since_last_click' => null];
        }

        $c5         = (int) $results[0];
        $c60        = (int) $results[2];
        $prevTs     = $results[4];
        $thresholds = config('tracking.viral_thresholds');

        $viralRank = match(true) {
            $c5  >= $thresholds['viral']    => 'viral',
            $c5  >= $thresholds['trending'] => 'trending',
            $c60 >= $thresholds['warming']  => 'warming',
            default                         => 'cold',
        };

        return [
            'viral_rank'               => $viralRank,
            'seconds_since_last_click' => $prevTs !== null
                ? max(0, now()->timestamp - (int) $prevTs)
                : null,
        ];
    }
}
