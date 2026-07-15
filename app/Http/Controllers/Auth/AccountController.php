<?php

namespace App\Http\Controllers\Auth;

use App\Logging\AppLogger;
use App\Models\UserSubdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Permanent account deletion (LGPD: direito à eliminação).
 *
 * Confirmation strategy depends on how the account authenticates: a local
 * account (has a password) confirms via its current password; an Auth0-only
 * account (`password === null`) confirms by typing its own email address,
 * since it has no password to check. Reachable without a verified email —
 * an unverified user must still be able to delete their own account.
 */
class AccountController extends Controller
{
    /**
     * DELETE /api/account
     *
     * Validates the confirmation appropriate to the account's auth method,
     * then permanently deletes the authenticated user and all of their data
     * (links, clicks, subdomain claims) inside a single transaction.
     *
     * Deletion goes through Eloquent (not a raw cascading DB delete) so that
     * model-level `deleted` events fire — these invalidate
     * `Link::findActiveBySlugCached()` and `UserSubdomain` caches. Skipping
     * that would leave the public redirect hot path serving a deleted link
     * from cache for up to 10 minutes after the account is gone.
     *
     * Middleware: api.auth:api (no `verified`)
     * Auth: required (JWT)
     * Owner check: n/a — always operates on the authenticated user's own record
     *
     * Body (local account): { password: string }
     * Body (Auth0 account, password === null): { confirmation: string } — must equal the user's email
     *
     * Response shape: 204 No Content on success
     *                  { error: { code: 'INVALID_PASSWORD', message } } (422) — local account, wrong password
     *                  { error: { code: 'INVALID_CONFIRMATION', message } } (422) — Auth0 account, confirmation mismatch
     */
    public function destroy(Request $request): JsonResponse|Response
    {
        $user = $request->user();

        if ($user->password !== null) {
            $request->validate(['password' => 'required|string']);

            if (! Hash::check($request->input('password'), $user->password)) {
                return response()->json(['error' => [
                    'code' => 'INVALID_PASSWORD',
                    'message' => 'Senha incorreta.',
                ]], 422);
            }
        } else {
            $request->validate(['confirmation' => 'required|string']);

            if ($request->input('confirmation') !== $user->email) {
                return response()->json(['error' => [
                    'code' => 'INVALID_CONFIRMATION',
                    'message' => 'A confirmação não confere com o email da conta.',
                ]], 422);
            }
        }

        $userId = $user->id;
        $email = $user->email;
        $authProvider = $user->auth0_sub !== null ? 'auth0' : 'local';

        DB::transaction(function () use ($user): void {
            // Delete via Eloquent (not the DB-level FK cascade) so `deleted`
            // events fire and invalidate the corresponding caches.
            $user->links()->get()->each->delete();

            // subdomain() is hasOne, but that only reflects the current DB
            // constraint (one active row per user); query directly so every
            // row for this user is removed regardless of status.
            UserSubdomain::where('user_id', $user->id)->get()->each->delete();

            $user->delete();
        });

        AppLogger::event('audit', 'notice', 'account.deleted', [
            'user_id' => $userId,
            'email' => $email,
            'auth_provider' => $authProvider,
        ]);

        return response()->noContent();
    }
}
