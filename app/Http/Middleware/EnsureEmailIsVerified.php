<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para garantir que o usuário tenha verificado seu email
 */
class EnsureEmailIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado',
                'type' => 'unauthenticated'
            ], 401);
        }

        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email não verificado. Verifique seu email para continuar.',
                'type' => 'email_not_verified',
                'email' => $user->email,
                'can_resend' => $user->canResendVerificationEmail(),
                'last_sent' => $user->email_verification_sent_at?->toISOString()
            ], 403);
        }

        return $next($request);
    }
}
