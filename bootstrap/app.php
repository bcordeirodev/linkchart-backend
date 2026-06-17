<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->job(new \App\Jobs\LinkHealthCheckJob)->hourly()->withoutOverlapping();
        $schedule->command('clicks:anonymize-ips')->dailyAt('04:10')->withoutOverlapping();
    })
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
                    'message' => 'A rota solicitada não foi encontrada nesta API',
                ], 404);
            });
        }
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 🌐 MIDDLEWARE GLOBAL: TrustProxies e CORS devem ser os primeiros
        $middleware->web([
            \App\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\AssignRequestId::class,
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->api([
            \App\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\AssignRequestId::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\NormalizeApiResponse::class,
        ]);

        // 🔧 CORS GLOBAL: Aplicar a todas as requisições para resolver problemas de desenvolvimento
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->alias([
            'api.auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'metrics.redirect' => \App\Http\Middleware\RedirectMetricsCollector::class,
            'resolve.subdomain' => \App\Http\Middleware\ResolveSubdomainContext::class,
        ]);

        // NOTA: Rota /r/* configurada em web.php com middlewares específicos
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Autenticação ausente/invalida
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->isApiRequest()) {
                return response()->json(['error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Token de autenticação não fornecido ou inválido.',
                ]], 401);
            }
        });

        // JWT malformado/expirado
        $exceptions->render(function (\Tymon\JWTAuth\Exceptions\JWTException $e, $request) {
            if ($request->isApiRequest()) {
                \App\Logging\AppLogger::authJwtError($e->getMessage(), $request->fullUrl());

                return response()->json(['error' => [
                    'code' => 'JWT_INVALID',
                    'message' => 'Token JWT inválido ou expirado.',
                ]], 401);
            }
        });

        // Validação FormRequest
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->isApiRequest()) {
                return response()->json(['error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => 'Dados inválidos fornecidos.',
                    'details' => ['fields' => $e->errors()],
                ]], 422);
            }
        });

        // Rate limit excedido
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException $e, $request) {
            if ($request->isApiRequest()) {
                return response()->json(['error' => [
                    'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Muitas requisições. Tente novamente em instantes.',
                ]], 429);
            }
        });

        // Recurso não encontrado (route/model binding)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->isApiRequest()) {
                return response()->json(['error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Recurso não encontrado.',
                ]], 404);
            }
        });

        // Método HTTP não permitido
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, $request) {
            if ($request->isApiRequest()) {
                return response()->json(['error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => 'Método HTTP não permitido para este endpoint.',
                ]], 405);
            }
        });

        // Fallback — qualquer exceção não tratada acima
        $exceptions->render(function (\Throwable $e, $request) {
            if (! $request->isApiRequest()) {
                return null;
            }

            $errorId = uniqid('err_');

            try {
                \App\Logging\AppLogger::httpServerError(
                    $request->path(),
                    $e,
                    optional($request->user())->id ?? null
                );
            } catch (\Throwable $logError) {
                error_log('Laravel Exception: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
            }

            // config(), not env(): env() returns null under config:cache in prod.
            $isDebug = (bool) config('app.debug');

            $error = [
                'code' => 'SERVER_ERROR',
                'message' => $isDebug ? $e->getMessage() : 'Erro interno do servidor.',
                'details' => [
                    'error_id' => $errorId,
                    ...($isDebug ? [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'type' => get_class($e),
                    ] : []),
                ],
            ];

            return response()->json(['error' => $error], 500);
        });
    })->create();
