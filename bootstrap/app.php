<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->job(new \App\Jobs\LinkHealthCheckJob)->hourly()->withoutOverlapping();
        $schedule->command('clicks:anonymize-ips')->dailyAt('04:10')->withoutOverlapping();
        $schedule->command('email-events:prune')->dailyAt('04:20')->withoutOverlapping();

        // Digest semanal de cliques: toda segunda 09:00 de São Paulo, com a
        // semana anterior fechada. Roda no worker (instância única) — o
        // blue/green da web nunca duplica o disparo.
        $schedule->job(new \App\Jobs\DispatchWeeklyDigestsJob)
            ->weeklyOn(1, '09:00')
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping();

        // Escada de marcos de cliques (10→1000): varredura diária às 10:00 de
        // São Paulo. Mesmo racional do digest (worker de instância única), só
        // que por link — o claim vive em links.milestone_last_threshold.
        $schedule->job(new \App\Jobs\DispatchMilestoneEmailsJob)
            ->dailyAt('10:00')
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping();

        // Winback dos links que fizeram duas semanas sem clique. 10:20 para
        // não colidir com a varredura de marcos — a janela do job é de 24h
        // exatas, então o horário fixo é o que garante cobertura sem lacuna.
        $schedule->job(new \App\Jobs\DispatchWinbackEmailsJob)
            ->dailyAt('10:20')
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping();

        // Dicas do terceiro dia de conta. 10:40 fecha o trio de varreduras de
        // retenção da manhã, cada uma com sua janela de 24h.
        $schedule->job(new \App\Jobs\DispatchOnboardingTipsJob)
            ->dailyAt('10:40')
            ->timezone('America/Sao_Paulo')
            ->withoutOverlapping();
    })
    ->withRouting(
        web: __DIR__.'/../routes/web.php', // Adicionado rotas web para redirecionamento
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // NOTE: withRouting(health: '/health') was removed on purpose. The
        // framework health route it registered was ALWAYS shadowed by the
        // hand-written GET /health in routes/web.php (same URI registered
        // later wins the route-collection lookup — `php artisan route:list`
        // resolved /health to routes/web.php). The web.php route is the live
        // one and serves the payload consumed by monitoring and deploy checks.
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
        $middleware->web([
            \App\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\AssignRequestId::class,
        ]);

        $middleware->api([
            \App\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\AssignRequestId::class,
            \App\Http\Middleware\NormalizeApiResponse::class,
        ]);

        // CORS: HandleCors is registered ONCE, here, as global middleware.
        // Global is the only registration that answers preflight OPTIONS
        // before routing, and it covers both the web and api groups — the
        // former duplicate copies inside those groups were removed (they made
        // HandleCors run twice per request). Path scoping ('api/*', 'r/*')
        // lives in config/cors.php.
        //
        // ⚠️ Behavior note: $middleware->use() REPLACES Laravel's default
        // global stack (TrimStrings, ConvertEmptyStringsToNull,
        // ValidatePostSize, PreventRequestsDuringMaintenance, ...), it does
        // not append to it. This app has always run with that reduced global
        // stack, so this registration is kept as `use()` deliberately —
        // switching to append() would silently re-enable input trimming and
        // empty-string-to-null conversion, a real behavior change that must
        // not ride along with a CORS cleanup.
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Append OtelTrace after AssignRequestId so request_id is already set
        // when the span is opened, and after the response is sent so the
        // batch flush does not add latency to the client.
        $middleware->web(append: [\App\Http\Middleware\OtelTrace::class]);
        $middleware->api(append: [\App\Http\Middleware\OtelTrace::class]);

        // Pyroscope continuous profiling (sampled) — terminable, so the profile
        // export runs after the response is flushed. Inert unless
        // PYROSCOPE_ENABLED=true (see config/pyroscope.php).
        $middleware->web(append: [\App\Http\Middleware\PyroscopeSampling::class]);
        $middleware->api(append: [\App\Http\Middleware\PyroscopeSampling::class]);

        $middleware->alias([
            'api.auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'metrics.redirect' => \App\Http\Middleware\RedirectMetricsCollector::class,
            'resolve.subdomain' => \App\Http\Middleware\ResolveSubdomainContext::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        // Este backend não tem página de login (SPA separado): o default do
        // Authenticate resolve route('login') EAGERMENTE ao lançar a
        // AuthenticationException — RouteNotFoundException viraria 500 antes
        // do renderer custom de 401 rodar (consumidores de API sem o header
        // Accept caem exatamente nesse caminho). Guest nunca redireciona.
        $middleware->redirectGuestsTo(fn () => null);

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
