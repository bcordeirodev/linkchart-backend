<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Handler customizado para capturar e registrar exceções da API
 */
class ApiExceptionHandler extends ExceptionHandler
{
    /**
     * Registra exceção e coleta métricas de erro
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $this->collectErrorMetrics($e);
        });
    }

    /**
     * Renderiza exceção para resposta HTTP
     */
    public function render($request, Throwable $e): Response
    {
        // Se for requisição API, registrar erro adicional
        if ($request->is('api/*') || $request->expectsJson()) {
            $this->collectApiError($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * Coleta métricas de erro da exceção
     */
    private function collectErrorMetrics(Throwable $e): void
    {
        try {
            $date = now()->format('Y-m-d');
            $hour = now()->format('Y-m-d-H');

            // Determinar código de status HTTP
            $statusCode = $this->getStatusCode($e);

            // Coletar dados da exceção
            $errorData = [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'status_code' => $statusCode,
                'timestamp' => now()->toISOString(),
                'trace_hash' => md5($e->getTraceAsString()) // Para agrupar erros similares
            ];

            // Atualizar contadores de erro por dia
            $dayKey = "errors:day:{$date}";
            $dayErrors = Cache::get($dayKey, [
                'total_errors' => 0,
                'by_status_code' => [],
                'by_exception_class' => [],
                'unique_errors' => []
            ]);

            $dayErrors['total_errors']++;
            $dayErrors['by_status_code'][$statusCode] =
                ($dayErrors['by_status_code'][$statusCode] ?? 0) + 1;
            $dayErrors['by_exception_class'][get_class($e)] =
                ($dayErrors['by_exception_class'][get_class($e)] ?? 0) + 1;

            // Agrupar erros únicos por hash do trace
            $traceHash = $errorData['trace_hash'];
            if (!isset($dayErrors['unique_errors'][$traceHash])) {
                $dayErrors['unique_errors'][$traceHash] = [
                    'first_occurrence' => now()->toISOString(),
                    'count' => 0,
                    'last_occurrence' => now()->toISOString(),
                    'exception_class' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
            }

            $dayErrors['unique_errors'][$traceHash]['count']++;
            $dayErrors['unique_errors'][$traceHash]['last_occurrence'] = now()->toISOString();

            Cache::put($dayKey, $dayErrors, 86400); // 24 horas

            // Atualizar contadores por hora
            $hourKey = "errors:hour:{$hour}";
            $hourErrors = Cache::get($hourKey, [
                'total_errors' => 0,
                'by_status_code' => []
            ]);

            $hourErrors['total_errors']++;
            $hourErrors['by_status_code'][$statusCode] =
                ($hourErrors['by_status_code'][$statusCode] ?? 0) + 1;

            Cache::put($hourKey, $hourErrors, 3600); // 1 hora

        } catch (\Exception $collectionError) {
            // Não deixar erro de coleta quebrar a aplicação
            Log::error('Failed to collect error metrics', [
                'original_error' => $e->getMessage(),
                'collection_error' => $collectionError->getMessage()
            ]);
        }
    }

    /**
     * Coleta erro específico da API
     */
    private function collectApiError(Request $request, Throwable $e): void
    {
        try {
            $endpoint = $this->identifyEndpoint($request);
            $userId = $this->getUserId($request);
            $statusCode = $this->getStatusCode($e);

            $errorData = [
                'endpoint' => $endpoint,
                'method' => $request->method(),
                'path' => $request->path(),
                'status_code' => $statusCode,
                'user_id' => $userId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'exception_class' => get_class($e),
                'error_message' => $e->getMessage(),
                'request_data' => $this->sanitizeRequestData($request),
                'timestamp' => now()->toISOString(),
            ];

            // Adicionar à lista de erros da API
            $date = now()->format('Y-m-d');
            $dayKey = "api_errors:day:{$date}";

            $apiErrors = Cache::get($dayKey, [
                'total_errors' => 0,
                'by_endpoint' => [],
                'by_user' => [],
                'errors' => []
            ]);

            $apiErrors['total_errors']++;
            $apiErrors['by_endpoint'][$endpoint] =
                ($apiErrors['by_endpoint'][$endpoint] ?? 0) + 1;

            if ($userId) {
                $apiErrors['by_user'][$userId] =
                    ($apiErrors['by_user'][$userId] ?? 0) + 1;
            }

            // Manter apenas os últimos 200 erros detalhados
            $apiErrors['errors'][] = $errorData;
            if (count($apiErrors['errors']) > 200) {
                $apiErrors['errors'] = array_slice($apiErrors['errors'], -200);
            }

            Cache::put($dayKey, $apiErrors, 86400); // 24 horas

        } catch (\Exception $collectionError) {
            Log::error('Failed to collect API error', [
                'original_error' => $e->getMessage(),
                'collection_error' => $collectionError->getMessage()
            ]);
        }
    }

    /**
     * Determina código de status HTTP da exceção
     */
    private function getStatusCode(Throwable $e): int
    {
        if (method_exists($e, 'getStatusCode')) {
            return $e->getStatusCode();
        }

        // Mapear exceções comuns para códigos HTTP
        $exceptionMap = [
            \Illuminate\Auth\AuthenticationException::class => 401,
            \Illuminate\Auth\Access\AuthorizationException::class => 403,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class => 404,
            \Illuminate\Validation\ValidationException::class => 422,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class => 404,
            \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class => 405,
            \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException::class => 429,
        ];

        $exceptionClass = get_class($e);
        return $exceptionMap[$exceptionClass] ?? 500;
    }

    /**
     * Identifica endpoint da requisição
     */
    private function identifyEndpoint(Request $request): string
    {
        $route = $request->route();
        if ($route && $route->getName()) {
            return $route->getName();
        }

        // Fallback para padrões de URL
        $path = $request->path();
        $method = $request->method();

        if (str_starts_with($path, 'api/auth/')) {
            return 'auth.' . str_replace('api/auth/', '', $path);
        }

        if (str_starts_with($path, 'api/link')) {
            return 'link.operation';
        }

        if (str_starts_with($path, 'api/r/')) {
            return 'link.redirect';
        }

        return $method . ':' . $path;
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
     * Sanitiza dados da requisição
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
