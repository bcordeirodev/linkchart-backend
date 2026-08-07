<?php

namespace App\Http\Middleware;

use App\Logging\AppLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate do módulo admin: exige users.is_admin = true.
 *
 * A flag é lida DO BANCO a cada request (ApiAuthenticate resolve o usuário
 * do banco) — nunca de claim do JWT: com TTL de 30 dias e sem lever de
 * revogação para contas Auth0, uma claim deixaria um admin demitido ativo
 * por até um mês. Revogar is_admin derruba o acesso na request seguinte.
 *
 * O 403 é RETORNADO como JsonResponse, nunca via abort()/exception: o
 * catch-all de bootstrap/app.php roda antes do handling padrão do framework
 * e converteria a exceção em 500 SERVER_ERROR (mesmo racional do
 * EnsureEmailIsVerified). O shape {error:{code,message}} já é o final —
 * o NormalizeApiResponse preserva error.code existente.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        if (! $user || ! $user->is_admin) {
            AppLogger::event('auth', 'warning', 'admin.access_denied', [
                'user_id' => $user?->id,
                'route' => $request->path(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Acesso negado.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
