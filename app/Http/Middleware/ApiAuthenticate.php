<?php

namespace App\Http\Middleware;

use App\Logging\AppLogger;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\JWTGuard;

/**
 * JWT authentication middleware for the API (`api.auth` alias).
 *
 * Beyond the standard guard check it enforces password-change invalidation:
 * every JWT carries the epoch of users.password_changed_at at issue time in
 * the custom `pwd_ts` claim (see User::getJWTCustomClaims). If the claim no
 * longer matches the current database value, the token predates a password
 * reset/change and is rejected with the same 401 shape as any other
 * unauthenticated request. This is what makes a stolen JWT die the moment the
 * victim changes their password.
 */
class ApiAuthenticate extends Middleware
{
    /**
     * Authenticate the request, then reject JWTs issued before the user's
     * last password change.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);

        $this->rejectTokensIssuedBeforePasswordChange($request, $guards);
    }

    /**
     * Compare the JWT's `pwd_ts` claim with the user's current
     * password_changed_at epoch; a mismatch means the token was issued before
     * a password reset/change and must be rejected.
     *
     * Both sides normalize "never changed" to 0: tokens minted before this
     * hardening (no claim at all) and users who never changed their password
     * (NULL column — including Auth0-only users) compare as 0 === 0 and stay
     * valid. Any token minted before a change carries the older epoch and
     * fails the comparison.
     *
     * Skipped when no parseable token is present on the request — the only
     * way to be authenticated without one is Laravel's actingAs() in tests,
     * which sets the user on the guard directly.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $guards  The guards the route authenticated against (for the 401 exception).
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    private function rejectTokensIssuedBeforePasswordChange($request, array $guards): void
    {
        $guard = $this->auth->guard();

        if (! $guard instanceof JWTGuard) {
            return;
        }

        try {
            $payload = $guard->getPayload();
        } catch (JWTException) {
            // Authenticated without a parsed token (actingAs in tests) —
            // there is no claim to compare.
            return;
        }

        $user = $guard->user();
        $tokenEpoch = (int) $payload->get('pwd_ts');
        $currentEpoch = $user->password_changed_at?->getTimestamp() ?? 0;

        if ($tokenEpoch !== $currentEpoch) {
            AppLogger::event('auth', 'warning', 'auth.jwt_rejected_password_changed', [
                'user_id' => $user->id,
                'url' => $request->fullUrl(),
            ]);

            throw new AuthenticationException(
                'Unauthenticated.', $guards, $this->redirectTo($request)
            );
        }
    }

    /**
     * Handle an unauthenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function unauthenticated($request, array $guards)
    {
        throw new AuthenticationException(
            'Unauthenticated.', $guards, $this->redirectTo($request)
        );
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // Para requisições de API, retorna null para que seja lançada uma exceção JSON
        if ($request->expectsJson() || $request->is('api/*')) {
            return null;
        }

        // SAFE: Para API pura, não usar route() helper que pode falhar durante bootstrap
        return '/login';
    }
}
