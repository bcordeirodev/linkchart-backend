<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para coletar métricas de performance e erros
 */
class MetricsCollector
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        // Processar request primeiro
        $response = $next($request);

        // Tentar coletar métricas de forma segura
        try {
            // Identificar endpoint
            $endpoint = $this->identifyEndpoint($request);
            $userId = $this->getUserId($request);
            $ipAddress = $request->ip();

            // Calcular métricas
            $responseTime = microtime(true) - $startTime;
            $memoryUsage = memory_get_usage(true) - $startMemory;
            $statusCode = $response->getStatusCode();

            // Coletar métricas
            $this->collectMetrics([
                'endpoint' => $endpoint,
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $userId,
                'ip_address' => $ipAddress,
                'status_code' => $statusCode,
                'response_time' => $responseTime,
                'memory_usage' => $memoryUsage,
                'timestamp' => now(),
                'user_agent' => $request->userAgent(),
                'referer' => $request->headers->get('referer'),
            ]);

            // Se for erro, registrar separadamente
            if ($statusCode >= 400) {
                $this->collectError([
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                    'user_id' => $userId,
                    'ip_address' => $ipAddress,
                    'error_message' => $this->getErrorMessage($response),
                    'request_data' => $this->sanitizeRequestData($request),
                    'timestamp' => now(),
                ]);
            }
        } catch (\Exception $e) {
            // Falha na coleta de métricas não deve quebrar a aplicação
            Log::warning('MetricsCollector failed but request continued', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
                'method' => $request->method()
            ]);
        }

        return $response;
    }

    /**
     * Identifica o endpoint baseado na rota
     */
    private function identifyEndpoint(Request $request): string
    {
        $route = $request->route();
        if (!$route) {
            return 'unknown';
        }

        $routeName = $route->getName();
        if ($routeName) {
            return $routeName;
        }

        // Mapear por padrões de URL
        $path = $request->path();
        $method = $request->method();

        $patterns = [
            'POST:auth/login' => 'auth.login',
            'POST:auth/register' => 'auth.register',
            'POST:auth/google' => 'auth.google',
            'GET:me' => 'auth.me',
            'POST:logout' => 'auth.logout',
            'POST:gerar-url' => 'link.create',
            'GET:link' => 'link.index',
            'GET:link/\d+' => 'link.show',
            'PUT:link/\d+' => 'link.update',
            'DELETE:link/\d+' => 'link.delete',
            'GET:link/.+/analytics' => 'analytics.view',
            'GET:analytics' => 'analytics.general',
            'GET:r/.+' => 'link.redirect',
            'GET:link/by-slug/.+' => 'link.preview',
            'GET:rate-limit/dashboard' => 'rate-limit.dashboard',
            'GET:rate-limit/status' => 'rate-limit.status',
        ];

        $key = $method . ':' . $path;

        foreach ($patterns as $pattern => $endpoint) {
            if (preg_match('#^' . str_replace('\d+', '\d+', $pattern) . '$#', $key)) {
                return $endpoint;
            }
        }

        return 'unknown';
    }

    /**
     * Obtém ID do usuário se autenticado
     */
    private function getUserId(Request $request): ?int
    {
        try {
            $user = auth()->guard('api')->user();
            return $user ? $user->id : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Coleta métricas de performance
     */
    private function collectMetrics(array $metrics): void
    {
        try {
            // Verificar se Cache está disponível antes de usar
            if (!$this->isCacheAvailable()) {
                Log::info('Cache not available, skipping metrics collection');
                return;
            }

            $hour = now()->format('Y-m-d-H');
            $minute = now()->format('Y-m-d-H-i');

            // Métricas por hora
            $hourKey = "metrics:hour:{$hour}";
            $hourMetrics = Cache::get($hourKey, [
                'total_requests' => 0,
                'total_response_time' => 0,
                'total_memory_usage' => 0,
                'unique_users' => [],
                'unique_ips' => [],
                'endpoints' => [],
                'status_codes' => [],
                'avg_response_time' => 0,
            ]);

            $hourMetrics['total_requests']++;
            $hourMetrics['total_response_time'] += $metrics['response_time'];
            $hourMetrics['total_memory_usage'] += $metrics['memory_usage'];

            if ($metrics['user_id']) {
                $hourMetrics['unique_users'][$metrics['user_id']] = true;
            }
            $hourMetrics['unique_ips'][$metrics['ip_address']] = true;

            $hourMetrics['endpoints'][$metrics['endpoint']] =
                ($hourMetrics['endpoints'][$metrics['endpoint']] ?? 0) + 1;

            $hourMetrics['status_codes'][$metrics['status_code']] =
                ($hourMetrics['status_codes'][$metrics['status_code']] ?? 0) + 1;

            $hourMetrics['avg_response_time'] =
                $hourMetrics['total_response_time'] / $hourMetrics['total_requests'];

            Cache::put($hourKey, $hourMetrics, 3600); // 1 hora

            // Métricas por minuto (para rate limiting)
            $minuteKey = "metrics:minute:{$minute}";
            $minuteMetrics = Cache::get($minuteKey, [
                'total_requests' => 0,
                'endpoints' => [],
            ]);

            $minuteMetrics['total_requests']++;
            $minuteMetrics['endpoints'][$metrics['endpoint']] =
                ($minuteMetrics['endpoints'][$metrics['endpoint']] ?? 0) + 1;

            Cache::put($minuteKey, $minuteMetrics, 120); // 2 minutos

            // Métricas do usuário (se autenticado)
            if ($metrics['user_id']) {
                $this->collectUserMetrics($metrics['user_id'], $metrics);
            }

            // Dashboard metrics (formato compatível com RateLimitController)
            $dashboardKey = "dashboard:metrics:{$hour}";
            $dashboardMetrics = Cache::get($dashboardKey, [
                'total_requests' => 0,
                'avg_response_time' => 0,
                'unique_users' => [],
                'unique_ips' => [],
                'endpoints' => [],
            ]);

            $dashboardMetrics['total_requests']++;
            $dashboardMetrics['avg_response_time'] =
                ($dashboardMetrics['avg_response_time'] + $metrics['response_time']) / 2;

            if ($metrics['user_id']) {
                $dashboardMetrics['unique_users'][$metrics['user_id']] = true;
            }
            $dashboardMetrics['unique_ips'][$metrics['ip_address']] = true;
            $dashboardMetrics['endpoints'][$metrics['endpoint']] =
                ($dashboardMetrics['endpoints'][$metrics['endpoint']] ?? 0) + 1;

            Cache::put($dashboardKey, $dashboardMetrics, 3600);

        } catch (\Exception $e) {
            Log::error('Failed to collect metrics', [
                'error' => $e->getMessage(),
                'metrics' => $metrics
            ]);
        }
    }

    /**
     * Verifica se o Cache está disponível com fallback inteligente
     */
    private function isCacheAvailable(): bool
    {
        try {
            // Priorizar Redis se disponível
            $cacheDriver = env('CACHE_DRIVER', 'file');

            if ($cacheDriver === 'redis') {
                // Testar Redis primeiro
                try {
                    Cache::store('redis')->put('cache_test_redis_' . uniqid(), 'test', 1);
                    return true;
                } catch (\Exception $redisError) {
                    Log::warning('Redis cache not available, falling back to file cache: ' . $redisError->getMessage());
                    $cacheDriver = 'file'; // Fallback para file
                }
            }

            if ($cacheDriver === 'file') {
                $cachePath = env('CACHE_PATH', storage_path('framework/cache/data'));

                // Verificar se diretório existe e é gravável
                if (!is_dir($cachePath)) {
                    // Tentar criar com permissões corretas
                    if (!mkdir($cachePath, 0777, true) && !is_dir($cachePath)) {
                        Log::warning('Cache directory could not be created: ' . $cachePath);
                        return false;
                    }
                    // Garantir ownership correto após criação
                    @chown($cachePath, 'www-data');
                    @chgrp($cachePath, 'www-data');
                }

                if (!is_writable($cachePath)) {
                    Log::warning('Cache directory not writable: ' . $cachePath);
                    // Tentar corrigir permissões
                    @chmod($cachePath, 0777);
                    if (!is_writable($cachePath)) {
                        return false;
                    }
                }
            }

            // Testar operação de cache final
            Cache::put('cache_test_' . uniqid(), 'test', 1);
            return true;

        } catch (\Exception $e) {
            Log::warning('Cache completely unavailable: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Coleta métricas específicas do usuário
     */
    private function collectUserMetrics(int $userId, array $metrics): void
    {
        if (!$this->isCacheAvailable()) {
            return;
        }

        $hour = now()->format('Y-m-d-H');
        $key = "user_metrics:{$userId}:{$hour}";

        $userMetrics = Cache::get($key, [
            'api_requests' => 0,
            'link_clicks' => 0,
            'link_views' => 0,
            'avg_response_time' => 0,
            'unique_ips' => [],
            'endpoints' => []
        ]);

        $userMetrics['api_requests']++;
        $userMetrics['unique_ips'][$metrics['ip_address']] = true;
        $userMetrics['endpoints'][$metrics['endpoint']] =
            ($userMetrics['endpoints'][$metrics['endpoint']] ?? 0) + 1;

        // Contabilizar cliques e visualizações
        if ($metrics['endpoint'] === 'link.redirect') {
            $userMetrics['link_clicks']++;
        } elseif ($metrics['endpoint'] === 'link.preview') {
            $userMetrics['link_views']++;
        }

        $userMetrics['avg_response_time'] =
            ($userMetrics['avg_response_time'] + $metrics['response_time']) / 2;

        Cache::put($key, $userMetrics, 3600);
    }

    /**
     * Coleta dados de erro
     */
    private function collectError(array $errorData): void
    {
        try {
            if (!$this->isCacheAvailable()) {
                Log::warning('Cache not available, logging error directly', $errorData);
                return;
            }

            $date = now()->format('Y-m-d');
            $hour = now()->format('Y-m-d-H');

            // Erros por dia
            $dayKey = "errors:day:{$date}";
            $dayErrors = Cache::get($dayKey, [
                'total_errors' => 0,
                'by_status_code' => [],
                'by_endpoint' => [],
                'by_user' => [],
                'errors' => []
            ]);

            $dayErrors['total_errors']++;
            $dayErrors['by_status_code'][$errorData['status_code']] =
                ($dayErrors['by_status_code'][$errorData['status_code']] ?? 0) + 1;
            $dayErrors['by_endpoint'][$errorData['endpoint']] =
                ($dayErrors['by_endpoint'][$errorData['endpoint']] ?? 0) + 1;

            if ($errorData['user_id']) {
                $dayErrors['by_user'][$errorData['user_id']] =
                    ($dayErrors['by_user'][$errorData['user_id']] ?? 0) + 1;
            }

            // Manter apenas os últimos 100 erros detalhados
            $dayErrors['errors'][] = $errorData;
            if (count($dayErrors['errors']) > 100) {
                $dayErrors['errors'] = array_slice($dayErrors['errors'], -100);
            }

            Cache::put($dayKey, $dayErrors, 86400); // 24 horas

            // Erros por hora
            $hourKey = "errors:hour:{$hour}";
            $hourErrors = Cache::get($hourKey, [
                'total_errors' => 0,
                'by_status_code' => [],
            ]);

            $hourErrors['total_errors']++;
            $hourErrors['by_status_code'][$errorData['status_code']] =
                ($hourErrors['by_status_code'][$errorData['status_code']] ?? 0) + 1;

            Cache::put($hourKey, $hourErrors, 3600);

            // Log do erro
            Log::warning('API Error Collected', $errorData);

        } catch (\Exception $e) {
            Log::error('Failed to collect error data', [
                'error' => $e->getMessage(),
                'error_data' => $errorData
            ]);
        }
    }

    /**
     * Extrai mensagem de erro da resposta
     */
    private function getErrorMessage(Response $response): ?string
    {
        try {
            $content = $response->getContent();
            if ($content) {
                $data = json_decode($content, true);
                return $data['message'] ?? $data['error'] ?? null;
            }
        } catch (\Exception $e) {
            // Ignorar erros de parsing
        }

        return null;
    }

    /**
     * Sanitiza dados da requisição para logging
     */
    private function sanitizeRequestData(Request $request): array
    {
        $data = $request->all();

        // Remover dados sensíveis
        $sensitiveFields = ['password', 'password_confirmation', 'token', 'api_key'];
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '[REDACTED]';
            }
        }

        return $data;
    }
}
