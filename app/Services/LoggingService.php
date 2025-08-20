<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

/**
 * Serviço de Logging Estruturado
 *
 * Centraliza todo o logging do sistema com contexto estruturado,
 * seguindo o princípio SRP e facilitando monitoramento.
 */
class LoggingService
{
    // Níveis de log
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';

    // Categorias de eventos
    const CATEGORY_AUTH = 'auth';
    const CATEGORY_LINK = 'link';
    const CATEGORY_CACHE = 'cache';
    const CATEGORY_API = 'api';
    const CATEGORY_SECURITY = 'security';
    const CATEGORY_PERFORMANCE = 'performance';

    /**
     * Log de autenticação.
     */
    public function logAuth(string $event, array $context = [], string $level = self::LEVEL_INFO): void
    {
        $this->log($level, $event, array_merge($context, [
            'category' => self::CATEGORY_AUTH,
            'user_id' => auth()->guard('api')->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]));
    }

    /**
     * Log de operações de link.
     */
    public function logLink(string $event, array $context = [], string $level = self::LEVEL_INFO): void
    {
        $this->log($level, $event, array_merge($context, [
            'category' => self::CATEGORY_LINK,
            'user_id' => auth()->guard('api')->id(),
        ]));
    }

    /**
     * Log de operações de cache.
     */
    public function logCache(string $event, array $context = [], string $level = self::LEVEL_DEBUG): void
    {
        $this->log($level, $event, array_merge($context, [
            'category' => self::CATEGORY_CACHE,
        ]));
    }

    /**
     * Log de requisições API.
     */
    public function logApiRequest(Request $request, $response = null, float $duration = null): void
    {
        $context = [
            'category' => self::CATEGORY_API,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth()->guard('api')->id(),
        ];

        if ($response) {
            $context['status_code'] = $response->getStatusCode();
        }

        if ($duration !== null) {
            $context['duration_ms'] = round($duration * 1000, 2);
        }

        $this->log(self::LEVEL_INFO, 'API Request', $context);
    }

    /**
     * Log de eventos de segurança.
     */
    public function logSecurity(string $event, array $context = [], string $level = self::LEVEL_WARNING): void
    {
        $this->log($level, $event, array_merge($context, [
            'category' => self::CATEGORY_SECURITY,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'user_id' => auth()->guard('api')->id(),
        ]));
    }

    /**
     * Log de performance.
     */
    public function logPerformance(string $event, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $event, array_merge($context, [
            'category' => self::CATEGORY_PERFORMANCE,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]));
    }

    /**
     * Log de erro com stack trace.
     */
    public function logError(\Throwable $exception, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $exception->getMessage(), array_merge($context, [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'user_id' => auth()->guard('api')->id(),
            'ip' => request()->ip(),
        ]));
    }

    /**
     * Log de tentativa de acesso não autorizado.
     */
    public function logUnauthorizedAccess(string $resource, array $context = []): void
    {
        $this->logSecurity('Unauthorized access attempt', array_merge($context, [
            'resource' => $resource,
            'attempted_user_id' => auth()->guard('api')->id(),
        ]), self::LEVEL_WARNING);
    }

    /**
     * Log de link suspeito ou malicioso.
     */
    public function logSuspiciousLink(string $url, string $reason, array $context = []): void
    {
        $this->logSecurity('Suspicious link detected', array_merge($context, [
            'url' => $url,
            'reason' => $reason,
        ]), self::LEVEL_WARNING);
    }

    /**
     * Log de rate limiting.
     */
    public function logRateLimit(string $identifier, int $attempts, array $context = []): void
    {
        $this->logSecurity('Rate limit exceeded', array_merge($context, [
            'identifier' => $identifier,
            'attempts' => $attempts,
        ]), self::LEVEL_WARNING);
    }

    /**
     * Log de operação de cache com métricas.
     */
    public function logCacheOperation(string $operation, string $key, bool $hit = null, float $duration = null): void
    {
        $context = [
            'operation' => $operation,
            'key' => $key,
        ];

        if ($hit !== null) {
            $context['cache_hit'] = $hit;
        }

        if ($duration !== null) {
            $context['duration_ms'] = round($duration * 1000, 2);
        }

        $this->logCache('Cache operation', $context);
    }

    /**
     * Log estruturado base.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Adiciona timestamp e request ID para rastreamento
        $context = array_merge([
            'timestamp' => now()->toISOString(),
            'request_id' => request()->header('X-Request-ID', uniqid()),
            'environment' => app()->environment(),
        ], $context);

        // Remove dados sensíveis
        $context = $this->sanitizeContext($context);

        Log::log($level, $message, $context);
    }

    /**
     * Remove dados sensíveis do contexto.
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'authorization'];

        foreach ($context as $key => $value) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }

    /**
     * Cria um contexto padrão para logs.
     */
    public function createContext(array $additional = []): array
    {
        return array_merge([
            'user_id' => auth()->guard('api')->id(),
            'ip' => request()->ip(),
            'timestamp' => now()->toISOString(),
        ], $additional);
    }
}
