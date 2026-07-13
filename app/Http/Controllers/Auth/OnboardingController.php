<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Persists "the user already saw this" onboarding markers on the user record.
 *
 * The guided tour used to remember dismissal in the browser's localStorage
 * alone, which is per-device: a new browser, a new machine or a private window
 * replayed the tour. Storing it on the account makes dismissal follow the user.
 */
class OnboardingController extends Controller
{
    /**
     * POST /api/onboarding/seen
     *
     * Marks an onboarding flag as dismissed for the authenticated user.
     * Idempotent — re-posting a flag already seen is a no-op and still 200s, so
     * the client can fire-and-forget without tracking whether it already sent it.
     *
     * Auth: JWT (email verification NOT required — the flag is harmless and we
     * want it recorded even if the user dismisses a tour before verifying).
     *
     * Request: { key: string }  — must be one of User::ONBOARDING_KEYS
     * Response: { data: { onboarding: { "<key>": "<iso8601>" } } } (200)
     *           422 when `key` is unknown.
     */
    public function seen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(User::ONBOARDING_KEYS)],
        ]);

        /** @var User $user */
        $user = auth()->user();
        $user->markOnboardingSeen($validated['key']);

        return response()->json([
            'data' => ['onboarding' => $user->onboarding],
        ]);
    }
}
