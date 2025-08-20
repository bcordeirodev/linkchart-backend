<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'api.auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'rate.limit.advanced' => \App\Http\Middleware\AdvancedRateLimit::class,
            'metrics.collector' => \App\Http\Middleware\MetricsCollector::class,
            'metrics.redirect' => \App\Http\Middleware\RedirectMetricsCollector::class,
        ]);

        // Aplicar apenas coleta de métricas globalmente para rotas API
        $middleware->api([
            \App\Http\Middleware\MetricsCollector::class, // Coletar métricas de todas as requisições
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Token de autenticação não fornecido ou inválido'
                ], 401);
            }
        });
    })->create();
