<?php

namespace App\Http\Controllers\Analytics;

use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\Http\Controllers\BaseController;
use App\Services\Analytics\LinkAnalyticsOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            if (! $link) {
                return $this->linkNotFound();
            }

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
     * Analytics geográficos detalhados — payload unificado (data + meta)
     */
    public function getGeographicAnalytics(int $linkId): JsonResponse
    {
        try {
            $link = $this->findOwnedLink($linkId);
            if (! $link) {
                return $this->linkNotFound();
            }

            $payload = $this->analyticsService->getLinkGeographicAnalytics($linkId);

            return response()->json([
                'success' => true,
                'data' => $payload['data'],
                'meta' => $payload['meta'],
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
            if (! $link) {
                return $this->linkNotFound();
            }

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
            if (! $link) {
                return $this->linkNotFound();
            }

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
                    'heatmap_data' => $advancedData['heatmap_data'] ?? [],
                    'daily_timeline' => $advancedData['daily_timeline'] ?? [],
                    'device_by_period' => $advancedData['device_by_period'] ?? [],
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
            if (! $link) {
                return $this->linkNotFound();
            }

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
            if (! $link) {
                return $this->linkNotFound();
            }

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
     * Legacy analytics endpoint consumed by GET /api/links/{id}/analytics.
     * Uses SQL aggregations instead of loading all clicks into memory.
     */
    public function getLinkLegacyAnalytics(string $id): JsonResponse
    {
        try {
            $link = $this->findOwnedLink((int) $id);
            if (! $link) {
                return $this->linkNotFound();
            }

            $totalClicks = (int) $link->getAttribute('clicks');

            if ($totalClicks == 0) {
                return response()->json([
                    'has_sufficient_data' => false,
                    'message' => 'Analytics disponíveis após o primeiro clique no link',
                    'total_clicks' => 0,
                    'link_info' => [
                        'id' => $link->id,
                        'slug' => $link->slug,
                        'title' => $link->title,
                        'original_url' => $link->original_url,
                        'shorted_url' => $link->getShortedUrl(),
                        'created_at' => $link->created_at,
                        'is_active' => $link->is_active,
                        'expires_at' => $link->expires_at,
                    ],
                ]);
            }

            $base = fn () => \App\Models\Click::where('link_id', $link->id);

            // Unique IPs for unique_visitors
            $uniqueVisitors = $base()->distinct('ip')->count('ip');

            // avg_daily_clicks: total / days since creation (min 1)
            $daysSinceCreated = max(1, now()->diffInDays($link->created_at));
            $avgDailyClicks = round($totalClicks / $daysSinceCreated, 1);
            $conversionRate = $uniqueVisitors > 0
                ? round(($totalClicks / $uniqueVisitors) * 100, 1).'%'
                : '0%';

            // clicks_over_time: last 30 days, one row per day — SQL DATE aggregation
            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $dateExpr = $isSqlite ? "strftime('%Y-%m-%d', created_at)" : "TO_CHAR(created_at, 'YYYY-MM-DD')";

            $clicksRaw = $base()
                ->where('created_at', '>=', now()->utc()->subDays(29)->startOfDay())
                ->selectRaw("$dateExpr AS day, COUNT(*) AS total")
                ->groupByRaw($dateExpr)
                ->pluck('total', 'day');

            $clicksOverTime = [];
            for ($i = 29; $i >= 0; $i--) {
                $date = now()->utc()->subDays($i)->format('Y-m-d');
                $clicksOverTime[] = ['date' => $date, 'clicks' => (int) ($clicksRaw[$date] ?? 0)];
            }

            // clicks_by_country
            $clicksByCountry = $base()
                ->whereNotNull('country')
                ->selectRaw('country, COUNT(*) AS clicks')
                ->groupBy('country')
                ->orderByDesc('clicks')
                ->limit(10)
                ->get()
                ->map(fn ($r) => ['country' => $r->country, 'clicks' => $r->clicks])
                ->values()
                ->toArray();

            // clicks_by_device
            $clicksByDevice = $base()
                ->whereNotNull('device')
                ->selectRaw('device, COUNT(*) AS clicks')
                ->groupBy('device')
                ->orderByDesc('clicks')
                ->limit(10)
                ->get()
                ->map(fn ($r) => ['device' => $r->device, 'clicks' => $r->clicks])
                ->values()
                ->toArray();

            // clicks_by_referer: group by raw referer in SQL, then re-aggregate by host in PHP
            $rawReferrers = $base()
                ->whereNotNull('referer')
                ->where('referer', '!=', '-')
                ->where('referer', '!=', '')
                ->selectRaw('referer, COUNT(*) AS clicks')
                ->groupBy('referer')
                ->orderByDesc('clicks')
                ->limit(100)
                ->pluck('clicks', 'referer');

            // Add "Direct" count
            $directCount = $base()
                ->where(function ($q) {
                    $q->whereNull('referer')
                        ->orWhere('referer', '-')
                        ->orWhere('referer', '');
                })
                ->count();

            $hostTotals = [];
            if ($directCount > 0) {
                $hostTotals['Direct'] = $directCount;
            }
            foreach ($rawReferrers as $referer => $clicks) {
                $host = parse_url($referer, PHP_URL_HOST) ?: 'Unknown';
                $hostTotals[$host] = ($hostTotals[$host] ?? 0) + $clicks;
            }
            arsort($hostTotals);
            $clicksByReferer = array_map(
                fn ($host, $clicks) => ['referer' => $host, 'clicks' => $clicks],
                array_keys(array_slice($hostTotals, 0, 10)),
                array_slice($hostTotals, 0, 10)
            );

            return response()->json([
                'total_clicks' => $totalClicks,
                'unique_visitors' => $uniqueVisitors,
                'avg_daily_clicks' => $avgDailyClicks,
                'conversion_rate' => $conversionRate,
                'clicks_over_time' => $clicksOverTime,
                'clicks_by_country' => $clicksByCountry,
                'clicks_by_device' => $clicksByDevice,
                'clicks_by_referer' => $clicksByReferer,
                'link_info' => [
                    'id' => $link->id,
                    'slug' => $link->slug,
                    'title' => $link->title,
                    'original_url' => $link->original_url,
                    'shorted_url' => $link->getShortedUrl(),
                    'created_at' => $link->created_at,
                    'is_active' => $link->is_active,
                    'expires_at' => $link->expires_at,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar analytics do link.', $e);
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
