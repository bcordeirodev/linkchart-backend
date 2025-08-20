<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Click;
use App\Models\Link;

/**
 * Serviço para análise de dados de redirecionamento
 * Gera dados para gráficos baseado em métricas reais coletadas
 */
class RedirectAnalyticsService
{
    /**
     * Obtém dados completos para gráficos de redirecionamento
     */
    public function getRedirectAnalytics(int $days = 30): array
    {
        return [
            'summary' => $this->getRedirectSummary($days),
            'charts' => [
                'redirects_over_time' => $this->getRedirectsOverTime($days),
                'redirects_by_country' => $this->getRedirectsByCountry($days),
                'redirects_by_device' => $this->getRedirectsByDevice($days),
                'redirects_by_referer' => $this->getRedirectsByReferer($days),
                'redirects_by_hour' => $this->getRedirectsByHour($days),
                'top_links' => $this->getTopRedirectedLinks($days),
            ],
            'performance' => $this->getRedirectPerformance($days),
        ];
    }

    /**
     * Resumo geral de redirecionamentos
     */
    private function getRedirectSummary(int $days): array
    {
        $totalRedirects = 0;
        $successfulRedirects = 0;
        $failedRedirects = 0;
        $uniqueIps = [];

        // Somar dados dos últimos X dias
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayMetrics = Cache::get("redirect_metrics:day:{$date}", []);

            $totalRedirects += $dayMetrics['total_redirects'] ?? 0;

            // Buscar dados por hora para ter mais precisão
            for ($hour = 0; $hour < 24; $hour++) {
                $hourKey = $date . '-' . sprintf('%02d', $hour);
                $hourMetrics = Cache::get("redirect_metrics:hour:{$hourKey}", []);

                $successfulRedirects += $hourMetrics['successful_redirects'] ?? 0;
                $failedRedirects += $hourMetrics['failed_redirects'] ?? 0;

                if (isset($hourMetrics['unique_ips'])) {
                    $uniqueIps = array_merge($uniqueIps, array_keys($hourMetrics['unique_ips']));
                }
            }
        }

        $uniqueIpsCount = count(array_unique($uniqueIps));
        $successRate = $totalRedirects > 0 ? round(($successfulRedirects / $totalRedirects) * 100, 2) : 0;

        return [
            'total_redirects' => $totalRedirects,
            'successful_redirects' => $successfulRedirects,
            'failed_redirects' => $failedRedirects,
            'unique_visitors' => $uniqueIpsCount,
            'success_rate' => $successRate,
            'avg_redirects_per_day' => round($totalRedirects / $days, 2),
            'period_days' => $days,
        ];
    }

    /**
     * Redirecionamentos ao longo do tempo (para gráfico de linha)
     */
    private function getRedirectsOverTime(int $days): array
    {
        $data = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dateStr = $date->format('Y-m-d');

            $dayTotal = 0;

            // Somar todas as horas do dia
            for ($hour = 0; $hour < 24; $hour++) {
                $hourKey = $dateStr . '-' . sprintf('%02d', $hour);
                $hourMetrics = Cache::get("redirect_metrics:hour:{$hourKey}", []);
                $dayTotal += $hourMetrics['total_redirects'] ?? 0;
            }

            $data[] = [
                'date' => $dateStr,
                'redirects' => $dayTotal,
                'day_name' => $date->format('D'),
            ];
        }

        return $data;
    }

    /**
     * Redirecionamentos por país (para gráfico de pizza/barra)
     */
    private function getRedirectsByCountry(int $days): array
    {
        $countries = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayMetrics = Cache::get("redirect_metrics:day:{$date}", []);

            if (isset($dayMetrics['top_countries'])) {
                foreach ($dayMetrics['top_countries'] as $country => $count) {
                    $countries[$country] = ($countries[$country] ?? 0) + $count;
                }
            }
        }

        // Ordenar por count e pegar top 10
        arsort($countries);
        $countries = array_slice($countries, 0, 10, true);

        $data = [];
        foreach ($countries as $country => $count) {
            $data[] = [
                'country' => $country,
                'redirects' => $count,
                'percentage' => array_sum($countries) > 0 ?
                    round(($count / array_sum($countries)) * 100, 2) : 0
            ];
        }

        return $data;
    }

    /**
     * Redirecionamentos por dispositivo (para gráfico de pizza)
     */
    private function getRedirectsByDevice(int $days): array
    {
        $devices = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayMetrics = Cache::get("redirect_metrics:day:{$date}", []);

            if (isset($dayMetrics['top_devices'])) {
                foreach ($dayMetrics['top_devices'] as $device => $count) {
                    $devices[$device] = ($devices[$device] ?? 0) + $count;
                }
            }
        }

        $data = [];
        $total = array_sum($devices);
        foreach ($devices as $device => $count) {
            $data[] = [
                'device' => ucfirst($device),
                'redirects' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0
            ];
        }

        return $data;
    }

    /**
     * Redirecionamentos por fonte de tráfego (referer)
     */
    private function getRedirectsByReferer(int $days): array
    {
        $referers = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayMetrics = Cache::get("redirect_metrics:day:{$date}", []);

            if (isset($dayMetrics['top_referers'])) {
                foreach ($dayMetrics['top_referers'] as $referer => $count) {
                    $referers[$referer] = ($referers[$referer] ?? 0) + $count;
                }
            }
        }

        // Ordenar e pegar top 10
        arsort($referers);
        $referers = array_slice($referers, 0, 10, true);

        $data = [];
        $total = array_sum($referers);
        foreach ($referers as $referer => $count) {
            $data[] = [
                'referer' => $referer,
                'redirects' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0
            ];
        }

        return $data;
    }

    /**
     * Distribuição de redirecionamentos por hora do dia
     */
    private function getRedirectsByHour(int $days): array
    {
        $hourlyData = array_fill(0, 24, 0);

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayMetrics = Cache::get("redirect_metrics:day:{$date}", []);

            if (isset($dayMetrics['hourly_distribution'])) {
                foreach ($dayMetrics['hourly_distribution'] as $hour => $count) {
                    $hourlyData[$hour] += $count;
                }
            }
        }

        $data = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $data[] = [
                'hour' => sprintf('%02d:00', $hour),
                'redirects' => $hourlyData[$hour],
            ];
        }

        return $data;
    }

    /**
     * Links mais redirecionados
     */
    private function getTopRedirectedLinks(int $days): array
    {
        $slugs = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayMetrics = Cache::get("redirect_metrics:day:{$date}", []);

            if (isset($dayMetrics['top_slugs'])) {
                foreach ($dayMetrics['top_slugs'] as $slug => $count) {
                    $slugs[$slug] = ($slugs[$slug] ?? 0) + $count;
                }
            }
        }

        // Ordenar e pegar top 10
        arsort($slugs);
        $slugs = array_slice($slugs, 0, 10, true);

        $data = [];
        foreach ($slugs as $slug => $redirectCount) {
            // Buscar informações do link
            $link = Link::where('slug', $slug)->first();

            $data[] = [
                'slug' => $slug,
                'title' => $link->title ?? 'Link sem título',
                'original_url' => $link->original_url ?? '',
                'redirects' => $redirectCount,
                'total_clicks' => $link->clicks ?? 0, // Cliques totais do banco
                'created_at' => $link->created_at ?? null,
            ];
        }

        return $data;
    }

    /**
     * Métricas de performance de redirecionamento
     */
    private function getRedirectPerformance(int $days): array
    {
        $totalResponseTime = 0;
        $totalRedirects = 0;
        $responseTimes = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');

            for ($hour = 0; $hour < 24; $hour++) {
                $hourKey = $date . '-' . sprintf('%02d', $hour);
                $hourMetrics = Cache::get("redirect_metrics:hour:{$hourKey}", []);

                if (!empty($hourMetrics)) {
                    $redirects = $hourMetrics['total_redirects'] ?? 0;
                    $avgTime = $hourMetrics['avg_response_time'] ?? 0;

                    $totalRedirects += $redirects;
                    $totalResponseTime += $avgTime * $redirects;

                    // Coletar tempos individuais para percentis
                    for ($j = 0; $j < $redirects; $j++) {
                        $responseTimes[] = $avgTime;
                    }
                }
            }
        }

        $avgResponseTime = $totalRedirects > 0 ? $totalResponseTime / $totalRedirects : 0;

        // Calcular percentis
        sort($responseTimes);
        $count = count($responseTimes);
        $p95 = $count > 0 ? $responseTimes[floor($count * 0.95)] : 0;
        $p99 = $count > 0 ? $responseTimes[floor($count * 0.99)] : 0;

        return [
            'avg_response_time' => round($avgResponseTime, 3),
            'p95_response_time' => round($p95, 3),
            'p99_response_time' => round($p99, 3),
            'total_redirects' => $totalRedirects,
            'redirects_per_day' => round($totalRedirects / $days, 2),
            'performance_score' => $this->calculateRedirectScore($avgResponseTime, $totalRedirects),
        ];
    }

    /**
     * Obtém dados de violações de rate limit específicas de redirecionamento
     */
    public function getRedirectViolations(int $days = 7): array
    {
        $violations = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayViolations = Cache::get("rate_limit:violations:{$date}", []);

            // Filtrar apenas violações de redirecionamento
            $redirectViolations = array_filter($dayViolations, function($violation) {
                return isset($violation['endpoint']) && $violation['endpoint'] === 'link.redirect';
            });

            $violations = array_merge($violations, $redirectViolations);
        }

        // Ordenar por timestamp mais recente
        usort($violations, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_slice($violations, 0, 50);
    }

    /**
     * Compara dados do cache com dados do banco para validação
     * ⚠️ DEPRECATED: Use validateDataConsistencyForUser() para segurança
     * ❌ MÉTODO INSEGURO: Vaza dados de todos os usuários
     *
     * @deprecated Use validateDataConsistencyForUser($userId) instead
     */
    public function validateDataConsistency(): array
    {
        // Dados do banco (fonte da verdade)
        $dbClicks = Click::count();
        $dbUniqueIps = Click::distinct('ip')->count();
        $dbLinksWithClicks = Link::where('clicks', '>', 0)->count();

        // Dados do cache (últimos 30 dias)
        $cacheData = $this->getRedirectSummary(30);

        return [
            'database' => [
                'total_clicks' => $dbClicks,
                'unique_ips' => $dbUniqueIps,
                'links_with_clicks' => $dbLinksWithClicks,
            ],
            'cache_metrics' => [
                'total_redirects' => $cacheData['total_redirects'],
                'unique_visitors' => $cacheData['unique_visitors'],
            ],
            'consistency' => [
                'clicks_match' => abs($dbClicks - $cacheData['total_redirects']) <= 10, // Tolerância de 10
                'ips_match' => abs($dbUniqueIps - $cacheData['unique_visitors']) <= 5, // Tolerância de 5
            ],
            'recommendations' => $this->getDataRecommendations($dbClicks, $cacheData),
        ];
    }

    /**
     * Calcula score de performance para redirecionamentos
     */
    private function calculateRedirectScore(float $avgResponseTime, int $totalRedirects): int
    {
        $score = 100;

        // Penalizar por tempo de resposta alto
        if ($avgResponseTime > 1.0) $score -= 40;
        elseif ($avgResponseTime > 0.5) $score -= 25;
        elseif ($avgResponseTime > 0.3) $score -= 15;
        elseif ($avgResponseTime > 0.1) $score -= 5;

        // Bonificar por volume (indica estabilidade)
        if ($totalRedirects > 50000) $score += 15;
        elseif ($totalRedirects > 10000) $score += 10;
        elseif ($totalRedirects > 1000) $score += 5;

        return max(0, min(100, $score));
    }

    /**
     * Gera recomendações baseadas na consistência dos dados
     */
    private function getDataRecommendations(int $dbClicks, array $cacheData): array
    {
        $recommendations = [];

        $diff = abs($dbClicks - $cacheData['total_redirects']);

        if ($diff > 100) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Grande diferença entre dados do banco e cache',
                'action' => 'Verificar se o middleware de coleta está funcionando corretamente'
            ];
        }

        if ($cacheData['total_redirects'] === 0 && $dbClicks > 0) {
            $recommendations[] = [
                'type' => 'error',
                'message' => 'Cache de métricas vazio mas há cliques no banco',
                'action' => 'Verificar se o RedirectMetricsCollector está ativo'
            ];
        }

        if ($cacheData['success_rate'] < 95) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Taxa de sucesso baixa nos redirecionamentos',
                'action' => 'Investigar erros 404/500 nos logs'
            ];
        }

        return $recommendations;
    }

    /**
     * Valida consistência de dados para um usuário específico
     * Versão segura que filtra dados apenas do usuário autenticado
     */
    public function validateDataConsistencyForUser(int $userId): array
    {
        // Dados do banco (fonte da verdade) - FILTRADOS POR USUÁRIO
        $dbClicks = Click::whereHas('link', function($query) use ($userId) {
            $query->where('user_id', $userId);
        })->count();

        $dbUniqueIps = Click::whereHas('link', function($query) use ($userId) {
            $query->where('user_id', $userId);
        })->distinct('ip')->count();

        $dbLinksWithClicks = Link::where('user_id', $userId)
                                ->where('clicks', '>', 0)
                                ->count();

        // Dados do cache (últimos 30 dias) - filtrar por usuário seria complexo
        // Para simplicidade, usamos apenas dados do banco para validação
        $totalUserLinks = Link::where('user_id', $userId)->count();

        return [
            'user_id' => $userId,
            'database' => [
                'total_clicks' => $dbClicks,
                'unique_ips' => $dbUniqueIps,
                'links_with_clicks' => $dbLinksWithClicks,
                'total_links' => $totalUserLinks,
            ],
            'cache_metrics' => [
                'note' => 'Cache metrics são compartilhados globalmente',
                'recommendation' => 'Use /api/metrics/dashboard para métricas específicas do usuário'
            ],
            'consistency' => [
                'data_integrity' => $dbLinksWithClicks <= $totalUserLinks,
                'has_traffic' => $dbClicks > 0,
            ],
            'recommendations' => $this->getUserDataRecommendations($dbClicks, $dbLinksWithClicks, $totalUserLinks),
        ];
    }

    /**
     * Gera recomendações específicas para dados do usuário
     */
    private function getUserDataRecommendations(int $clicks, int $linksWithClicks, int $totalLinks): array
    {
        $recommendations = [];

        if ($totalLinks === 0) {
            $recommendations[] = [
                'type' => 'info',
                'message' => 'Nenhum link criado ainda',
                'action' => 'Crie seu primeiro link para começar a coletar dados'
            ];
        } elseif ($linksWithClicks === 0 && $totalLinks > 0) {
            $recommendations[] = [
                'type' => 'warning',
                'message' => 'Links criados mas sem tráfego',
                'action' => 'Compartilhe seus links para começar a receber cliques'
            ];
        } elseif ($clicks > 0) {
            $trafficRate = ($linksWithClicks / $totalLinks) * 100;

            if ($trafficRate < 50) {
                $recommendations[] = [
                    'type' => 'info',
                    'message' => "Apenas {$trafficRate}% dos seus links têm tráfego",
                    'action' => 'Considere promover links com menos cliques'
                ];
            } else {
                $recommendations[] = [
                    'type' => 'success',
                    'message' => "Boa performance! {$trafficRate}% dos seus links têm tráfego",
                    'action' => 'Continue monitorando suas métricas'
                ];
            }
        }

        return $recommendations;
    }
}
