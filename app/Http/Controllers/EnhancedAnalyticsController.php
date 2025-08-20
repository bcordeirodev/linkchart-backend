<?php

namespace App\Http\Controllers;

use App\Services\EnhancedLinkAnalyticsService;
use App\Services\AdvancedAnalyticsService;
use App\Models\Link;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para Analytics Avançados
 * Focado em valor de negócio e insights acionáveis
 */
class EnhancedAnalyticsController
{
    public function __construct(
        private EnhancedLinkAnalyticsService $analyticsService,
        private AdvancedAnalyticsService $advancedAnalyticsService
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
     * Dados específicos para mapa de calor
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

            return response()->json([
                'success' => true,
                'data' => $analytics['geographic']['heatmap_data'] ?? []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados do mapa de calor.',
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

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics['geographic'] ?? []
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

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics['temporal'] ?? []
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

            $analytics = $this->analyticsService->getComprehensiveLinkAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $analytics['audience'] ?? []
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

            $analytics = $this->advancedAnalyticsService->getAdvancedTemporalAnalytics($linkId);

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
}
