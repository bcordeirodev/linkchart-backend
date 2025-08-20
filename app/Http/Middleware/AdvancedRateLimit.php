<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

/**
 * Middleware avançado de rate limiting com diferentes tiers e monitoramento
 */
class AdvancedRateLimit
{
    /**
     * Configurações de rate limit por tier
     */
    protected $tiers = [
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
            'links_per_hour' => -1, // Ilimitado
            'links_per_day' => -1,  // Ilimitado
            'api_requests_per_minute' => 1000,
            'api_requests_per_hour' => 50000,
        ]
    ];

    /**
     * Configurações específicas APENAS para o endpoint público de redirecionamento
     * Rate limiting focado EXCLUSIVAMENTE em /r/{slug}
     */
    protected $endpointLimits = [
        'link.redirect' => [
            'requests_per_minute' => 100, // 100 redirects por minuto por IP
            'requests_per_hour' => 2000,  // 2000 redirects por hora por IP
            'requests_per_day' => 10000,  // 10k redirects por dia por IP
            'burst_limit' => 20, // Burst de 20 redirects em 10 segundos
            'description' => 'Limite para acesso público aos links encurtados'
        ]
    ];

    public function handle(Request $request, Closure $next, string $endpoint = null)
    {
        // Se não foi especificado um endpoint, pular rate limiting
        if (!$endpoint) {
            return $next($request);
        }

        // Verificar se é um endpoint de links que deve ter rate limiting
        if (!isset($this->endpointLimits[$endpoint])) {
            return $next($request);
        }

        $userId = $this->getUserId($request);
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();

        // Identificar tier do usuário
        $userTier = $this->getUserTier($userId);

        // Verificar rate limits específicos para links
        $rateLimitResult = $this->checkLinkRateLimit($request, $userId, $ipAddress, $endpoint, $userTier);

        if ($rateLimitResult['exceeded']) {
            return $this->rateLimitExceededResponse($rateLimitResult);
        }

        // Processar request
        $response = $next($request);

        // Registrar métricas específicas de links
        $this->logLinkMetrics($request, $userId, $ipAddress, $userAgent, $endpoint);

        // Adicionar headers de rate limit
        $this->addRateLimitHeaders($response, $rateLimitResult);

        return $response;
    }

    /**
     * Verifica rate limits específicos para endpoints de links
     */
    protected function checkLinkRateLimit(Request $request, $userId, $ipAddress, $endpoint, $userTier)
    {
        $checks = [];
        $limits = $this->endpointLimits[$endpoint];

        // 1. Rate limit por usuário (se autenticado) - específico para links
        if ($userId) {
            $checks[] = $this->checkUserLinkRateLimit($userId, $endpoint, $userTier, $limits);
        }

        // 2. Rate limit por IP - específico para links
        $checks[] = $this->checkIpLinkRateLimit($ipAddress, $endpoint, $limits);

        // 3. Rate limit de burst (se configurado)
        if (isset($limits['burst_limit'])) {
            $checks[] = $this->checkBurstRateLimit($userId ?: $ipAddress, $endpoint, $limits);
        }

        // Verificar se algum limite foi excedido
        foreach ($checks as $check) {
            if ($check['exceeded']) {
                return $check;
            }
        }

        // Retornar o check mais restritivo (menor remaining)
        return collect($checks)->sortBy('remaining')->first();
    }

    /**
     * Verifica rate limit por usuário para operações de links
     */
    protected function checkUserLinkRateLimit($userId, $endpoint, $userTier, $limits)
    {
        $tierLimits = $this->tiers[$userTier] ?? $this->tiers['free'];
        $prefix = "rate_limit:user:{$userId}:link";

        $checks = [];

        // Limite por minuto
        if (isset($limits['requests_per_minute'])) {
            $checks['minute'] = [
                'key' => "{$prefix}:{$endpoint}:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => $limits['requests_per_minute'],
                'window' => 60
            ];
        }

        // Limite por hora
        if (isset($limits['requests_per_hour'])) {
            $checks['hour'] = [
                'key' => "{$prefix}:{$endpoint}:hour:" . now()->format('Y-m-d-H'),
                'limit' => $limits['requests_per_hour'],
                'window' => 3600
            ];
        }

        // Limite por dia
        if (isset($limits['requests_per_day'])) {
            $checks['day'] = [
                'key' => "{$prefix}:{$endpoint}:day:" . now()->format('Y-m-d'),
                'limit' => $limits['requests_per_day'],
                'window' => 86400
            ];
        }

        // Verificar limites específicos do tier para criação de links
        if ($endpoint === 'link.create') {
            if ($tierLimits['links_per_hour'] !== -1) {
                $checks['tier_hour'] = [
                    'key' => "{$prefix}:create:tier:hour:" . now()->format('Y-m-d-H'),
                    'limit' => $tierLimits['links_per_hour'],
                    'window' => 3600
                ];
            }

            if ($tierLimits['links_per_day'] !== -1) {
                $checks['tier_day'] = [
                    'key' => "{$prefix}:create:tier:day:" . now()->format('Y-m-d'),
                    'limit' => $tierLimits['links_per_day'],
                    'window' => 86400
                ];
            }
        }

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Verifica rate limit por IP para operações de links
     */
    protected function checkIpLinkRateLimit($ipAddress, $endpoint, $limits)
    {
        $prefix = "rate_limit:ip:" . md5($ipAddress) . ":link";

        $checks = [];

        // Limite por minuto
        if (isset($limits['requests_per_minute'])) {
            $checks['minute'] = [
                'key' => "{$prefix}:{$endpoint}:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => $limits['requests_per_minute'],
                'window' => 60
            ];
        }

        // Limite por hora
        if (isset($limits['requests_per_hour'])) {
            $checks['hour'] = [
                'key' => "{$prefix}:{$endpoint}:hour:" . now()->format('Y-m-d-H'),
                'limit' => $limits['requests_per_hour'],
                'window' => 3600
            ];
        }

        // Limite por dia
        if (isset($limits['requests_per_day'])) {
            $checks['day'] = [
                'key' => "{$prefix}:{$endpoint}:day:" . now()->format('Y-m-d'),
                'limit' => $limits['requests_per_day'],
                'window' => 86400
            ];
        }

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Verifica rate limit de burst
     */
    protected function checkBurstRateLimit($identifier, $endpoint, $limits)
    {
        $prefix = "rate_limit:burst:" . md5($identifier) . ":link";

        $checks = [
            'burst' => [
                'key' => "{$prefix}:{$endpoint}:burst:" . floor(now()->timestamp / 10), // Janela de 10 segundos
                'limit' => $limits['burst_limit'],
                'window' => 10
            ]
        ];

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Registra métricas específicas de links
     */
    protected function logLinkMetrics(Request $request, $userId, $ipAddress, $userAgent, $endpoint)
    {
        $responseTime = microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : $_SERVER['REQUEST_TIME_FLOAT']);

        $metrics = [
            'timestamp' => now()->toISOString(),
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'endpoint' => $endpoint,
            'method' => $request->method(),
            'path' => $request->path(),
            'response_time' => $responseTime,
            'link_operation' => true, // Marcar como operação de link
        ];

        // Log específico para operações de links
        Log::channel('metrics')->info('link_operation', $metrics);

        // Atualizar métricas de links no cache
        $this->updateLinkMetrics($metrics);

        // Registrar violação se aplicável
        $this->checkAndLogLinkViolation($userId, $ipAddress, $endpoint, $userAgent);
    }

    /**
     * Atualiza métricas específicas de links
     */
    protected function updateLinkMetrics($metrics)
    {
        $hour = now()->format('Y-m-d-H');
        $key = "link_metrics:hour:{$hour}";

        $linkMetrics = Cache::get($key, [
            'total_operations' => 0,
            'by_endpoint' => [],
            'by_user' => [],
            'avg_response_time' => 0,
            'total_response_time' => 0,
        ]);

        $linkMetrics['total_operations']++;
        $linkMetrics['by_endpoint'][$metrics['endpoint']] =
            ($linkMetrics['by_endpoint'][$metrics['endpoint']] ?? 0) + 1;

        if ($metrics['user_id']) {
            $linkMetrics['by_user'][$metrics['user_id']] =
                ($linkMetrics['by_user'][$metrics['user_id']] ?? 0) + 1;
        }

        $linkMetrics['total_response_time'] += $metrics['response_time'];
        $linkMetrics['avg_response_time'] =
            $linkMetrics['total_response_time'] / $linkMetrics['total_operations'];

        Cache::put($key, $linkMetrics, 3600);
    }

    /**
     * Verifica e registra violações específicas de links
     */
    protected function checkAndLogLinkViolation($userId, $ipAddress, $endpoint, $userAgent)
    {
        $identifier = $userId ?: $ipAddress;
        $prefix = "rate_limit:user:{$userId}:link";
        $now = now();

        // Verificar violação por minuto
        $minuteKey = "{$prefix}:{$endpoint}:minute:" . $now->format('Y-m-d-H-i');
        $currentCount = Cache::get($minuteKey, 0);

        $limits = $this->endpointLimits[$endpoint];
        $limit = $limits['requests_per_minute'] ?? 60;

        if ($currentCount >= $limit) {
            // Registrar violação específica de link
            $violation = [
                'id' => uniqid(),
                'user_id' => $userId,
                'ip' => $ipAddress,
                'endpoint' => $endpoint,
                'violation_type' => 'link_minute_limit',
                'operation_type' => $this->getOperationType($endpoint),
                'limit' => $limit,
                'attempted' => $currentCount + 1,
                'requests_count' => $currentCount + 1,
                'timestamp' => $now->toISOString(),
                'last_request_at' => $now->toISOString(),
                'user_agent' => $userAgent
            ];

            // Salvar violação
            $violationsKey = 'rate_limit:violations:' . $now->format('Y-m-d');
            $violations = Cache::get($violationsKey, []);
            $violations[] = $violation;
            Cache::put($violationsKey, $violations, 86400);

            // Log da violação
            Log::warning('Link rate limit violation', $violation);
        }
    }

    /**
     * Determina o tipo de operação baseado no endpoint
     */
    protected function getOperationType($endpoint): string
    {
        $operations = [
            'link.redirect' => 'Redirecionamento Público de Link',
        ];

        return $operations[$endpoint] ?? 'Acesso Público a Link';
    }

    /**
     * Verifica todos os rate limits aplicáveis (método original mantido para compatibilidade)
     */
    protected function checkRateLimit(Request $request, $userId, $ipAddress, $endpoint, $userTier)
    {
        $checks = [];

        // 1. Rate limit por usuário (se autenticado)
        if ($userId) {
            $checks[] = $this->checkUserRateLimit($userId, $endpoint, $userTier);
        }

        // 2. Rate limit por IP
        $checks[] = $this->checkIpRateLimit($ipAddress, $endpoint);

        // 3. Rate limit específico do endpoint
        if ($endpoint && isset($this->endpointLimits[$endpoint])) {
            $checks[] = $this->checkEndpointRateLimit($userId ?: $ipAddress, $endpoint);
        }

        // 4. Rate limit global
        $checks[] = $this->checkGlobalRateLimit();

        // Verificar se algum limite foi excedido
        foreach ($checks as $check) {
            if ($check['exceeded']) {
                return $check;
            }
        }

        // Retornar o check mais restritivo (menor remaining)
        return collect($checks)->sortBy('remaining')->first();
    }

    /**
     * Rate limiting básico para endpoints administrativos
     */
    protected function checkBasicRateLimit($userId, $ipAddress)
    {
        $prefix = "rate_limit:admin:" . ($userId ?: md5($ipAddress));

        $checks = [
            'minute' => [
                'key' => "{$prefix}:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => 300, // 300 requests por minuto para admin
                'window' => 60
            ],
            'hour' => [
                'key' => "{$prefix}:hour:" . now()->format('Y-m-d-H'),
                'limit' => 10000, // 10k requests por hora para admin
                'window' => 3600
            ]
        ];

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Verifica rate limit por usuário baseado no tier
     */
    protected function checkUserRateLimit($userId, $endpoint, $userTier)
    {
        $limits = $this->tiers[$userTier] ?? $this->tiers['free'];
        $prefix = "rate_limit:user:{$userId}";

        // Verificar diferentes janelas de tempo
        $checks = [
            'minute' => [
                'key' => "{$prefix}:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => $limits['api_requests_per_minute'],
                'window' => 60
            ],
            'hour' => [
                'key' => "{$prefix}:hour:" . now()->format('Y-m-d-H'),
                'limit' => $limits['api_requests_per_hour'],
                'window' => 3600
            ]
        ];

        // Verificações específicas para criação de links
        if ($endpoint === 'link.create') {
            $checks['links_hour'] = [
                'key' => "{$prefix}:links:hour:" . now()->format('Y-m-d-H'),
                'limit' => $limits['links_per_hour'],
                'window' => 3600
            ];
            $checks['links_day'] = [
                'key' => "{$prefix}:links:day:" . now()->format('Y-m-d'),
                'limit' => $limits['links_per_day'],
                'window' => 86400
            ];
        }

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Verifica rate limit por IP
     */
    protected function checkIpRateLimit($ipAddress, $endpoint)
    {
        $prefix = "rate_limit:ip:" . md5($ipAddress);

        $checks = [
            'minute' => [
                'key' => "{$prefix}:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => 120, // 120 requests por minuto por IP
                'window' => 60
            ],
            'hour' => [
                'key' => "{$prefix}:hour:" . now()->format('Y-m-d-H'),
                'limit' => 2000, // 2000 requests por hora por IP
                'window' => 3600
            ]
        ];

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Verifica rate limit específico do endpoint
     */
    protected function checkEndpointRateLimit($identifier, $endpoint)
    {
        $limits = $this->endpointLimits[$endpoint];
        $prefix = "rate_limit:endpoint:{$endpoint}:" . md5($identifier);

        $checks = [];

        if (isset($limits['attempts_per_minute'])) {
            $checks['minute'] = [
                'key' => "{$prefix}:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => $limits['attempts_per_minute'],
                'window' => 60
            ];
        }

        if (isset($limits['attempts_per_hour'])) {
            $checks['hour'] = [
                'key' => "{$prefix}:hour:" . now()->format('Y-m-d-H'),
                'limit' => $limits['attempts_per_hour'],
                'window' => 3600
            ];
        }

        if (isset($limits['requests_per_minute'])) {
            $checks['minute'] = [
                'key' => "{$prefix}:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => $limits['requests_per_minute'],
                'window' => 60
            ];
        }

        if (isset($limits['requests_per_hour'])) {
            $checks['hour'] = [
                'key' => "{$prefix}:hour:" . now()->format('Y-m-d-H'),
                'limit' => $limits['requests_per_hour'],
                'window' => 3600
            ];
        }

        // Verificar burst limit
        if (isset($limits['burst_limit'])) {
            $checks['burst'] = [
                'key' => "{$prefix}:burst:" . floor(now()->timestamp / 10), // Janela de 10 segundos
                'limit' => $limits['burst_limit'],
                'window' => 10
            ];
        }

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Verifica rate limit global do sistema
     */
    protected function checkGlobalRateLimit()
    {
        $checks = [
            'global_minute' => [
                'key' => "rate_limit:global:minute:" . now()->format('Y-m-d-H-i'),
                'limit' => 10000, // 10k requests por minuto globalmente
                'window' => 60
            ]
        ];

        return $this->performRateLimitCheck($checks);
    }

    /**
     * Executa as verificações de rate limit
     */
    protected function performRateLimitCheck($checks)
    {
        foreach ($checks as $type => $check) {
            if ($check['limit'] === -1) {
                continue; // Ilimitado
            }

            $current = Cache::get($check['key'], 0);
            $remaining = max(0, $check['limit'] - $current);

            if ($current >= $check['limit']) {
                return [
                    'exceeded' => true,
                    'type' => $type,
                    'limit' => $check['limit'],
                    'remaining' => 0,
                    'reset_at' => now()->addSeconds($check['window'])->timestamp,
                    'retry_after' => $check['window']
                ];
            }

            // Incrementar contador
            Cache::put($check['key'], $current + 1, $check['window']);
        }

        // Se chegou aqui, nenhum limite foi excedido
        $firstCheck = reset($checks);
        $current = Cache::get($firstCheck['key'], 1);

        return [
            'exceeded' => false,
            'limit' => $firstCheck['limit'],
            'remaining' => max(0, $firstCheck['limit'] - $current),
            'reset_at' => now()->addSeconds($firstCheck['window'])->timestamp,
            'retry_after' => 0
        ];
    }

    /**
     * Obtém o ID do usuário da requisição
     */
    protected function getUserId(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();
            return $user ? $user->id : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Obtém o tier do usuário
     */
    protected function getUserTier($userId)
    {
        if (!$userId) {
            return 'free';
        }

        // Buscar tier do usuário no cache ou banco
        return Cache::remember("user_tier:{$userId}", 3600, function() use ($userId) {
            // Aqui você implementaria a lógica para determinar o tier do usuário
            // Por enquanto, retornando 'free' como padrão
            return 'free';
        });
    }

    /**
     * Registra métricas de uso
     */
    protected function logMetrics(Request $request, $userId, $ipAddress, $userAgent, $endpoint)
    {
        $responseTime = microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : $_SERVER['REQUEST_TIME_FLOAT']);

        $metrics = [
            'timestamp' => now()->toISOString(),
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'endpoint' => $endpoint,
            'method' => $request->method(),
            'path' => $request->path(),
            'response_time' => $responseTime,
        ];

        // Log para analytics
        Log::channel('metrics')->info('api_request', $metrics);

                // Salvar métricas em cache para dashboard
        $this->updateDashboardMetrics($metrics);

        // Salvar métricas específicas do usuário
        if ($userId) {
            $this->updateUserMetrics($userId, $metrics, $endpoint);
        }

        // Registrar violação se aplicável
        if ($endpoint && isset($this->endpointLimits[$endpoint])) {
            $this->checkAndLogViolation($userId, $ipAddress, $endpoint, $userAgent);
        }
    }

    /**
     * Atualiza métricas do dashboard
     */
    protected function updateDashboardMetrics($metrics)
    {
        $key = 'dashboard:metrics:' . now()->format('Y-m-d-H');

        $currentMetrics = Cache::get($key, [
            'total_requests' => 0,
            'unique_users' => [],
            'unique_ips' => [],
            'endpoints' => [],
            'avg_response_time' => 0,
        ]);

        $currentMetrics['total_requests']++;

        if ($metrics['user_id']) {
            $currentMetrics['unique_users'][$metrics['user_id']] = true;
        }

        $currentMetrics['unique_ips'][$metrics['ip_address']] = true;

        if ($metrics['endpoint']) {
            $currentMetrics['endpoints'][$metrics['endpoint']] =
                ($currentMetrics['endpoints'][$metrics['endpoint']] ?? 0) + 1;
        }

        // Calcular média de tempo de resposta
        $currentMetrics['avg_response_time'] =
            ($currentMetrics['avg_response_time'] + $metrics['response_time']) / 2;

        Cache::put($key, $currentMetrics, 3600);
    }

    /**
     * Atualiza métricas específicas do usuário
     */
    protected function updateUserMetrics($userId, $metrics, $endpoint)
    {
        $hour = now()->format('Y-m-d-H');
        $key = "user_metrics:{$userId}:{$hour}";

        $currentMetrics = Cache::get($key, [
            'api_requests' => 0,
            'link_clicks' => 0,
            'link_views' => 0,
            'avg_response_time' => 0,
            'unique_ips' => [],
            'endpoints' => []
        ]);

        $currentMetrics['api_requests']++;
        $currentMetrics['unique_ips'][$metrics['ip_address']] = true;
        $currentMetrics['endpoints'][$endpoint] = ($currentMetrics['endpoints'][$endpoint] ?? 0) + 1;

        // Contabilizar cliques e visualizações baseado no endpoint
        if ($endpoint === 'link.redirect') {
            $currentMetrics['link_clicks']++;
        } elseif ($endpoint === 'link.preview') {
            $currentMetrics['link_views']++;
        }

        // Calcular média de tempo de resposta
        $currentMetrics['avg_response_time'] =
            ($currentMetrics['avg_response_time'] + $metrics['response_time']) / 2;

        Cache::put($key, $currentMetrics, 3600);
    }

    /**
     * Verifica e registra violações de rate limit
     */
    protected function checkAndLogViolation($userId, $ipAddress, $endpoint, $userAgent)
    {
        $identifier = $userId ?: $ipAddress;
        $prefix = "rate_limit:endpoint:{$endpoint}:" . md5($identifier);
        $now = now();

        // Verificar se houve violação recente
        $minuteKey = "{$prefix}:minute:" . $now->format('Y-m-d-H-i');
        $currentCount = Cache::get($minuteKey, 0);

        $limits = $this->endpointLimits[$endpoint];
        $limit = $limits['requests_per_minute'] ?? $limits['attempts_per_minute'] ?? 60;

        if ($currentCount >= $limit) {
            // Registrar violação
            $violation = [
                'id' => uniqid(),
                'user_id' => $userId,
                'ip' => $ipAddress,
                'endpoint' => $endpoint,
                'violation_type' => 'endpoint_minute_limit',
                'limit' => $limit,
                'attempted' => $currentCount + 1,
                'requests_count' => $currentCount + 1,
                'timestamp' => $now->toISOString(),
                'last_request_at' => $now->toISOString(),
                'user_agent' => $userAgent
            ];

            // Salvar violação em cache (para dashboard)
            $violationsKey = 'rate_limit:violations:' . $now->format('Y-m-d');
            $violations = Cache::get($violationsKey, []);
            $violations[] = $violation;
            Cache::put($violationsKey, $violations, 86400); // 24 horas

            // Log da violação
            Log::warning('Rate limit violation', $violation);
        }
    }

    /**
     * Resposta quando rate limit é excedido
     */
    protected function rateLimitExceededResponse($rateLimitResult)
    {
        return response()->json([
            'error' => 'Rate limit exceeded',
            'message' => 'Muitas tentativas. Tente novamente mais tarde.',
            'type' => $rateLimitResult['type'],
            'limit' => $rateLimitResult['limit'],
            'remaining' => $rateLimitResult['remaining'],
            'reset_at' => $rateLimitResult['reset_at'],
            'retry_after' => $rateLimitResult['retry_after']
        ], 429, [
            'X-RateLimit-Limit' => $rateLimitResult['limit'],
            'X-RateLimit-Remaining' => $rateLimitResult['remaining'],
            'X-RateLimit-Reset' => $rateLimitResult['reset_at'],
            'Retry-After' => $rateLimitResult['retry_after']
        ]);
    }

    /**
     * Adiciona headers de rate limit na resposta
     */
    protected function addRateLimitHeaders($response, $rateLimitResult)
    {
        $response->headers->set('X-RateLimit-Limit', $rateLimitResult['limit']);
        $response->headers->set('X-RateLimit-Remaining', $rateLimitResult['remaining']);
        $response->headers->set('X-RateLimit-Reset', $rateLimitResult['reset_at']);

        if ($rateLimitResult['exceeded']) {
            $response->headers->set('Retry-After', $rateLimitResult['retry_after']);
        }
    }
}
