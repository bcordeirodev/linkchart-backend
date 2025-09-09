<?php

namespace App\Http\Controllers\Analytics;

use App\Services\Analytics\MetricsService;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller unificado para métricas
 * Elimina duplicação entre controladores de analytics
 * Fornece endpoints consolidados para o frontend
 */
class MetricsController extends Controller
{
    public function __construct(
        private MetricsService $metricsService
    ) {}

    /**
     * Endpoint unificado para métricas do dashboard
     * Substitui múltiplos endpoints com dados similares
     */
    public function getDashboardMetrics(Request $request): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado'], 401);
            }

            $hours = $request->get('hours', 24); // Padrão 24h
            $includeCharts = $request->get('include_charts', false); // Incluir dados para gráficos

            // Buscar todas as métricas necessárias em uma única requisição
            $basicMetrics = $this->metricsService->getUserBasicMetrics($userId, $hours);
            $performanceMetrics = $this->metricsService->getUserPerformanceMetrics($userId, $hours);
            $geographicMetrics = $this->metricsService->getUserGeographicMetrics($userId);
            $audienceMetrics = $this->metricsService->getUserAudienceMetrics($userId);

            // Buscar dados para gráficos se solicitado
            $chartData = [];
            if ($includeCharts) {
                $chartData = $this->metricsService->getUserChartData($userId, $hours);
            }

            // Buscar top links
            $topLinks = $this->metricsService->getUserTopLinks($userId, 5);

            return response()->json([
                'success' => true,
                'timeframe' => "{$hours}h",
                'metrics' => [
                    // Métricas para Dashboard
                    'dashboard' => [
                        'total_links' => $basicMetrics['total_links'],
                        'active_links' => $basicMetrics['active_links'],
                        'total_clicks' => $basicMetrics['total_clicks'],
                        'avg_clicks_per_link' => $basicMetrics['avg_clicks_per_link']
                    ],

                    // Métricas para Analytics
                    'analytics' => [
                        'total_clicks' => $basicMetrics['total_clicks'],
                        'unique_visitors' => $basicMetrics['unique_visitors'],
                        'conversion_rate' => $basicMetrics['conversion_rate'],
                        'avg_daily_clicks' => round($basicMetrics['recent_clicks'] / max(1, $hours / 24), 1)
                    ],

                    // Métricas de Performance
                    'performance' => [
                        'total_redirects_24h' => $performanceMetrics['total_redirects_24h'],
                        'unique_visitors' => $performanceMetrics['unique_visitors'],
                        'avg_response_time' => $performanceMetrics['avg_response_time'],
                        'success_rate' => $performanceMetrics['success_rate']
                    ],

                    // Métricas Geográficas
                    'geographic' => [
                        'countries_reached' => $geographicMetrics['countries_reached'],
                        'cities_reached' => $geographicMetrics['cities_reached']
                    ],

                    // Métricas de Audiência
                    'audience' => [
                        'device_types' => $audienceMetrics['device_types']
                    ]
                ],

                // Summary geral (compatibilidade com frontend atual)
                'summary' => [
                    'total_clicks' => $basicMetrics['total_clicks'],
                    'total_links' => $basicMetrics['total_links'],
                    'active_links' => $basicMetrics['active_links'],
                    'unique_visitors' => $basicMetrics['unique_visitors'],
                    'success_rate' => $performanceMetrics['success_rate'],
                    'avg_response_time' => $performanceMetrics['avg_response_time'],
                    'countries_reached' => $geographicMetrics['countries_reached'],
                    'links_with_traffic' => $basicMetrics['links_with_traffic']
                ],

                // Top links do usuário
                'top_links' => $topLinks,

                // Dados para gráficos (se solicitado)
                'charts' => $chartData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas unificadas',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas específicas por categoria
     * Endpoint mais granular para necessidades específicas
     */
    public function getMetricsByCategory(Request $request, string $category): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado'], 401);
            }

            $hours = $request->get('hours', 24);

            switch ($category) {
                case 'dashboard':
                    $metrics = $this->metricsService->getUserBasicMetrics($userId, $hours);
                    $response = [
                        'total_links' => $metrics['total_links'],
                        'active_links' => $metrics['active_links'],
                        'total_clicks' => $metrics['total_clicks'],
                        'avg_clicks_per_link' => $metrics['avg_clicks_per_link']
                    ];
                    break;

                case 'analytics':
                    $metrics = $this->metricsService->getUserBasicMetrics($userId, $hours);
                    $response = [
                        'total_clicks' => $metrics['total_clicks'],
                        'unique_visitors' => $metrics['unique_visitors'],
                        'conversion_rate' => $metrics['conversion_rate'],
                        'avg_daily_clicks' => round($metrics['recent_clicks'] / max(1, $hours / 24), 1)
                    ];
                    break;

                case 'performance':
                    $response = $this->metricsService->getUserPerformanceMetrics($userId, $hours);
                    break;

                case 'geographic':
                    $response = $this->metricsService->getUserGeographicMetrics($userId);
                    break;

                case 'audience':
                    $response = $this->metricsService->getUserAudienceMetrics($userId);
                    break;

                default:
                    return response()->json(['error' => 'Categoria de métrica inválida'], 400);
            }

            return response()->json([
                'success' => true,
                'category' => $category,
                'timeframe' => "{$hours}h",
                'metrics' => $response
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => "Erro ao carregar métricas da categoria {$category}",
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas para um link específico
     * Substitui endpoint duplicado em LinkController
     */
    public function getLinkMetrics(Request $request, int $linkId): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            // Verificar se o link pertence ao usuário
            $link = \App\Models\Link::where('id', $linkId)
                ->where('user_id', $userId)
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado'], 404);
            }

            $metrics = $this->metricsService->getLinkMetrics($linkId);

            return response()->json([
                'success' => true,
                'link_id' => $linkId,
                'metrics' => $metrics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao carregar métricas do link',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Endpoint para comparar métricas entre períodos
     * Funcionalidade adicional que aproveita o cache unificado
     */
    public function compareMetrics(Request $request): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado'], 401);
            }

            $currentPeriod = $request->get('current', 24);
            $previousPeriod = $request->get('previous', 48);

            $currentMetrics = $this->metricsService->getUserBasicMetrics($userId, $currentPeriod);
            $previousMetrics = $this->metricsService->getUserBasicMetrics($userId, $previousPeriod);

            // Calcular variações percentuais
            $comparison = [];
            $keys = ['total_clicks', 'unique_visitors', 'avg_clicks_per_link', 'conversion_rate'];

            foreach ($keys as $key) {
                $current = $currentMetrics[$key] ?? 0;
                $previous = $previousMetrics[$key] ?? 0;

                $change = $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : 0;

                $comparison[$key] = [
                    'current' => $current,
                    'previous' => $previous,
                    'change_percent' => $change,
                    'trend' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable')
                ];
            }

            return response()->json([
                'success' => true,
                'current_period' => "{$currentPeriod}h",
                'previous_period' => "{$previousPeriod}h",
                'comparison' => $comparison
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao comparar métricas',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Limpar cache de métricas
     * Útil para desenvolvimento e testes
     */
    public function clearCache(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado'], 401);
            }

            $this->metricsService->clearUserMetricsCache($userId);

            return response()->json([
                'success' => true,
                'message' => 'Cache de métricas limpo com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao limpar cache',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
