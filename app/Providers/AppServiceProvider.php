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

        $this->app->bind(\App\Contracts\Analytics\DashboardAnalyticsInterface::class, \App\Services\Analytics\DashboardAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\GeographicAnalyticsInterface::class, \App\Services\Analytics\GeographicAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\TemporalAnalyticsInterface::class, \App\Services\Analytics\TemporalAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\AudienceAnalyticsInterface::class, \App\Services\Analytics\AudienceAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\InsightsAnalyticsInterface::class, \App\Services\Analytics\InsightsAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\LinkAnalyticsOrchestratorInterface::class, \App\Services\Analytics\LinkAnalyticsOrchestrator::class);
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

        // 5 tentativas/min por email (ou IP quando email ausente) para evitar
        // brute-force em login/reset e abuso de reenvio de verificação.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email') ?: $request->ip());
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
        // autenticado poderia abusar da cota/reputação do SendGrid. Chaveado por
        // usuário (com fallback para IP) como defesa em profundidade.
        RateLimiter::for('resend-verification', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
