<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use App\Services\MetricsAnalysisService;

/**
 * Controller para monitoramento e gerenciamento de rate limiting
 */
class RateLimitController
{
    protected MetricsAnalysisService $metricsService;

    public function __construct(MetricsAnalysisService $metricsService)
    {
        $this->metricsService = $metricsService;
    }
    // ❌ REMOVIDO: dashboard() - Use UnifiedMetricsController::getDashboardMetrics

    // ❌ REMOVIDO: userStatus() - Use UnifiedMetricsController::getMetricsByCategory('performance')

    /**
     * Configurações de rate limit por tier
     */
    public function rateLimitConfig(): JsonResponse
    {
        return response()->json([
            'tiers' => [
                'free' => [
                    'name' => 'Gratuito',
                    'links_per_hour' => 50,
                    'links_per_day' => 200,
                    'api_requests_per_minute' => 60,
                    'api_requests_per_hour' => 1000,
                    'features' => [
                        'Analytics básicos',
                        'Links com expiração',
                        'Suporte por email'
                    ]
                ],
                'premium' => [
                    'name' => 'Premium',
                    'links_per_hour' => 500,
                    'links_per_day' => 5000,
                    'api_requests_per_minute' => 300,
                    'api_requests_per_hour' => 10000,
                    'features' => [
                        'Analytics avançados',
                        'Links personalizados',
                        'Bulk operations',
                        'Suporte prioritário'
                    ]
                ],
                'enterprise' => [
                    'name' => 'Enterprise',
                    'links_per_hour' => -1, // Ilimitado
                    'links_per_day' => -1,  // Ilimitado
                    'api_requests_per_minute' => 1000,
                    'api_requests_per_hour' => 50000,
                    'features' => [
                        'Tudo do Premium',
                        'Links ilimitados',
                        'API dedicada',
                        'SLA garantido',
                        'Suporte 24/7'
                    ]
                ]
            ],
            'endpoint_limits' => [
                'auth.login' => [
                    'description' => 'Tentativas de login',
                    'limits' => [
                        'per_minute' => 5,
                        'per_hour' => 20,
                        'lockout_duration' => 900
                    ]
                ],
                'link.create' => [
                    'description' => 'Criação de links',
                    'limits' => [
                        'per_minute' => 30,
                        'per_hour' => 200,
                        'burst_limit' => 5
                    ]
                ],
                'link.delete' => [
                    'description' => 'Exclusão de links',
                    'limits' => [
                        'per_minute' => 20,
                        'per_hour' => 100
                    ]
                ]
            ]
        ]);
    }

    /**
     * Histórico de violações de rate limit
     */
    public function violations(Request $request): JsonResponse
    {
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 50);

        // Buscar dados reais de violações do cache
        $violations = $this->getRateLimitViolations();

        // Paginar resultados
        $total = count($violations);
        $offset = ($page - 1) * $perPage;
        $paginatedViolations = array_slice($violations, $offset, $perPage);

        return response()->json([
            'data' => $paginatedViolations,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
                'from' => $offset + 1,
                'to' => min($offset + $perPage, $total)
            ]
        ]);
    }

    /**
     * Resetar rate limit para um usuário (admin only)
     */
    public function resetUserLimits(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'type' => 'sometimes|in:all,minute,hour,day'
        ]);

        $userId = $request->get('user_id');
        $type = $request->get('type', 'all');

        try {
            $this->clearUserRateLimits($userId, $type);

            return response()->json([
                'message' => 'Rate limits resetados com sucesso',
                'user_id' => $userId,
                'type' => $type
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao resetar rate limits',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Métricas de performance da API
     */
    // ❌ REMOVIDO: performanceMetrics() - Use UnifiedMetricsController::getMetricsByCategory('performance')

    // ==========================================
    // MÉTODOS AUXILIARES
    // ==========================================

    protected function getUserTier($userId): string
    {
        return Cache::remember("user_tier:{$userId}", 3600, function() {
            // Implementar lógica para determinar tier do usuário
            return 'free';
        });
    }

    protected function getTierLimits($tier): array
    {
        $tiers = [
            'free' => [
                'links_per_hour' => 50,
                'links_per_day' => 200,
                'api_requests_per_minute' => 60,
                'api_requests_per_hour' => 1000,
            ],
            'premium' => [
                'links_per_hour' => 500,
                'links_per_day' => 5000,
                'api_requests_per_minute' => 300,
                'api_requests_per_hour' => 10000,
            ],
            'enterprise' => [
                'links_per_hour' => -1,
                'links_per_day' => -1,
                'api_requests_per_minute' => 1000,
                'api_requests_per_hour' => 50000,
            ]
        ];

        return $tiers[$tier] ?? $tiers['free'];
    }

    protected function getCurrentUsage($userId): array
    {
        $prefix = "rate_limit:user:{$userId}";
        $now = now();

        return [
            'api_requests_current_minute' => Cache::get("{$prefix}:minute:" . $now->format('Y-m-d-H-i'), 0),
            'api_requests_current_hour' => Cache::get("{$prefix}:hour:" . $now->format('Y-m-d-H'), 0),
            'links_current_hour' => Cache::get("{$prefix}:links:hour:" . $now->format('Y-m-d-H'), 0),
            'links_current_day' => Cache::get("{$prefix}:links:day:" . $now->format('Y-m-d'), 0),
        ];
    }

    protected function calculateUsagePercentage($limits, $usage): array
    {
        $percentage = [];

        foreach ($usage as $key => $value) {
            $limitKey = str_replace(['current_', '_current'], ['', '_per_'], $key);
            $limit = $limits[$limitKey] ?? 1;

            if ($limit === -1) {
                $percentage[$key] = 0; // Ilimitado
            } else {
                $percentage[$key] = round(($value / $limit) * 100, 2);
            }
        }

        return $percentage;
    }

    protected function getRateLimitViolations(): array
    {
        return $this->metricsService->getRateLimitViolations(7);
    }

        protected function getPerformanceData(): array
    {
        // DADOS REAIS: Buscar performance real das métricas coletadas
        $endpoints = ['link.create', 'auth.login', 'analytics.view', 'link.delete', 'rate-limit.dashboard'];
        $performance = [];

        foreach ($endpoints as $endpoint) {
            // Buscar métricas reais das últimas 24 horas
            $totalRequests = 0;
            $totalResponseTime = 0;
            $responseTimes = [];

            for ($i = 23; $i >= 0; $i--) {
                $hour = now()->subHours($i)->format('Y-m-d-H');
                $metrics = Cache::get("dashboard:metrics:{$hour}", []);

                if (isset($metrics['endpoints'][$endpoint])) {
                    $hourlyRequests = $metrics['endpoints'][$endpoint];
                    $hourlyAvgTime = $metrics['avg_response_time'] ?? 0;

                    $totalRequests += $hourlyRequests;
                    $totalResponseTime += $hourlyAvgTime * $hourlyRequests;

                    // Coletar tempos para percentis
                    for ($j = 0; $j < $hourlyRequests; $j++) {
                        $responseTimes[] = $hourlyAvgTime;
                    }
                }
            }

            $avgTime = $totalRequests > 0 ? round($totalResponseTime / $totalRequests, 2) : 0;

            // Calcular percentis reais
            sort($responseTimes);
            $count = count($responseTimes);
            $p95 = $count > 0 ? $responseTimes[floor($count * 0.95)] ?? 0 : 0;
            $p99 = $count > 0 ? $responseTimes[floor($count * 0.99)] ?? 0 : 0;

            $performance[] = [
                'endpoint' => $endpoint,
                'avg_response_time' => $avgTime,
                'total_requests' => $totalRequests,
                'p95_response_time' => round($p95, 2),
                'p99_response_time' => round($p99, 2)
            ];
        }

        return $performance;
    }

        protected function getUserStatusData(): array
    {
        // DADOS REAIS: Buscar apenas o usuário atual
        $userId = auth()->guard('api')->id();
        $user = auth()->guard('api')->user();

        if (!$user) {
            return [];
        }

        // Calcular requests reais das últimas 24h
        $requests24h = 0;
        for ($i = 23; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('Y-m-d-H');
            $userMetrics = Cache::get("user_metrics:{$userId}:{$hour}", []);
            $requests24h += $userMetrics['api_requests'] ?? 0;
        }

        // Determinar status baseado no uso real
        $userTier = $this->getUserTier($userId);
        $limits = $this->getTierLimits($userTier);
        $usage = $this->getCurrentUsage($userId);

        $status = 'active';
        if ($usage['api_requests_current_minute'] >= $limits['api_requests_per_minute'] * 0.9) {
            $status = 'warning';
        }
        if ($usage['api_requests_current_minute'] >= $limits['api_requests_per_minute']) {
            $status = 'limited';
        }

        return [[
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'requests_24h' => $requests24h,
            'last_request_at' => now()->toISOString(),
            'status' => $status
        ]];
    }

    protected function getRealRateLimitViolations(): array
    {
        $violations = [];
        $today = now()->format('Y-m-d');

        // Buscar violações dos últimos 7 dias
        for ($i = 0; $i < 7; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayViolations = Cache::get("rate_limit:violations:{$date}", []);
            $violations = array_merge($violations, $dayViolations);
        }

        // Ordenar por timestamp mais recente
        usort($violations, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        // Retornar apenas os 50 mais recentes
        return array_slice($violations, 0, 50);
    }

        protected function getUserHourlyMetrics($userId, $linkSlugs): array
    {
        $last24Hours = [];
        $linkIds = \App\Models\Link::where('user_id', $userId)->pluck('id')->toArray();

        for ($i = 23; $i >= 0; $i--) {
            $hour = now()->subHours($i);
            $hourKey = $hour->format('Y-m-d-H');

            // Buscar cliques reais nesta hora
            $hourlyClicks = \App\Models\Click::whereIn('link_id', $linkIds)
                ->whereBetween('created_at', [
                    $hour->startOfHour(),
                    $hour->endOfHour()
                ])
                ->get();

            $linkClicks = $hourlyClicks->count();
            $uniqueIps = $hourlyClicks->unique('ip')->count();

            // Buscar métricas de cache se disponíveis
            $userMetrics = Cache::get("user_metrics:{$userId}:{$hourKey}", [
                'api_requests' => 0,
                'avg_response_time' => 0,
            ]);

            $last24Hours[] = [
                'hour' => $hourKey,
                'total_requests' => $userMetrics['api_requests'],
                'unique_users' => 1, // Sempre 1 para o usuário atual
                'unique_ips' => $uniqueIps,
                'avg_response_time' => round($userMetrics['avg_response_time'], 3),
                'link_clicks' => $linkClicks, // Dados reais da tabela clicks
                'link_views' => $linkClicks, // Por enquanto, views = clicks
            ];
        }

        return $last24Hours;
    }

    protected function getUserRateLimitViolations($userId): array
    {
        $violations = [];

        // Buscar violações dos últimos 7 dias para este usuário
        for ($i = 0; $i < 7; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayViolations = Cache::get("rate_limit:violations:{$date}", []);

            // Filtrar apenas violações deste usuário
            $userViolations = array_filter($dayViolations, function($violation) use ($userId) {
                return isset($violation['user_id']) && $violation['user_id'] == $userId;
            });

            $violations = array_merge($violations, $userViolations);
        }

        // Ordenar por timestamp mais recente
        usort($violations, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_slice($violations, 0, 20); // Últimas 20 violações
    }

        protected function getUserPerformanceData($userId, $linkSlugs): array
    {
        $performance = [];

        // Buscar dados reais de performance do cache de métricas
        $userEndpoints = [
            'link.redirect' => 'Redirecionamentos',
            'link.preview' => 'Visualizações',
            'link.create' => 'Criação de Links',
            'link.edit' => 'Edição de Links',
            'analytics.view' => 'Analytics'
        ];

        foreach ($userEndpoints as $endpoint => $description) {
            // Buscar métricas reais das últimas 24 horas
            $totalRequests = 0;
            $totalResponseTime = 0;
            $responseTimes = [];

            for ($i = 23; $i >= 0; $i--) {
                $hour = now()->subHours($i)->format('Y-m-d-H');
                $userMetrics = Cache::get("user_metrics:{$userId}:{$hour}", []);

                if (isset($userMetrics['endpoints'][$endpoint])) {
                    $hourlyRequests = $userMetrics['endpoints'][$endpoint];
                    $hourlyAvgTime = $userMetrics['avg_response_time'] ?? 0;

                    $totalRequests += $hourlyRequests;
                    $totalResponseTime += $hourlyAvgTime * $hourlyRequests;

                    // Coletar tempos para calcular percentis
                    for ($j = 0; $j < $hourlyRequests; $j++) {
                        $responseTimes[] = $hourlyAvgTime;
                    }
                }
            }

            $avgTime = $totalRequests > 0 ? round($totalResponseTime / $totalRequests, 2) : 0;

            // Calcular percentis reais
            sort($responseTimes);
            $count = count($responseTimes);
            $p95 = $count > 0 ? $responseTimes[floor($count * 0.95)] : 0;
            $p99 = $count > 0 ? $responseTimes[floor($count * 0.99)] : 0;

            $performance[] = [
                'endpoint' => $endpoint,
                'description' => $description,
                'avg_response_time' => $avgTime,
                'total_requests' => $totalRequests,
                'p95_response_time' => round($p95, 2),
                'p99_response_time' => round($p99, 2)
            ];
        }

        return $performance;
    }

    protected function getCurrentUserStatus($userId): array
    {
        $userTier = $this->getUserTier($userId);
        $limits = $this->getTierLimits($userTier);
        $usage = $this->getCurrentUsage($userId);

        return [
            'id' => $userId,
            'name' => auth()->guard('api')->user()->name ?? 'Usuário',
            'email' => auth()->guard('api')->user()->email ?? '',
            'tier' => $userTier,
            'requests_24h' => array_sum($usage),
            'last_request_at' => now()->toISOString(),
            'status' => 'active',
            'limits' => $limits,
            'current_usage' => $usage
        ];
    }

    protected function getRateLimitConfig(): array
    {
        return [
            'enabled' => true,
            'default_tier' => 'free',
            'monitoring_enabled' => true,
            'auto_ban_enabled' => false,
            'violation_threshold' => 10,
        ];
    }

    protected function clearUserRateLimits($userId, $type): void
    {
        $prefix = "rate_limit:user:{$userId}";
        $patterns = [];

        switch ($type) {
            case 'minute':
                $patterns[] = "{$prefix}:minute:*";
                break;
            case 'hour':
                $patterns[] = "{$prefix}:hour:*";
                $patterns[] = "{$prefix}:links:hour:*";
                break;
            case 'day':
                $patterns[] = "{$prefix}:links:day:*";
                break;
            case 'all':
            default:
                $patterns[] = "{$prefix}:*";
                break;
        }

        foreach ($patterns as $pattern) {
            $keys = Cache::getRedis()->keys($pattern);
            if (!empty($keys)) {
                Cache::getRedis()->del($keys);
            }
        }
    }

    protected function checkCacheHealth(): string
    {
        try {
            Cache::put('health_check', 'ok', 10);
            $result = Cache::get('health_check');
            return $result === 'ok' ? 'healthy' : 'degraded';
        } catch (\Exception $e) {
            return 'unhealthy';
        }
    }

    protected function checkDatabaseHealth(): string
    {
        try {
            \DB::select('SELECT 1');
            return 'healthy';
        } catch (\Exception $e) {
            return 'unhealthy';
        }
    }

    protected function getSlowEndpoints(): array
    {
        return $this->metricsService->getSlowEndpoints(24, 5);
    }

        protected function getErrorRates(): array
    {
        return $this->metricsService->getErrorRates(7);
    }
}
