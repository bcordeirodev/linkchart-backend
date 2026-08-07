<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Defesa estrutural: NENHUMA rota api/admin/* pode existir sem a cadeia
 * COMPLETA de middleware (auth do guard certo + verified + admin +
 * throttle). É o único jeito barato de impedir que uma rota adicionada fora
 * do grupo no futuro vaze para qualquer usuário logado — ou pior, para um
 * guard diferente do 'api' (ex.: uma rota registrada com 'auth:sanctum' em
 * vez de 'api.auth:api' passaria por 'admin' de qualquer jeito, já que
 * EnsureUserIsAdmin lê explicitamente o guard 'api').
 *
 * Checar só 'admin' não bastaria: uma rota ['auth:sanctum', 'admin'] teria
 * o middleware certo no nome mas nunca autenticaria via JWT — e antes do
 * pin de guard em EnsureUserIsAdmin, um token Sanctum de admin teria
 * alcançado dados admin por essa via.
 */
class AdminRouteProtectionTest extends TestCase
{
    /** Cadeia completa exigida em toda rota api/admin/*. */
    private const REQUIRED_MIDDLEWARE = ['api.auth:api', 'verified', 'admin', 'throttle:admin'];

    public function test_every_admin_route_has_the_full_required_middleware_chain(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin'));

        $this->assertGreaterThan(0, $adminRoutes->count(), 'Nenhuma rota api/admin/* registrada.');

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            foreach (self::REQUIRED_MIDDLEWARE as $required) {
                $this->assertContains(
                    $required,
                    $middleware,
                    "Rota {$route->uri()} registrada SEM o middleware '{$required}'."
                );
            }
        }
    }
}
