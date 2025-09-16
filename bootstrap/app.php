<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php', // Adicionado rotas web para redirecionamento
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/health', // Health check customizado
        then: function () {
            // Fallback apenas para rotas não encontradas
            Route::fallback(function () {
                return response()->json([
                    'error' => 'Not Found',
                    'message' => 'A rota solicitada não foi encontrada nesta API'
                ], 404);
            });
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 🌐 MIDDLEWARE GLOBAL: TrustProxies e CORS devem ser os primeiros
        $middleware->web([
            \App\Http\Middleware\TrustProxies::class,
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->api([
            \App\Http\Middleware\TrustProxies::class,
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // 🔧 CORS GLOBAL: Aplicar a todas as requisições para resolver problemas de desenvolvimento
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->alias([
            'api.auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'metrics.collector' => \App\Http\Middleware\MetricsCollector::class,
            'metrics.redirect' => \App\Http\Middleware\RedirectMetricsCollector::class,
        ]);

        // NOTA: Rota /r/* configurada em web.php com middlewares específicos
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Tratamento para erros de autenticação
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Token de autenticação não fornecido ou inválido'
                ], 401);
            }
        });

        // Tratamento para erros de JWT
        $exceptions->render(function (\Tymon\JWTAuth\Exceptions\JWTException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                \Log::channel('api_errors')->error('JWT Error', [
                    'message' => $e->getMessage(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);

                return response()->json([
                    'error' => 'JWT Error',
                    'message' => 'Erro na configuração do JWT: ' . $e->getMessage()
                ], 500);
            }
        });

        // Tratamento para erros de validação
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $e->errors()
                ], 422);
            }
        });

        // Tratamento geral para exceções não tratadas
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                // Log detalhado do erro (com try-catch para evitar falhas em cascata)
                try {
                    \Log::channel('api_errors')->error('API Exception', [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'url' => $request->fullUrl(),
                        'method' => $request->method(),
                        'parameters' => $request->all(),
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'trace' => $e->getTraceAsString()
                    ]);
                } catch (\Exception $logError) {
                    // Se falhar o log, usar error_log como fallback
                    error_log('Laravel Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                }

                // Resposta diferente para produção vs desenvolvimento
                // SAFE: Usar env() direto para evitar problema de container resolution
                $isDebug = (bool) env('APP_DEBUG', false);

                $responseData = $isDebug ? [
                    'error' => 'Server Error',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTrace()
                ] : [
                    'error' => 'Server Error',
                    'message' => 'Erro interno do servidor. Verifique os logs para mais detalhes.',
                    'error_id' => uniqid('err_')
                ];

                $response = response()->json($responseData, 500);

                // Laravel CORS padrão será aplicado automaticamente

                return $response;
            }
        });
    })->create();
