<?php

namespace App\Providers;

use App\Models\Observers\UserObserver;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\Repositories\LinkRepositoryInterface::class,
            \App\Repositories\LinkRepository::class
        );

        $this->app->bind(
            \App\Contracts\Services\LinkServiceInterface::class,
            \App\Services\Links\LinkService::class
        );

        $this->app->bind(
            \App\Contracts\Repositories\TagRepositoryInterface::class,
            \App\Repositories\TagRepository::class
        );

        $this->app->bind(
            \App\Contracts\Services\TagServiceInterface::class,
            \App\Services\Links\TagService::class
        );

        $this->app->bind(
            \App\Contracts\Services\BioPageServiceInterface::class,
            \App\Services\Bio\BioPageService::class
        );

        $this->app->bind(\App\Contracts\Analytics\DashboardAnalyticsInterface::class, \App\Services\Analytics\DashboardAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\GeographicAnalyticsInterface::class, \App\Services\Analytics\GeographicAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\TemporalAnalyticsInterface::class, \App\Services\Analytics\TemporalAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\AudienceAnalyticsInterface::class, \App\Services\Analytics\AudienceAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\InsightsAnalyticsInterface::class, \App\Services\Analytics\InsightsAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\LinkAnalyticsOrchestratorInterface::class, \App\Services\Analytics\LinkAnalyticsOrchestrator::class);
        $this->app->bind(\App\Contracts\Analytics\ReportsAnalyticsServiceInterface::class, \App\Services\Analytics\ReportsAnalyticsService::class);

        $this->app->bind(
            \App\Contracts\Admin\AdminStatsServiceInterface::class,
            \App\Services\Admin\AdminStatsService::class
        );

        // Singleton so the same profiler instance spans the PyroscopeSampling
        // middleware's handle() (start) and terminate() (flush/push).
        $this->app->singleton(\App\Profiling\PyroscopeProfiler::class);
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);

        Request::macro('isApiRequest', function (): bool {
            /** @var Request $this */
            return $this->expectsJson() || $this->is('api/*');
        });

        // Let the JWT guard read the token from the httpOnly `auth_token` cookie
        // (set by auth0Exchange) in addition to the Authorization header. This
        // lets browser (SPA) clients authenticate without a JS-readable token
        // — the canonical XSS-safe pattern — while native/API clients keep
        // using the Bearer header. `false`: the cookie holds a raw JWT, not a
        // Laravel-encrypted value (api routes don't run EncryptCookies).
        $this->app['tymon.jwt.parser']->addParser(
            (new \Tymon\JWTAuth\Http\Parser\Cookies(false))
                ->setKey(\App\Http\Controllers\Auth\AuthController::AUTH_COOKIE)
        );

        // Duas dimensões: 5/min por email contra brute-force numa conta, e
        // 20/min por IP contra password spraying (um IP testando uma senha em
        // muitos emails distintos — o limite por email nunca dispara nesse ataque).
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by('login-email:'.($request->input('email') ?: $request->ip())),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        // 10 encurtamentos/min por IP para a rota pública (sem auth).
        RateLimiter::for('public-shorten', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // 30 consultas/min por IP em analytics públicos — mitiga scraping
        // por enumeração de slugs no endpoint GET /api/public/analytics/{slug}.
        RateLimiter::for('public-analytics', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Limite permissivo para /r/{slug} — só previne flood por IP.
        RateLimiter::for('redirect', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });

        // 10 tentativas/min por IP+slug no unlock de link protegido por senha
        // (POST /r/{slug}/unlock) — contém brute-force da senha de um link sem
        // consumir o limite permissivo do redirect nem travar outros slugs
        // acessados pelo mesmo IP.
        RateLimiter::for('redirect-unlock', function (Request $request) {
            return Limit::perMinute(10)
                ->by('redirect-unlock:'.$request->ip().':'.(string) $request->route('slug'));
        });

        // 10 trocas/min por IP no endpoint de exchange Auth0 — previne abuso de token.
        RateLimiter::for('auth0-exchange', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // 5 reivindicações/hora por usuário — previne squatting de subdomínios.
        RateLimiter::for('subdomain-claim', function (Request $request) {
            return Limit::perHour(5)->by($request->user()?->id);
        });

        // 30 consultas/min por usuário para busca de metadados de URL arbitrária.
        // Debounced no frontend (~700 ms), portanto raramente atinge o limite em uso normal.
        RateLimiter::for('url-meta', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Sugestão de slug do shortener público. Faz um fetch externo de preview
        // (og:title) por request, então é throttled por IP para conter abuso —
        // o endpoint é público e não autenticado.
        RateLimiter::for('suggest-slug', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Reenvio de email de verificação. Há um cooldown de 2 min no model
        // (User::canResendVerificationEmail), mas sem limite de rota um atacante
        // autenticado poderia abusar da cota/reputação do provedor. Chaveado por
        // usuário (com fallback para IP) como defesa em profundidade.
        RateLimiter::for('resend-verification', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // 10 req/min por usuário nas rotas de gestão de API keys (/api/api-keys:
        // criar/listar/revogar). Chaveado pelo usuário do guard do painel (JWT),
        // com fallback para IP em requests não autenticadas (que o api.auth já
        // rejeita com 401, mas o limiter roda para toda a rota).
        RateLimiter::for('api-keys', function (Request $request) {
            return Limit::perMinute(10)->by('api-keys:'.($request->user('api')?->id ?: $request->ip()));
        });

        // 60 req/min por token Sanctum em toda a API pública /api/v1. Chave
        // preferencial = id do personal access token (revogar e recriar a key
        // zera o bucket de propósito); fallback para o id do usuário (ex.:
        // autenticação stateful sem token) e, por fim, IP quando anônimo.
        RateLimiter::for('public-api', function (Request $request) {
            $user = $request->user('sanctum');
            $token = $user?->currentAccessToken();

            $key = $token instanceof \Laravel\Sanctum\PersonalAccessToken
                ? 'token:'.$token->id
                : ($user?->id ? 'user:'.$user->id : 'ip:'.$request->ip());

            return Limit::perMinute(60)->by('public-api:'.$key);
        });

        // 30 checagens/min por usuário na disponibilidade de handle do bio —
        // o frontend consulta a cada tecla digitada (debounced), então o
        // limite é permissivo o bastante para uso normal e ainda contém abuso.
        RateLimiter::for('bio-handle-check', function (Request $request) {
            return Limit::perMinute(30)->by('bio-handle-check:'.($request->user('api')?->id ?: $request->ip()));
        });

        // 60 consultas/min por IP na página bio pública — mitiga scraping por
        // enumeração de handles, no mesmo espírito do limiter 'public-analytics'.
        RateLimiter::for('public-bio', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // 10 uploads/min por usuário no avatar da página bio — cada request
        // grava um arquivo em disco, então o limite é mais apertado que os
        // outros endpoints de leitura/gestão do módulo.
        RateLimiter::for('bio-avatar', function (Request $request) {
            return Limit::perMinute(10)->by('bio-avatar:'.($request->user('api')?->id ?: $request->ip()));
        });

        // 60 req/min por admin no painel /api/admin/* — read-only, navegação
        // normal fica muito abaixo; contém runaway de refetch do frontend.
        RateLimiter::for('admin', function (Request $request) {
            return Limit::perMinute(60)->by('admin:'.($request->user('api')?->id ?: $request->ip()));
        });

        // Docs OpenAPI (Scramble): a superfície admin fica fora da doc pública —
        // a estratégia de auth-doc do Scramble nem conhece o alias 'admin'.
        // Scramble é require-dev (ausente em produção com --no-dev); guardar
        // com class_exists evita um fatal no boot em produção.
        if (class_exists(\Dedoc\Scramble\Scramble::class)) {
            \Dedoc\Scramble\Scramble::routes(
                fn (\Illuminate\Routing\Route $route) => str_starts_with($route->uri, 'api/') && ! str_starts_with($route->uri, 'api/admin')
            );
        }
    }
}
