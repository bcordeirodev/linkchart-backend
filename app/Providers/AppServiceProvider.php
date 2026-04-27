<?php

namespace App\Providers;

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
    }

    public function boot(): void
    {
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

        // Limite permissivo para /r/{slug} — só previne flood por IP.
        RateLimiter::for('redirect', function (Request $request) {
            return Limit::perMinute(600)->by($request->ip());
        });
    }
}
