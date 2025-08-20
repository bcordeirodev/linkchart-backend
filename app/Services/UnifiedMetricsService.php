<?php

namespace App\Services;

use App\Models\Link;
use App\Models\Click;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

/**
 * Serviço unificado para cálculo de métricas
 * Elimina duplicação de código entre controladores
 * Centraliza todas as regras de negócio de métricas
 */
class UnifiedMetricsService
{
    /**
     * Cache key patterns para métricas
     */
    private const CACHE_TTL = 300; // 5 minutos
    private const CACHE_KEYS = [
        'user_metrics' => 'metrics:user:{userId}',
        'link_metrics' => 'metrics:link:{linkId}',
        'daily_metrics' => 'metrics:daily:{date}',
        'hourly_metrics' => 'metrics:hourly:{hour}'
    ];

    /**
     * Calcula métricas básicas para um usuário
     */
    public function getUserBasicMetrics(int $userId, int $hours = 24): array
    {
        $cacheKey = "metrics:user:{$userId}:basic:{$hours}h";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($userId, $hours) {
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
                'timeframe_hours' => $hours
            ];
        });
    }

    /**
     * Calcula métricas de performance para um usuário
     */
    public function getUserPerformanceMetrics(int $userId, int $hours = 24): array
    {
        $cacheKey = "metrics:user:{$userId}:performance:{$hours}h";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($userId, $hours) {
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
                'timeframe_hours' => $hours
            ];
        });
    }

    /**
     * Calcula métricas para um link específico
     */
    public function getLinkMetrics(int $linkId): array
    {
        $cacheKey = "metrics:link:{$linkId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($linkId) {
            $link = Link::find($linkId);

            if (!$link) {
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
                    'is_active' => $link->is_active
                ]
            ];
        });
    }

    /**
     * Calcula métricas geográficas para um usuário
     */
    public function getUserGeographicMetrics(int $userId): array
    {
        $cacheKey = "metrics:user:{$userId}:geographic";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($userId) {
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
                'cities_reached' => $citiesReached
            ];
        });
    }

        /**
     * Calcula métricas de audiência para um usuário
     */
    public function getUserAudienceMetrics(int $userId): array
    {
        $cacheKey = "metrics:user:{$userId}:audience";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($userId) {
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
                'device_types' => $deviceTypes
            ];
        });
    }

    /**
     * Limpa cache de métricas para um usuário
     */
    public function clearUserMetricsCache(int $userId): void
    {
        $patterns = [
            "metrics:user:{$userId}:*",
            "metrics:link:*", // Limpar também cache de links do usuário
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

            if (!empty($metrics)) {
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
        $violationsToday = Cache::get('rate_limit:violations:' . now()->format('Y-m-d'), []);

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
            'success_rate' => 100
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
            'total_links_with_traffic' => 0
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
            'days_since_creation' => 0
        ];
    }
}
