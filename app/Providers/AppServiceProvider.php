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

        $this->app->bind(\App\Contracts\Analytics\DashboardAnalyticsInterface::class, \App\Services\Analytics\DashboardAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\GeographicAnalyticsInterface::class, \App\Services\Analytics\GeographicAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\TemporalAnalyticsInterface::class, \App\Services\Analytics\TemporalAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\AudienceAnalyticsInterface::class, \App\Services\Analytics\AudienceAnalyticsService::class);
        $this->app->bind(\App\Contracts\Analytics\InsightsAnalyticsInterface::class, \App\Services\Analytics\InsightsAnalyticsService::class);
    }

    public function boot(): void
    {
        User::observe(UserObserver::class);

        Request::macro('isApiRequest', function (): bool {
            /** @var Request $this */
            return $this->expectsJson() || $this->is('api/*');
        });

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
    }
}
