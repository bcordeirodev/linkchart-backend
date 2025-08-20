<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Serviço para análise de métricas coletadas
 */
class MetricsAnalysisService
{
    /**
     * Obtém endpoints mais lentos baseado em dados reais
     */
    public function getSlowEndpoints(int $hours = 24, int $limit = 5): array
    {
        $endpointMetrics = [];

        // Coletar dados das últimas X horas
        for ($i = $hours - 1; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('Y-m-d-H');
            $metrics = Cache::get("metrics:hour:{$hour}", []);

            if (!empty($metrics['endpoints'])) {
                foreach ($metrics['endpoints'] as $endpoint => $requests) {
                    if (!isset($endpointMetrics[$endpoint])) {
                        $endpointMetrics[$endpoint] = [
                            'total_requests' => 0,
                            'total_response_time' => 0,
                            'response_times' => []
                        ];
                    }

                    $endpointMetrics[$endpoint]['total_requests'] += $requests;

                    // Estimar tempo de resposta baseado na média da hora
                    $avgResponseTime = $metrics['avg_response_time'] ?? 0;
                    $totalResponseTime = $avgResponseTime * $requests;

                    $endpointMetrics[$endpoint]['total_response_time'] += $totalResponseTime;
                    $endpointMetrics[$endpoint]['response_times'][] = $avgResponseTime;
                }
            }
        }

        // Calcular médias e ordenar
        $slowEndpoints = [];
        foreach ($endpointMetrics as $endpoint => $data) {
            if ($data['total_requests'] > 0) {
                $avgResponseTime = $data['total_response_time'] / $data['total_requests'];

                // Calcular percentis
                $responseTimes = $data['response_times'];
                sort($responseTimes);
                $count = count($responseTimes);

                $p95 = $count > 0 ? $responseTimes[floor($count * 0.95)] : 0;
                $p99 = $count > 0 ? $responseTimes[floor($count * 0.99)] : 0;

                $slowEndpoints[] = [
                    'endpoint' => $endpoint,
                    'avg_response_time' => round($avgResponseTime, 3),
                    'total_requests' => $data['total_requests'],
                    'p95_response_time' => round($p95, 3),
                    'p99_response_time' => round($p99, 3)
                ];
            }
        }

        // Ordenar por tempo de resposta (mais lentos primeiro)
        usort($slowEndpoints, function($a, $b) {
            return $b['avg_response_time'] <=> $a['avg_response_time'];
        });

        return array_slice($slowEndpoints, 0, $limit);
    }

    /**
     * Obtém taxas de erro baseado em dados reais
     */
    public function getErrorRates(int $days = 7): array
    {
        $totalErrors = 0;
        $totalRequests = 0;
        $errorsByCode = [];
        $errorsByEndpoint = [];

        // Coletar dados dos últimos X dias
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');

            // Dados de erro
            $errorData = Cache::get("errors:day:{$date}", []);
            if (!empty($errorData)) {
                $totalErrors += $errorData['total_errors'] ?? 0;

                // Agregar por código de status
                foreach ($errorData['by_status_code'] ?? [] as $code => $count) {
                    $errorsByCode[$code] = ($errorsByCode[$code] ?? 0) + $count;
                }

                // Agregar por endpoint
                foreach ($errorData['by_endpoint'] ?? [] as $endpoint => $count) {
                    $errorsByEndpoint[$endpoint] = ($errorsByEndpoint[$endpoint] ?? 0) + $count;
                }
            }

            // Dados de requests (somar todas as horas do dia)
            for ($hour = 0; $hour < 24; $hour++) {
                $hourKey = $date . '-' . sprintf('%02d', $hour);
                $hourMetrics = Cache::get("metrics:hour:{$hourKey}", []);
                $totalRequests += $hourMetrics['total_requests'] ?? 0;
            }
        }

        // Calcular taxa de erro
        $errorRate = $totalRequests > 0 ? round(($totalErrors / $totalRequests) * 100, 3) : 0;

        // Montar array de erros comuns
        $commonErrors = [];
        $descriptions = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Validation Error',
            429 => 'Rate Limit Exceeded',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable'
        ];

        foreach ($errorsByCode as $code => $count) {
            $commonErrors[] = [
                'code' => (int)$code,
                'count' => $count,
                'description' => $descriptions[$code] ?? 'Unknown Error',
                'percentage' => $totalErrors > 0 ? round(($count / $totalErrors) * 100, 2) : 0
            ];
        }

        // Ordenar por count (mais frequentes primeiro)
        usort($commonErrors, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        // Top endpoints com erro
        $topErrorEndpoints = [];
        foreach ($errorsByEndpoint as $endpoint => $count) {
            $topErrorEndpoints[] = [
                'endpoint' => $endpoint,
                'error_count' => $count,
                'percentage' => $totalErrors > 0 ? round(($count / $totalErrors) * 100, 2) : 0
            ];
        }

        usort($topErrorEndpoints, function($a, $b) {
            return $b['error_count'] <=> $a['error_count'];
        });

        return [
            'total_errors' => $totalErrors,
            'total_requests' => $totalRequests,
            'error_rate' => $errorRate,
            'period_days' => $days,
            'common_errors' => array_slice($commonErrors, 0, 10),
            'top_error_endpoints' => array_slice($topErrorEndpoints, 0, 10),
            'summary' => [
                'most_common_error' => !empty($commonErrors) ? $commonErrors[0] : null,
                'error_trend' => $this->calculateErrorTrend($days),
                'health_status' => $this->getHealthStatus($errorRate)
            ]
        ];
    }

    /**
     * Obtém métricas de performance do sistema
     */
    public function getSystemPerformance(int $hours = 24): array
    {
        $totalRequests = 0;
        $totalResponseTime = 0;
        $totalMemoryUsage = 0;
        $uniqueUsers = [];
        $uniqueIps = [];
        $hourlyData = [];

        for ($i = $hours - 1; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('Y-m-d-H');
            $metrics = Cache::get("metrics:hour:{$hour}", []);

            if (!empty($metrics)) {
                $totalRequests += $metrics['total_requests'] ?? 0;
                $totalResponseTime += $metrics['total_response_time'] ?? 0;
                $totalMemoryUsage += $metrics['total_memory_usage'] ?? 0;

                // Merge unique users and IPs
                $uniqueUsers = array_merge($uniqueUsers, array_keys($metrics['unique_users'] ?? []));
                $uniqueIps = array_merge($uniqueIps, array_keys($metrics['unique_ips'] ?? []));

                $hourlyData[] = [
                    'hour' => $hour,
                    'requests' => $metrics['total_requests'] ?? 0,
                    'avg_response_time' => $metrics['avg_response_time'] ?? 0,
                    'memory_usage' => $metrics['total_memory_usage'] ?? 0
                ];
            }
        }

        $avgResponseTime = $totalRequests > 0 ? $totalResponseTime / $totalRequests : 0;
        $avgMemoryUsage = $totalRequests > 0 ? $totalMemoryUsage / $totalRequests : 0;

        return [
            'total_requests' => $totalRequests,
            'avg_response_time' => round($avgResponseTime, 3),
            'avg_memory_usage' => round($avgMemoryUsage / 1024 / 1024, 2), // MB
            'unique_users' => count(array_unique($uniqueUsers)),
            'unique_ips' => count(array_unique($uniqueIps)),
            'requests_per_hour' => $hours > 0 ? round($totalRequests / $hours, 2) : 0,
            'hourly_data' => $hourlyData,
            'performance_score' => $this->calculatePerformanceScore($avgResponseTime, $totalRequests)
        ];
    }

    /**
     * Obtém violações de rate limit reais
     */
    public function getRateLimitViolations(int $days = 7): array
    {
        $violations = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayViolations = Cache::get("rate_limit:violations:{$date}", []);
            $violations = array_merge($violations, $dayViolations);
        }

        // Ordenar por timestamp mais recente
        usort($violations, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return $violations;
    }

    /**
     * Calcula tendência de erro
     */
    private function calculateErrorTrend(int $days): string
    {
        if ($days < 2) return 'insufficient_data';

        $recentErrors = 0;
        $olderErrors = 0;
        $midPoint = floor($days / 2);

        // Primeira metade (mais recente)
        for ($i = 0; $i < $midPoint; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $errorData = Cache::get("errors:day:{$date}", []);
            $recentErrors += $errorData['total_errors'] ?? 0;
        }

        // Segunda metade (mais antiga)
        for ($i = $midPoint; $i < $days; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $errorData = Cache::get("errors:day:{$date}", []);
            $olderErrors += $errorData['total_errors'] ?? 0;
        }

        if ($olderErrors == 0) return $recentErrors > 0 ? 'increasing' : 'stable';

        $changePercent = (($recentErrors - $olderErrors) / $olderErrors) * 100;

        if ($changePercent > 10) return 'increasing';
        if ($changePercent < -10) return 'decreasing';
        return 'stable';
    }

    /**
     * Determina status de saúde baseado na taxa de erro
     */
    private function getHealthStatus(float $errorRate): string
    {
        if ($errorRate < 1) return 'excellent';
        if ($errorRate < 3) return 'good';
        if ($errorRate < 5) return 'warning';
        return 'critical';
    }

    /**
     * Calcula score de performance
     */
    private function calculatePerformanceScore(float $avgResponseTime, int $totalRequests): int
    {
        $score = 100;

        // Penalizar por tempo de resposta alto
        if ($avgResponseTime > 2.0) $score -= 30;
        elseif ($avgResponseTime > 1.0) $score -= 20;
        elseif ($avgResponseTime > 0.5) $score -= 10;

        // Bonificar por volume de requests (indica estabilidade)
        if ($totalRequests > 10000) $score += 10;
        elseif ($totalRequests > 1000) $score += 5;

        return max(0, min(100, $score));
    }
}
