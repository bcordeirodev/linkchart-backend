<?php

namespace App\Http\Controllers;

use App\Services\EnhancedLinkAnalyticsService;
use App\Services\AdvancedAnalyticsService;
use App\Models\Link;
use Illuminate\Http\JsonResponse;

/**
 * Controller para relatórios completos de analytics
 * Combina todos os serviços em relatórios unificados
 */
class AnalyticsReportController
{
    public function __construct(
        private EnhancedLinkAnalyticsService $enhancedService,
        private AdvancedAnalyticsService $advancedService
    ) {}

    /**
     * Relatório executivo completo
     */
    public function getExecutiveReport(int $linkId): JsonResponse
    {
        try {
            // Verificar permissão
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            // Coletar todos os dados
            $comprehensive = $this->enhancedService->getComprehensiveLinkAnalytics($linkId);
            $browser = $this->advancedService->getBrowserAnalytics($linkId);
            $referer = $this->advancedService->getRefererAnalytics($linkId);
            $engagement = $this->advancedService->getEngagementAnalytics($linkId);
            $quality = $this->advancedService->getTrafficQualityReport($linkId);
            $temporal = $this->advancedService->getAdvancedTemporalAnalytics($linkId);
            $performance = $this->advancedService->getPerformanceByRegion($linkId);

            // Montar relatório executivo
            $report = [
                'link_info' => $comprehensive['link_info'],
                'executive_summary' => [
                    'total_clicks' => $comprehensive['overview']['total_clicks'],
                    'unique_visitors' => $comprehensive['overview']['unique_visitors'],
                    'countries_reached' => $comprehensive['overview']['countries_reached'],
                    'quality_score' => $quality['quality_score'],
                    'engagement_rate' => $engagement['click_through_rate'],
                    'return_rate' => $engagement['return_rate'],
                ],
                'geographic_performance' => [
                    'top_markets' => array_slice($comprehensive['geographic']['top_countries'], 0, 5),
                    'heatmap_points' => count($comprehensive['geographic']['heatmap_data']),
                    'regional_performance' => array_slice($performance, 0, 5),
                ],
                'audience_insights' => [
                    'device_breakdown' => $comprehensive['audience']['device_breakdown'],
                    'top_browsers' => array_slice($browser['browsers'], 0, 5),
                    'top_os' => array_slice($browser['operating_systems'], 0, 5),
                    'traffic_sources' => [
                        'direct' => $referer['direct_traffic'],
                        'social_media' => array_sum(array_column($referer['social_media'], 'clicks')),
                        'search_engines' => array_sum(array_column($referer['search_engines'], 'clicks')),
                        'other' => array_sum(array_column($referer['other_referrers'], 'clicks')),
                    ],
                ],
                'temporal_patterns' => [
                    'peak_hour' => $temporal['peak_analysis']['peak_hour'],
                    'peak_day' => $temporal['peak_analysis']['peak_day'],
                    'timezone_distribution' => array_slice($temporal['timezone_analysis'], 0, 5),
                ],
                'quality_metrics' => [
                    'human_traffic_percentage' => round(($quality['human_clicks'] / $quality['total_clicks']) * 100, 1),
                    'bot_traffic_percentage' => round(($quality['bot_clicks'] / $quality['total_clicks']) * 100, 1),
                    'suspicious_activity' => $quality['suspicious_ips'] + $quality['rapid_clicks_detected'],
                    'recommendations' => $quality['recommendations'],
                ],
                'business_insights' => $comprehensive['insights'],
                'generated_at' => now()->toISOString(),
            ];

            return response()->json([
                'success' => true,
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao gerar relatório executivo.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados para dashboard em tempo real
     */
    public function getDashboardData(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $comprehensive = $this->enhancedService->getComprehensiveLinkAnalytics($linkId);
            $quality = $this->advancedService->getTrafficQualityReport($linkId);

            return response()->json([
                'success' => true,
                'data' => [
                    'kpis' => [
                        'total_clicks' => $comprehensive['overview']['total_clicks'],
                        'unique_visitors' => $comprehensive['overview']['unique_visitors'],
                        'countries_reached' => $comprehensive['overview']['countries_reached'],
                        'quality_score' => $quality['quality_score'],
                    ],
                    'quick_charts' => [
                        'top_countries' => array_slice($comprehensive['geographic']['top_countries'], 0, 5),
                        'device_breakdown' => $comprehensive['audience']['device_breakdown'],
                        'hourly_pattern' => $comprehensive['temporal']['clicks_by_hour'],
                    ],
                    'alerts' => $this->generateAlerts($quality, $comprehensive),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados do dashboard.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gerar alertas baseados nos dados
     */
    private function generateAlerts(array $quality, array $comprehensive): array
    {
        $alerts = [];

        // Alerta de qualidade baixa
        if ($quality['quality_score'] < 70) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Qualidade do Tráfego',
                'message' => "Score de qualidade baixo: {$quality['quality_score']}%",
                'priority' => 'high'
            ];
        }

        // Alerta de tráfego de bots alto
        $botPercentage = ($quality['bot_clicks'] / $quality['total_clicks']) * 100;
        if ($botPercentage > 10) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Tráfego de Bots Alto',
                'message' => sprintf('%.1f%% do tráfego são bots', $botPercentage),
                'priority' => 'medium'
            ];
        }

        // Alerta de concentração geográfica
        if ($comprehensive['overview']['countries_reached'] < 3) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Oportunidade de Expansão',
                'message' => 'Tráfego concentrado em poucos países. Considere campanhas internacionais.',
                'priority' => 'low'
            ];
        }

        // Alerta de alta performance
        if ($comprehensive['overview']['total_clicks'] > 1000 && $quality['quality_score'] > 90) {
            $alerts[] = [
                'type' => 'success',
                'title' => 'Alta Performance',
                'message' => 'Link com excelente engajamento e qualidade de tráfego!',
                'priority' => 'info'
            ];
        }

        return $alerts;
    }
}
