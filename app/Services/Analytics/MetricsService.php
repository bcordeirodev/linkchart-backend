<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Multi-purpose legacy metrics service used by the dashboard and link list endpoints.
 *
 * Computes basic metrics (total clicks, unique visitors, response time, top links,
 * sparklines, trend comparisons) for a user or a single link. Results are cached
 * with a 5-minute TTL (CACHE_TTL = 300s) using the default cache driver.
 *
 * Note: clearUserMetricsCache uses Cache::forget with wildcard patterns
 * (e.g. "metrics:user:{id}:*"). This is a no-op on drivers that do not support
 * tag-based or pattern-based clearing (the default file/database drivers do NOT
 * support it; only Redis does). Callers should not rely on cache invalidation
 * from this method — treat it as best-effort.
 *
 * Side effects:
 *   - Reads links and clicks tables.
 *   - Reads/writes Laravel cache (driver-dependent key prefix "metrics:*").
 *   - getUserChartData logs via AppLogger::analyticsError on exception.
 */
class MetricsService
{
    /**
     * Cache key patterns (5-minute TTL for all computed metrics).
     */
    private const CACHE_TTL = 300; // 5 minutos

    private const CACHE_KEYS = [
        'user_metrics' => 'metrics:user:{userId}',
        'link_metrics' => 'metrics:link:{linkId}',
        'daily_metrics' => 'metrics:daily:{date}',
        'hourly_metrics' => 'metrics:hourly:{hour}',
    ];

    /**
     * Returns basic aggregate metrics for all links owned by a user.
     *
     * Cached under "metrics:user:{userId}:basic:{hours}h" for CACHE_TTL seconds.
     *
     * @param  int  $userId  User primary key.
     * @param  int  $hours  Lookback window for recent/unique counts (default 24h).
     * @return array{total_clicks: int, total_links: int, active_links: int, recent_clicks: int, unique_visitors: int, links_with_traffic: int, avg_clicks_per_link: float, conversion_rate: float, success_rate: float, timeframe_hours: int}
     */
    public function getUserBasicMetrics(int $userId, int $hours = 24): array
    {
        $cacheKey = "metrics:user:{$userId}:basic:{$hours}h";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId, $hours) {
            // Buscar links do usuário
            $userLinks = Link::where('user_id', $userId)->get();
            $linkIds = $userLinks->pluck('id')->toArray();

            if (empty($linkIds)) {
                return $this->getEmptyMetrics();
            }

            $timeframe = now()->subHours($hours);

            // Métricas básicas
            $totalClicks = $userLinks->sum('clicks');
            $totalLinks = $userLinks->count();
            $activeLinks = $userLinks->where('is_active', true)->count();

            // Cliques recentes
            $recentClicks = Click::whereIn('link_id', $linkIds)
                ->where('created_at', '>=', $timeframe)
                ->count();

            // Visitantes únicos recentes
            $uniqueVisitors = Click::whereIn('link_id', $linkIds)
                ->where('created_at', '>=', $timeframe)
                ->distinct('ip')
                ->count();

            // Links com tráfego
            $linksWithTraffic = $userLinks->where('clicks', '>', 0)->count();

            // Média de cliques por link
            $avgClicksPerLink = $totalLinks > 0 ? round($totalClicks / $totalLinks, 1) : 0;

            // Taxa de conversão
            $conversionRate = $recentClicks > 0 ? round(($uniqueVisitors / $recentClicks) * 100, 1) : 0;

            // Taxa de sucesso
            $successRate = $this->calculateSuccessRate($userId, $linkIds, $recentClicks);

            return [
                'total_clicks' => $totalClicks,
                'total_links' => $totalLinks,
                'active_links' => $activeLinks,
                'recent_clicks' => $recentClicks,
                'unique_visitors' => $uniqueVisitors,
                'links_with_traffic' => $linksWithTraffic,
                'avg_clicks_per_link' => $avgClicksPerLink,
                'conversion_rate' => $conversionRate,
                'success_rate' => $successRate,
                'timeframe_hours' => $hours,
            ];
        });
    }

    /**
     * Returns performance-focused metrics for all links owned by a user.
     *
     * Average response time is derived from hourly cache buckets written by
     * RedirectMetricsCollector middleware (key prefix "redirect_metrics:hour:*"),
     * not directly from the clicks table.
     *
     * Cached under "metrics:user:{userId}:performance:{hours}h".
     *
     * @param  int  $userId  User primary key.
     * @param  int  $hours  Time window for redirect and visitor counts.
     * @return array{total_redirects_24h: int, unique_visitors: int, avg_response_time: float, success_rate: float, total_links_with_traffic: int, timeframe_hours: int}
     */
    public function getUserPerformanceMetrics(int $userId, int $hours = 24): array
    {
        $cacheKey = "metrics:user:{$userId}:performance:{$hours}h";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId, $hours) {
            $userLinks = Link::where('user_id', $userId)->get();
            $linkIds = $userLinks->pluck('id')->toArray();

            if (empty($linkIds)) {
                return $this->getEmptyPerformanceMetrics();
            }

            $timeframe = now()->subHours($hours);

            // Métricas de redirecionamento das últimas N horas
            $redirects = Click::whereIn('link_id', $linkIds)
                ->where('created_at', '>=', $timeframe)
                ->count();

            $uniqueVisitors = Click::whereIn('link_id', $linkIds)
                ->where('created_at', '>=', $timeframe)
                ->distinct('ip')
                ->count();

            // Tempo médio de resposta (do cache de métricas)
            $avgResponseTime = $this->calculateAverageResponseTime($userId, $hours);

            // Taxa de sucesso
            $successRate = $this->calculateSuccessRate($userId, $linkIds, $redirects);

            return [
                'total_redirects_24h' => $redirects,
                'unique_visitors' => $uniqueVisitors,
                'avg_response_time' => $avgResponseTime,
                'success_rate' => $successRate,
                'total_links_with_traffic' => $userLinks->where('clicks', '>', 0)->count(),
                'timeframe_hours' => $hours,
            ];
        });
    }

    /**
     * Returns aggregate metrics for a single link.
     *
     * Note: loads all clicks into memory via Click::where()->get() — may be
     * expensive for links with very large click volumes. Cached under
     * "metrics:link:{linkId}".
     *
     * @param  int  $linkId  Link primary key.
     * @return array{total_clicks: int, unique_visitors: int, avg_daily_clicks: float, conversion_rate: float, clicks_24h: int, unique_visitors_24h: int, days_since_creation: int, link_info?: array}
     */
    public function getLinkMetrics(int $linkId): array
    {
        $cacheKey = "metrics:link:{$linkId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($linkId) {
            $link = Link::find($linkId);

            if (! $link) {
                return $this->getEmptyLinkMetrics();
            }

            // Buscar todos os cliques do link
            $clicks = Click::where('link_id', $linkId)->get();

            $totalClicks = $link->clicks;
            $uniqueVisitors = $clicks->unique('ip')->count();

            // Média diária de cliques
            $daysSinceCreation = max(1, now()->diffInDays($link->created_at));
            $avgDailyClicks = round($totalClicks / $daysSinceCreation, 1);

            // Taxa de conversão
            $conversionRate = $uniqueVisitors > 0 ? round(($totalClicks / $uniqueVisitors) * 100, 1) : 0;

            // Cliques das últimas 24h
            $clicks24h = $clicks->where('created_at', '>=', now()->subDay())->count();

            // Visitantes únicos das últimas 24h
            $uniqueVisitors24h = $clicks->where('created_at', '>=', now()->subDay())
                ->unique('ip')->count();

            return [
                'total_clicks' => $totalClicks,
                'unique_visitors' => $uniqueVisitors,
                'avg_daily_clicks' => $avgDailyClicks,
                'conversion_rate' => $conversionRate,
                'clicks_24h' => $clicks24h,
                'unique_visitors_24h' => $uniqueVisitors24h,
                'days_since_creation' => $daysSinceCreation,
                'link_info' => [
                    'id' => $link->id,
                    'slug' => $link->slug,
                    'title' => $link->title,
                    'is_active' => $link->is_active,
                ],
            ];
        });
    }

    /**
     * Returns country and city reach counts for all links owned by a user.
     *
     * Cached under "metrics:user:{userId}:geographic".
     *
     * @param  int  $userId  User primary key.
     * @return array{countries_reached: int, cities_reached: int}
     */
    public function getUserGeographicMetrics(int $userId): array
    {
        $cacheKey = "metrics:user:{$userId}:geographic";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            $userLinks = Link::where('user_id', $userId)->get();
            $linkIds = $userLinks->pluck('id')->toArray();

            if (empty($linkIds)) {
                return ['countries_reached' => 0, 'cities_reached' => 0];
            }

            // Países únicos
            $countriesReached = Click::whereIn('link_id', $linkIds)
                ->whereNotNull('country')
                ->distinct('country')
                ->count();

            // Cidades únicas
            $citiesReached = Click::whereIn('link_id', $linkIds)
                ->whereNotNull('city')
                ->distinct('city')
                ->count();

            return [
                'countries_reached' => $countriesReached,
                'cities_reached' => $citiesReached,
            ];
        });
    }

    /**
     * Returns device type diversity count across all links owned by a user.
     *
     * Cached under "metrics:user:{userId}:audience".
     *
     * @param  int  $userId  User primary key.
     * @return array{device_types: int}
     */
    public function getUserAudienceMetrics(int $userId): array
    {
        $cacheKey = "metrics:user:{$userId}:audience";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            $userLinks = Link::where('user_id', $userId)->get();
            $linkIds = $userLinks->pluck('id')->toArray();

            if (empty($linkIds)) {
                return ['device_types' => 0];
            }

            // Tipos de dispositivos únicos (usando coluna 'device' que existe)
            $deviceTypes = Click::whereIn('link_id', $linkIds)
                ->whereNotNull('device')
                ->distinct('device')
                ->count();

            return [
                'device_types' => $deviceTypes,
            ];
        });
    }

    /**
     * Attempts to clear all cached metric entries for a user.
     *
     * KNOWN ISSUE: uses Cache::forget with wildcard patterns. This is a no-op
     * on file/database cache drivers, which do not support wildcard deletion.
     * Only Redis-based cache drivers honor glob patterns in this way.
     * Do not rely on this method for cache invalidation in non-Redis environments.
     *
     * @param  int  $userId  User primary key.
     */
    public function clearUserMetricsCache(int $userId): void
    {
        $patterns = [
            "metrics:user:{$userId}:*",
            'metrics:link:*', // Limpar também cache de links do usuário
        ];

        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }

    /**
     * Calcula tempo médio de resposta do cache de métricas
     */
    private function calculateAverageResponseTime(int $userId, int $hours): float
    {
        $totalResponseTime = 0;
        $totalRequests = 0;

        for ($i = $hours - 1; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('Y-m-d-H');
            $metrics = Cache::get("redirect_metrics:hour:{$hour}", []);

            if (! empty($metrics)) {
                $requests = $metrics['total_redirects'] ?? 0;
                $responseTime = $metrics['avg_response_time'] ?? 0;

                $totalRequests += $requests;
                $totalResponseTime += $responseTime * $requests;
            }
        }

        return $totalRequests > 0 ? round($totalResponseTime / $totalRequests, 2) : 0;
    }

    /**
     * Calcula taxa de sucesso considerando tentativas bloqueadas
     */
    private function calculateSuccessRate(int $userId, array $linkIds, int $totalClicks): float
    {
        // Buscar tentativas bloqueadas do cache
        $blockedAttempts = 0;
        $violationsToday = Cache::get('rate_limit:violations:'.now()->format('Y-m-d'), []);

        foreach ($violationsToday as $violation) {
            if (isset($violation['user_id']) && $violation['user_id'] == $userId) {
                $blockedAttempts += $violation['attempted'] ?? 1;
            }
        }

        return $totalClicks > 0 ? round((($totalClicks - $blockedAttempts) / $totalClicks) * 100, 1) : 100;
    }

    /**
     * Retorna métricas vazias para usuário sem dados
     */
    private function getEmptyMetrics(): array
    {
        return [
            'total_clicks' => 0,
            'total_links' => 0,
            'active_links' => 0,
            'recent_clicks' => 0,
            'unique_visitors' => 0,
            'links_with_traffic' => 0,
            'avg_clicks_per_link' => 0,
            'conversion_rate' => 0,
            'success_rate' => 100,
        ];
    }

    /**
     * Retorna métricas de performance vazias
     */
    private function getEmptyPerformanceMetrics(): array
    {
        return [
            'total_redirects_24h' => 0,
            'unique_visitors' => 0,
            'avg_response_time' => 0,
            'success_rate' => 100,
            'total_links_with_traffic' => 0,
        ];
    }

    /**
     * Retorna métricas de link vazias
     */
    private function getEmptyLinkMetrics(): array
    {
        return [
            'total_clicks' => 0,
            'unique_visitors' => 0,
            'avg_daily_clicks' => 0,
            'conversion_rate' => 0,
            'clicks_24h' => 0,
            'unique_visitors_24h' => 0,
            'days_since_creation' => 0,
        ];
    }

    /**
     * Returns the user's most-clicked active links.
     *
     * Cached under "metrics:user:{userId}:top_links:{limit}".
     *
     * @param  int  $userId  User primary key.
     * @param  int  $limit  Maximum number of links to return (default 5).
     * @return array<int, array{id: int, title: string, short_url: string, original_url: string, clicks: int, is_active: bool, created_at: string}>
     */
    public function getUserTopLinks(int $userId, int $limit = 5): array
    {
        $cacheKey = "metrics:user:{$userId}:top_links:{$limit}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId, $limit) {
            $topLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->orderByDesc('clicks')
                ->limit($limit)
                ->get(['id', 'title', 'slug', 'original_url', 'clicks', 'is_active', 'created_at'])
                ->map(function ($link) {
                    return [
                        'id' => $link->id,
                        'title' => $link->title ?: 'Link sem título',
                        'short_url' => config('app.redirect_url').'/'.($link->slug ?: "link-{$link->id}"),
                        'original_url' => $link->original_url,
                        'clicks' => $link->clicks ?? 0,
                        'is_active' => $link->is_active,
                        'created_at' => $link->created_at->toISOString(),
                    ];
                })
                ->toArray();

            return $topLinks;
        });
    }

    /**
     * Returns simplified temporal, geographic, and audience data for dashboard charts.
     *
     * Not cached — fetches fresh data each call. Uses PostgreSQL-specific
     * EXTRACT(HOUR/DOW FROM created_at) expressions (not SQLite-safe).
     * On exception, logs via AppLogger::analyticsError and returns empty arrays.
     *
     * @param  int  $userId  User primary key.
     * @param  int  $hours  Time window for the hourly distribution query.
     * @return array{temporal: array, geographic: array, audience: array}
     */
    public function getUserChartData(int $userId, int $hours = 24): array
    {
        try {
            $userLinks = Link::where('user_id', $userId)->get();

            if ($userLinks->isEmpty()) {
                return [
                    'temporal' => [
                        'clicks_by_hour' => [],
                        'clicks_by_day_of_week' => [],
                    ],
                    'geographic' => [
                        'top_countries' => [],
                        'top_cities' => [],
                    ],
                    'audience' => [
                        'device_breakdown' => [],
                    ],
                ];
            }

            $linkIds = $userLinks->pluck('id')->toArray();

            // Dados temporais básicos
            $temporalData = $this->getBasicTemporalData($linkIds, $hours);

            // Dados geográficos básicos
            $geographicData = $this->getBasicGeographicData($linkIds);

            // Dados de audiência básicos
            $audienceData = $this->getBasicAudienceData($linkIds);

            return [
                'temporal' => $temporalData,
                'geographic' => $geographicData,
                'audience' => $audienceData,
            ];

        } catch (\Exception $e) {
            \App\Logging\AppLogger::analyticsError($e, ['source' => 'metrics_service.charts']);

            return [
                'temporal' => [
                    'clicks_by_hour' => [],
                    'clicks_by_day_of_week' => [],
                ],
                'geographic' => [
                    'top_countries' => [],
                    'top_cities' => [],
                ],
                'audience' => [
                    'device_breakdown' => [],
                ],
            ];
        }
    }

    /**
     * Dados temporais básicos para gráficos
     */
    private function getBasicTemporalData(array $linkIds, int $hours): array
    {
        // Usar um período maior para garantir que temos dados (últimos 30 dias)
        $searchPeriod = max($hours, 24 * 30); // Mínimo 30 dias

        // Cliques por hora (distribuição geral dos dados disponíveis) - PostgreSQL
        $clicksByHour = DB::table('clicks')
            ->whereIn('link_id', $linkIds)
            ->where('created_at', '>=', now()->subHours($searchPeriod))
            ->selectRaw('EXTRACT(HOUR FROM created_at) as hour, COUNT(*) as clicks')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $hourlyData = [];
        for ($i = 0; $i < 24; $i++) {
            $hourlyData[] = [
                'hour' => $i,
                'clicks' => $clicksByHour->get($i)->clicks ?? 0,
                'label' => sprintf('%02d:00', $i),
            ];
        }

        // Cliques por dia da semana (últimos 30 dias) - PostgreSQL
        $clicksByDay = DB::table('clicks')
            ->whereIn('link_id', $linkIds)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('EXTRACT(DOW FROM created_at) as day, COUNT(*) as clicks')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $dayNames = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        $dailyData = [];
        for ($i = 0; $i < 7; $i++) {
            $dailyData[] = [
                'day' => $i,
                'day_name' => $dayNames[$i],
                'clicks' => $clicksByDay->get($i)->clicks ?? 0,
            ];
        }

        return [
            'clicks_by_hour' => $hourlyData,
            'clicks_by_day_of_week' => $dailyData,
        ];
    }

    /**
     * Dados geográficos básicos para gráficos
     */
    private function getBasicGeographicData(array $linkIds): array
    {
        // Top 10 países
        $topCountries = DB::table('clicks')
            ->whereIn('link_id', $linkIds)
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->selectRaw('country, COUNT(*) as clicks')
            ->groupBy('country')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'country' => $item->country,
                    'clicks' => $item->clicks,
                ];
            })
            ->toArray();

        // Top 10 cidades
        $topCities = DB::table('clicks')
            ->whereIn('link_id', $linkIds)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw('city, COUNT(*) as clicks')
            ->groupBy('city')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'city' => $item->city,
                    'clicks' => $item->clicks,
                ];
            })
            ->toArray();

        return [
            'top_countries' => $topCountries,
            'top_cities' => $topCities,
        ];
    }

    /**
     * Dados de audiência básicos para gráficos
     */
    private function getBasicAudienceData(array $linkIds): array
    {
        // Dispositivos
        $deviceData = DB::table('clicks')
            ->whereIn('link_id', $linkIds)
            ->whereNotNull('device')
            ->selectRaw('device, COUNT(*) as clicks')
            ->groupBy('device')
            ->orderByDesc('clicks')
            ->get()
            ->map(function ($item) {
                return [
                    'device' => ucfirst($item->device),
                    'clicks' => $item->clicks,
                ];
            })
            ->toArray();

        return [
            'device_breakdown' => $deviceData,
        ];
    }

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
