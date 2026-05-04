<?php

namespace App\Http\Controllers\Analytics;

use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\Http\Controllers\BaseController;
use App\Models\Link;
use App\Services\Analytics\LinkAnalyticsOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para Analytics Avançados
 * Focado em valor de negócio e insights acionáveis
 */
class AnalyticsController extends BaseController
{
    public function __construct(
        private LinkAnalyticsOrchestrator $analyticsService,
        private TemporalAnalyticsInterface $temporalService
    ) {}

    /**
     * Analytics completos de um link específico
     */
    public function getLinkAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar analytics do link.', $e);
        }
    }

    /**
     * Dados específicos para mapa de calor com informações enriquecidas
     */
    public function getHeatmapData(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $heatmapData = $this->analyticsService->getHeatmapData($linkId);

            // Adicionar metadados úteis
            $totalClicks = array_sum(array_column($heatmapData, 'clicks'));
            $uniqueCountries = count(array_unique(array_column($heatmapData, 'country')));
            $uniqueCities = count(array_unique(array_column($heatmapData, 'city')));
            $maxClicks = $heatmapData ? max(array_column($heatmapData, 'clicks')) : 0;

            return response()->json([
                'success' => true,
                'data' => $heatmapData,
                'metadata' => [
                    'total_clicks' => $totalClicks,
                    'unique_countries' => $uniqueCountries,
                    'unique_cities' => $uniqueCities,
                    'max_clicks' => $maxClicks,
                    'total_locations' => count($heatmapData),
                    'last_updated' => now()->toISOString(),
                    'link_info' => [
                        'id' => $link->id,
                        'title' => $link->title,
                        'short_url' => $link->getShortedUrl(),
                        'is_active' => $link->is_active,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar dados do mapa de calor.', $e);
        }
    }

    /**
     * Dados do heatmap em tempo real (sem autenticação para polling rápido)
     */
    public function getHeatmapDataRealtime(int $linkId): JsonResponse
    {
        try {
            // Verificar se o link existe e está ativo (sem verificar usuário para performance)
            $link = Link::where('id', $linkId)
                ->where('is_active', true)
                ->first();

            if (! $link) {
                return response()->json(['error' => 'Link não encontrado ou inativo.'], 404);
            }

            $heatmapData = $this->analyticsService->getHeatmapData($linkId);

            // Retornar apenas os dados essenciais para performance
            return response()->json([
                'success' => true,
                'data' => $heatmapData,
                'timestamp' => now()->toISOString(),
                'total_locations' => count($heatmapData),
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar dados em tempo real.', $e);
        }
    }

    /**
     * Analytics geográficos detalhados
     */
    public function getGeographicAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $analytics = $this->analyticsService->getLinkGeographicAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar analytics geográficos.', $e);
        }
    }

    /**
     * Insights de negócio automatizados
     *
     * Retorna o payload completo de insights (insights + summary + analytics_data
     * com retention/session_depth/traffic_sources) consumido por
     * useInsightsData no frontend.
     */
    public function getBusinessInsights(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $insights = $this->analyticsService->getLinkInsightsAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $insights,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar insights de negócio.', $e);
        }
    }

    /**
     * Analytics temporais (horários, dias da semana, etc.)
     * ✨ UNIFICADO: Agora inclui dados advanced (trends, peaks, timezones)
     */
    public function getTemporalAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            // 1. Buscar dados base (clicks_by_hour, clicks_by_day_of_week, etc.)
            $baseData = $this->analyticsService->getLinkTemporalAnalytics($linkId);

            // 2. Buscar dados avançados (weekly_trends, monthly_trends, peak_analysis, timezone_analysis)
            $advancedData = $this->temporalService->getAdvancedTemporalAnalytics($linkId);

            // 3. Enriquecer timezone analysis com percentuais
            $enrichedTimezones = $this->enrichTimezoneAnalysis($advancedData['timezone_analysis'] ?? []);

            // 4. Merge estruturado - compatível com tipos existentes
            $unifiedData = array_merge($baseData, [
                'advanced' => [
                    'weekly_trends' => $advancedData['weekly_trends'] ?? [],
                    'monthly_trends' => $advancedData['monthly_trends'] ?? [],
                    'peak_analysis' => $advancedData['peak_analysis'] ?? [],
                    'timezone_analysis' => $enrichedTimezones,
                ],
            ]);

            return response()->json([
                'success' => true,
                'data' => $unifiedData,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar analytics temporais.', $e);
        }
    }

    /**
     * Analytics de audiência (dispositivos, engajamento, etc.)
     */
    public function getAudienceAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $analytics = $this->analyticsService->getLinkAudienceAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar analytics de audiência.', $e);
        }
    }

    /**
     * Relatório executivo resumido
     */
    public function getExecutiveSummary(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            if (! $analytics['has_data']) {
                return response()->json([
                    'success' => true,
                    'data' => ['link_info' => $analytics['link_info'], 'has_data' => false],
                ]);
            }

            // Criar resumo executivo
            $summary = [
                'link_info' => $analytics['link_info'],
                'key_metrics' => $analytics['overview'],
                'top_insights' => array_slice($analytics['insights'] ?? [], 0, 3),
                'geographic_summary' => [
                    'top_country' => $analytics['geographic']['top_countries'][0] ?? null,
                    'countries_count' => count($analytics['geographic']['top_countries'] ?? []),
                ],
                'audience_summary' => [
                    'top_device' => $analytics['audience']['device_breakdown'][0] ?? null,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar resumo executivo.', $e);
        }
    }

    /**
     * Dashboard de link individual - dados para a tab Dashboard
     * Combina métricas básicas com dados de gráficos para um link específico
     */
    public function getLinkDashboardData(Request $request, int $linkId): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (! $userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            $link = $this->findOwnedLink($linkId);
            if (! $link) return $this->linkNotFound();

            $validHours = [1, 24, 168, 720];
            $hours = in_array((int) $request->query('hours'), $validHours, true)
                ? (int) $request->query('hours')
                : 0;

            $analytics = $this->analyticsService->getLinkDashboardAnalytics($linkId, $hours);

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar dados do dashboard do link.', $e);
        }
    }

    /**
     * Função auxiliar para enriquecer timezone analysis com percentuais
     *
     * @param  array  $timezones  Array de timezones com clicks
     * @return array Array enriquecido com percentuais
     */
    private function enrichTimezoneAnalysis(array $timezones): array
    {
        if (empty($timezones)) {
            return [];
        }

        // Calcular total de cliques
        $total = array_sum(array_column($timezones, 'clicks'));

        // Enriquecer cada timezone com percentual
        return array_map(function ($tz) use ($total) {
            return [
                'name' => $tz['name'] ?? 'Unknown',
                'clicks' => $tz['clicks'] ?? 0,
                'percentage' => $total > 0 ? round(($tz['clicks'] / $total) * 100, 2) : 0,
            ];
        }, $timezones);
    }
}
