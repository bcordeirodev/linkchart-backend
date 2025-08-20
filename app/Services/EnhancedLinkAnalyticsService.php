<?php

namespace App\Services;

use App\Models\Click;
use App\Models\Link;

/**
 * Serviço de Analytics Avançado para Links - MVP
 */
class EnhancedLinkAnalyticsService
{
    /**
     * Obtém analytics completos de um link específico - versão otimizada
     */
    public function getComprehensiveLinkAnalytics(int $linkId): array
    {
        $link = Link::findOrFail($linkId);

        // Verificar se há cliques sem carregar todos os dados
        $hasClicks = Click::where('link_id', $linkId)->exists();

        if (!$hasClicks) {
            return [
                'has_data' => false,
                'link_info' => $this->getLinkInfo($link),
                'message' => 'Analytics will be available after the first clicks on your link.',
            ];
        }

        return [
            'has_data' => true,
            'link_info' => $this->getLinkInfo($link),
            'overview' => $this->getOverviewMetricsOptimized($linkId),
            'geographic' => $this->getGeographicAnalyticsOptimized($linkId),
            'temporal' => $this->getTemporalAnalyticsOptimized($linkId),
            'audience' => $this->getAudienceAnalyticsOptimized($linkId),
            'insights' => $this->generateBusinessInsightsOptimized($linkId),
        ];
    }

    /**
     * Métricas de visão geral otimizadas
     */
    private function getOverviewMetricsOptimized(int $linkId): array
    {
        $totalClicks = Click::where('link_id', $linkId)->count();
        $uniqueVisitors = Click::where('link_id', $linkId)->distinct('ip')->count();
        $countriesReached = Click::where('link_id', $linkId)
            ->whereNotNull('country')
            ->where('country', '!=', 'localhost')
            ->distinct('country')
            ->count();

        return [
            'total_clicks' => $totalClicks,
            'unique_visitors' => $uniqueVisitors,
            'countries_reached' => $countriesReached,
            'avg_daily_clicks' => $totalClicks > 0 ? round($totalClicks / 30, 1) : 0,
        ];
    }

    /**
     * Métricas geográficas
     */
    private function getGeographicAnalytics($clicks): array
    {
        return [
            'heatmap_data' => $this->getHeatmapData($clicks),
            'top_countries' => $this->getTopCountries($clicks),
            'top_states' => $this->getTopStates($clicks),
            'top_cities' => $this->getTopCities($clicks),
        ];
    }

    /**
     * Analytics geográficos otimizados
     */
    private function getGeographicAnalyticsOptimized(int $linkId): array
    {
        return [
            'heatmap_data' => $this->getHeatmapDataOptimized($linkId),
            'top_countries' => $this->getTopCountriesOptimized($linkId),
            'top_states' => $this->getTopStatesOptimized($linkId),
            'top_cities' => $this->getTopCitiesOptimized($linkId),
        ];
    }

    /**
     * Analytics temporais otimizados
     */
    private function getTemporalAnalyticsOptimized(int $linkId): array
    {
        return [
            'clicks_by_hour' => $this->getClicksByHourOptimized($linkId),
            'clicks_by_day_of_week' => $this->getClicksByDayOfWeekOptimized($linkId),
        ];
    }

    /**
     * Analytics de audiência otimizados
     */
    private function getAudienceAnalyticsOptimized(int $linkId): array
    {
        return [
            'device_breakdown' => $this->getDeviceBreakdownOptimized($linkId),
        ];
    }

    /**
     * Dados para mapa de calor - versão otimizada
     */
    private function getHeatmapDataOptimized(int $linkId): array
    {
        return \DB::table('clicks')
            ->selectRaw('
                latitude,
                longitude,
                city,
                country,
                COUNT(*) as clicks
            ')
            ->where('link_id', $linkId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->groupBy('latitude', 'longitude', 'city', 'country')
            ->get()
            ->map(function ($item) {
                return [
                    'lat' => (float) $item->latitude,
                    'lng' => (float) $item->longitude,
                    'city' => $item->city,
                    'country' => $item->country,
                    'clicks' => (int) $item->clicks,
                ];
            })
            ->toArray();
    }

    /**
     * Top países - versão otimizada
     */
    private function getTopCountriesOptimized(int $linkId): array
    {
        return \DB::table('clicks')
            ->selectRaw('
                country,
                iso_code,
                currency,
                COUNT(*) as clicks
            ')
            ->where('link_id', $linkId)
            ->whereNotNull('country')
            ->where('country', '!=', 'localhost')
            ->groupBy('country', 'iso_code', 'currency')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'country' => $item->country,
                    'iso_code' => $item->iso_code,
                    'clicks' => (int) $item->clicks,
                    'currency' => $item->currency,
                ];
            })
            ->toArray();
    }

    /**
     * Top estados - versão otimizada
     */
    private function getTopStatesOptimized(int $linkId): array
    {
        return \DB::table('clicks')
            ->selectRaw('
                country,
                state,
                state_name,
                COUNT(*) as clicks
            ')
            ->where('link_id', $linkId)
            ->whereNotNull('state')
            ->groupBy('country', 'state', 'state_name')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'country' => $item->country,
                    'state' => $item->state,
                    'state_name' => $item->state_name,
                    'clicks' => (int) $item->clicks,
                ];
            })
            ->toArray();
    }

    /**
     * Top cidades - versão otimizada
     */
    private function getTopCitiesOptimized(int $linkId): array
    {
        return \DB::table('clicks')
            ->selectRaw('
                city,
                state,
                country,
                COUNT(*) as clicks
            ')
            ->where('link_id', $linkId)
            ->whereNotNull('city')
            ->groupBy('city', 'state', 'country')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'city' => $item->city,
                    'state' => $item->state,
                    'country' => $item->country,
                    'clicks' => (int) $item->clicks,
                ];
            })
            ->toArray();
    }

    /**
     * Analytics temporais otimizados
     */
    private function getTemporalAnalytics($clicks): array
    {
        return [
            'clicks_by_hour' => $this->getClicksByHourOptimized($clicks->first()->link_id),
            'clicks_by_day_of_week' => $this->getClicksByDayOfWeekOptimized($clicks->first()->link_id),
        ];
    }

    /**
     * Cliques por hora - versão otimizada com SQL
     */
    private function getClicksByHourOptimized(int $linkId): array
    {
        $hourlyData = \DB::table('clicks')
            ->selectRaw('EXTRACT(HOUR FROM created_at) as hour, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        $result = [];
        for ($i = 0; $i < 24; $i++) {
            $result[] = [
                'hour' => $i,
                'clicks' => $hourlyData->get($i)?->clicks ?? 0,
                'label' => sprintf('%02d:00', $i),
            ];
        }

        return $result;
    }

    /**
     * Cliques por dia da semana - versão otimizada com SQL
     */
    private function getClicksByDayOfWeekOptimized(int $linkId): array
    {
        $daysData = \DB::table('clicks')
            ->selectRaw('EXTRACT(DOW FROM created_at) as day, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $dayNames = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

        $result = [];
        for ($i = 0; $i < 7; $i++) {
            $result[] = [
                'day' => $i,
                'day_name' => $dayNames[$i],
                'clicks' => $daysData->get($i)?->clicks ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Analytics de audiência
     */
    private function getAudienceAnalytics($clicks): array
    {
        return [
            'device_breakdown' => $this->getDeviceBreakdown($clicks),
        ];
    }

    /**
     * Breakdown de dispositivos - versão otimizada
     */
    private function getDeviceBreakdownOptimized(int $linkId): array
    {
        return \DB::table('clicks')
            ->selectRaw('
                device,
                COUNT(*) as clicks
            ')
            ->where('link_id', $linkId)
            ->whereNotNull('device')
            ->groupBy('device')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'device' => $item->device,
                    'clicks' => (int) $item->clicks,
                ];
            })
            ->toArray();
    }

    /**
     * Insights de negócio otimizados
     */
    private function generateBusinessInsightsOptimized(int $linkId): array
    {
        $insights = [];

        // Insight geográfico
        $topCountry = \DB::table('clicks')
            ->selectRaw('country, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('country')
            ->where('country', '!=', 'localhost')
            ->groupBy('country')
            ->orderBy('clicks', 'desc')
            ->first();

        if ($topCountry) {
            $totalClicks = Click::where('link_id', $linkId)->count();
            $percentage = round(($topCountry->clicks / $totalClicks) * 100, 1);

            $insights[] = [
                'type' => 'geographic',
                'title' => 'Mercado Principal',
                'description' => "O {$topCountry->country} representa {$percentage}% dos seus cliques. Considere criar conteúdo específico para este mercado.",
                'priority' => 'high'
            ];
        }

        // Insight de dispositivo
        $topDevice = \DB::table('clicks')
            ->selectRaw('device, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('device')
            ->groupBy('device')
            ->orderBy('clicks', 'desc')
            ->first();

        if ($topDevice) {
            $totalClicks = Click::where('link_id', $linkId)->count();
            $percentage = round(($topDevice->clicks / $totalClicks) * 100, 1);

            $insights[] = [
                'type' => 'audience',
                'title' => 'Dispositivo Principal',
                'description' => "A maioria dos cliques ({$percentage}%) vem de {$topDevice->device}. Otimize a experiência para este dispositivo.",
                'priority' => 'medium'
            ];
        }

        return $insights;
    }

    private function getLinkInfo(Link $link): array
    {
        return [
            'id' => $link->id,
            'title' => $link->title,
            'short_url' => $link->short_url,
            'original_url' => $link->original_url,
            'created_at' => $link->created_at,
            'is_active' => $link->is_active,
            'expires_at' => $link->expires_at,
        ];
    }
}
