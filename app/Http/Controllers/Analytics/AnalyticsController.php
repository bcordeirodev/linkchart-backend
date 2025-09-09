<?php

namespace App\Http\Controllers\Analytics;

use App\Services\Analytics\LinkAnalyticsService;
use App\Services\Analytics\UserAgentAnalyticsService;
use Illuminate\Routing\Controller;
use App\Models\Link;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para Analytics Avançados
 * Focado em valor de negócio e insights acionáveis
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private LinkAnalyticsService $analyticsService,
        private UserAgentAnalyticsService $userAgentAnalyticsService
    ) {}

    /**
     * Analytics completos de um link específico
     */
    public function getLinkAnalytics(int $linkId): JsonResponse
    {
        try {
            // Verificar permissão do usuário
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json([
                    'error' => 'Link não encontrado ou você não tem permissão para acessá-lo.'
                ], 404);
            }

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics do link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados específicos para mapa de calor com informações enriquecidas
     */
    public function getHeatmapData(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);
            $heatmapData = $analytics['geographic']['heatmap_data'] ?? [];

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
                        'short_url' => $link->short_url,
                        'is_active' => $link->is_active
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados do mapa de calor.',
                'message' => $e->getMessage()
            ], 500);
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

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado ou inativo.'], 404);
            }

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);
            $heatmapData = $analytics['geographic']['heatmap_data'] ?? [];

            // Retornar apenas os dados essenciais para performance
            return response()->json([
                'success' => true,
                'data' => $heatmapData,
                'timestamp' => now()->toISOString(),
                'total_locations' => count($heatmapData)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados em tempo real.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados de heatmap geral - todos os links ativos do usuário
     */
    public function getGlobalHeatmapData(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar todos os links ativos do usuário
            $activeLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'metadata' => [
                        'total_clicks' => 0,
                        'unique_countries' => 0,
                        'unique_cities' => 0,
                        'max_clicks' => 0,
                        'total_locations' => 0,
                        'total_links' => 0,
                        'last_updated' => now()->toISOString()
                    ]
                ]);
            }

            // Buscar dados de heatmap agregados de todos os links
            $heatmapData = $this->analyticsService->getGlobalHeatmapData($activeLinks);

            // Calcular metadados
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
                    'total_links' => count($activeLinks),
                    'last_updated' => now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados globais do heatmap.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados de heatmap geral em tempo real (sem autenticação para polling rápido)
     */
    public function getGlobalHeatmapDataRealtime(): JsonResponse
    {
        try {
            // Buscar todos os links ativos (sem filtro de usuário para performance)
            $activeLinks = Link::where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'timestamp' => now()->toISOString(),
                    'total_locations' => 0
                ]);
            }

            // Buscar dados agregados
            $heatmapData = $this->analyticsService->getGlobalHeatmapData($activeLinks);

            return response()->json([
                'success' => true,
                'data' => $heatmapData,
                'timestamp' => now()->toISOString(),
                'total_locations' => count($heatmapData)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados globais em tempo real.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Analytics geográficos detalhados
     */
    public function getGeographicAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->analyticsService->getLinkGeographicAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics geográficos.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Insights de negócio automatizados
     */
    public function getBusinessInsights(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics['insights'] ?? []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar insights de negócio.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Analytics temporais (horários, dias da semana, etc.)
     */
    public function getTemporalAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->analyticsService->getLinkTemporalAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics temporais.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Analytics de audiência (dispositivos, engajamento, etc.)
     */
    public function getAudienceAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->analyticsService->getLinkAudienceAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics de audiência.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Relatório executivo resumido
     */
    public function getExecutiveSummary(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            // Criar resumo executivo
            $summary = [
                'link_info' => $analytics['link_info'],
                'key_metrics' => $analytics['overview'],
                'top_insights' => array_slice($analytics['insights'] ?? [], 0, 3),
                'geographic_summary' => [
                    'top_country' => $analytics['geographic']['top_countries'][0] ?? null,
                    'countries_count' => count($analytics['geographic']['top_countries'] ?? []),
                    'continents_count' => count($analytics['geographic']['continents'] ?? []),
                ],
                'audience_summary' => [
                    'top_device' => $analytics['audience']['device_breakdown'][0] ?? null,
                    'returning_visitors' => $analytics['audience']['returning_visitors'] ?? 0,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar resumo executivo.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Análise detalhada de browsers e sistemas operacionais
     */
    public function getBrowserAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->advancedAnalyticsService->getBrowserAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics de browser.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Análise detalhada de referrers com categorização
     */
    public function getRefererAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->advancedAnalyticsService->getRefererAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics de referrers.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Análise temporal avançada
     */
    public function getAdvancedTemporalAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->userAgentAnalyticsService->getAdvancedTemporalAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics temporais avançados.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Análise de engajamento e conversão
     */
    public function getEngagementAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->advancedAnalyticsService->getEngagementAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics de engajamento.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Performance por região
     */
    public function getPerformanceByRegion(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->advancedAnalyticsService->getPerformanceByRegion($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar performance por região.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Relatório de qualidade de tráfego
     */
    public function getTrafficQualityReport(int $linkId): JsonResponse
    {
        try {
            $link = Link::where('id', $linkId)
                ->where('user_id', auth()->guard('api')->id())
                ->first();

            if (!$link) {
                return response()->json(['error' => 'Link não encontrado.'], 404);
            }

            $analytics = $this->advancedAnalyticsService->getTrafficQualityReport($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar relatório de qualidade.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados geográficos globais - todos os links ativos do usuário
     */
    public function getGlobalGeographicData(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar todos os links ativos do usuário
            $activeLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'top_countries' => [],
                        'top_states' => [],
                        'top_cities' => [],
                        'heatmap_data' => []
                    ]
                ]);
            }

            // Usar o service para buscar dados agregados
            $analytics = $this->analyticsService->getGlobalGeographicAnalytics($activeLinks);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados geográficos globais.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados temporais globais - todos os links ativos do usuário
     */
    public function getGlobalTemporalData(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar todos os links ativos do usuário
            $activeLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'clicks_by_hour' => [],
                        'clicks_by_day_of_week' => []
                    ]
                ]);
            }

            // Usar o service para buscar dados agregados
            $analytics = $this->analyticsService->getGlobalTemporalAnalytics($activeLinks);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados temporais globais.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dados de audiência globais - todos os links ativos do usuário
     */
    public function getGlobalAudienceData(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar todos os links ativos do usuário
            $activeLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'device_breakdown' => [],
                        'browser_breakdown' => [],
                        'os_breakdown' => []
                    ]
                ]);
            }

            // Usar o service para buscar dados agregados
            $analytics = $this->analyticsService->getGlobalAudienceAnalytics($activeLinks);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados de audiência globais.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Insights globais - todos os links ativos do usuário
     */
    public function getGlobalInsightsData(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar todos os links ativos do usuário
            $activeLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'insights' => [],
                        'summary' => [
                            'total_insights' => 0,
                            'high_priority' => 0,
                            'actionable_insights' => 0,
                            'avg_confidence' => 0
                        ]
                    ]
                ]);
            }

            // Usar o service para buscar insights agregados
            $analytics = $this->analyticsService->getGlobalInsightsAnalytics($activeLinks);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar insights globais.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Performance global - todos os links ativos do usuário
     */
    /**
     * Dashboard global consolidado - dados para a tab Dashboard
     * Combina métricas básicas com dados de gráficos
     */
    public function getGlobalDashboardData(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar todos os links ativos do usuário
            $activeLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'summary' => [
                            'total_clicks' => 0,
                            'total_links' => 0,
                            'active_links' => 0,
                            'unique_visitors' => 0,
                            'success_rate' => 100,
                            'avg_response_time' => 0,
                            'countries_reached' => 0,
                            'links_with_traffic' => 0
                        ],
                        'top_links' => [],
                        'temporal_data' => [
                            'clicks_by_hour' => [],
                            'clicks_by_day_of_week' => []
                        ],
                        'geographic_data' => [
                            'top_countries' => [],
                            'top_cities' => []
                        ],
                        'audience_data' => [
                            'device_breakdown' => []
                        ]
                    ]
                ]);
            }

            // Usar o service para buscar analytics consolidados
            $analytics = $this->analyticsService->getGlobalDashboardAnalytics($activeLinks);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados do dashboard global.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dashboard de link individual - dados para a tab Dashboard
     * Combina métricas básicas com dados de gráficos para um link específico
     */
    public function getLinkDashboardData(int $linkId): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Verificar permissão do usuário
            $link = Link::where('id', $linkId)
                ->where('user_id', $userId)
                ->first();

            if (!$link) {
                return response()->json([
                    'error' => 'Link não encontrado ou você não tem permissão para acessá-lo.'
                ], 404);
            }

            // Usar o service para buscar analytics consolidados do link específico
            $analytics = $this->analyticsService->getLinkDashboardAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados do dashboard do link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getGlobalPerformanceData(): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();

            if (!$userId) {
                return response()->json(['error' => 'Usuário não autenticado.'], 401);
            }

            // Buscar todos os links ativos do usuário
            $activeLinks = Link::where('user_id', $userId)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();

            if (empty($activeLinks)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_redirects_24h' => 0,
                        'unique_visitors' => 0,
                        'avg_response_time' => 0,
                        'success_rate' => 100
                    ]
                ]);
            }

            // Usar o service para buscar performance agregada
            $analytics = $this->analyticsService->getGlobalPerformanceAnalytics($activeLinks);

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados de performance globais.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
