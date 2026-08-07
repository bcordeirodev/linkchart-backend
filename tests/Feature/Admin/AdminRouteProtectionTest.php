<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Defesa estrutural: NENHUMA rota api/admin/* pode existir sem o middleware
 * 'admin'. É o único jeito barato de impedir que uma rota adicionada fora do
 * grupo no futuro vaze para qualquer usuário logado.
 */
class AdminRouteProtectionTest extends TestCase
{
    public function test_every_admin_route_has_the_admin_middleware(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin'));

        $this->assertGreaterThan(0, $adminRoutes->count(), 'Nenhuma rota api/admin/* registrada.');

        foreach ($adminRoutes as $route) {
            $this->assertContains(
                'admin',
                $route->gatherMiddleware(),
                "Rota {$route->uri()} registrada SEM o middleware 'admin'."
            );
        }
    }
}
