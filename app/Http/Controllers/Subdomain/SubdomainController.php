<?php

namespace App\Http\Controllers\Subdomain;

use App\Models\UserSubdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Manages subdomain claim, release, and availability check for authenticated users.
 *
 * All routes require auth:api + verified middleware (configured in routes/api.php).
 * Responses are wrapped by NormalizeApiResponse middleware — return raw data, not wrapped.
 *
 * Rate limiting for POST: throttle:subdomain-claim (5 claims/hour per user).
 */
class SubdomainController extends Controller
{
    /**
     * GET /api/subdomain
     *
     * Returns the authenticated user's active subdomain or null.
     *
     * Note: response()->json(null) produces {} in Laravel, which the NormalizeApiResponse
     * middleware wraps as {"data": []}. We explicitly pass ['data' => null] so the
     * middleware's array_key_exists('data') branch preserves the null value correctly.
     */
    public function show(Request $request): JsonResponse
    {
        $sub = UserSubdomain::findByUserCached($request->user()->id);

        if (! $sub) {
            return response()->json(['data' => null]);
        }

        return response()->json($this->formatSubdomain($sub));
    }

    /**
     * GET /api/subdomain/check?name=acme
     *
     * Returns whether the requested subdomain label is available to claim.
     * Only checks active records; inactive (released) subdomains are available.
     */
    public function check(Request $request): JsonResponse
    {
        $name = (string) $request->query('name', '');

        if (strlen($name) < 3 || strlen($name) > 63) {
            return response()->json(['available' => false]);
        }

        $taken = UserSubdomain::where('subdomain', $name)
            ->where('status', 'active')
            ->exists();

        return response()->json(['available' => ! $taken]);
    }

    /**
     * POST /api/subdomain
     *
     * Claim a subdomain for the authenticated user. The user must not already
     * have an active subdomain (409). If the user has an inactive (released)
     * row, it is updated to the new subdomain instead of inserting a new row
     * (because user_id has a UNIQUE constraint on the table).
     *
     * @throws \Illuminate\Validation\ValidationException on invalid input.
     */
    public function claim(Request $request): JsonResponse
    {
        $user = $request->user();

        $existing = UserSubdomain::where('user_id', $user->id)->first();
        if ($existing && $existing->status === 'active') {
            return response()->json([
                'error' => [
                    'code' => 'SUBDOMAIN_ALREADY_ACTIVE',
                    'message' => 'Você já possui um subdomínio ativo. Libere-o antes de reivindicar um novo.',
                ],
            ], 409);
        }

        $validated = $request->validate([
            'subdomain' => [
                'required', 'string', 'min:3', 'max:63',
                'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
                Rule::notIn(config('app.reserved_subdomains', [])),
                Rule::unique('user_subdomains', 'subdomain')->where('status', 'active'),
            ],
        ], [
            'subdomain.regex' => 'O subdomínio deve conter apenas letras minúsculas, números e hífens, e não pode começar ou terminar com hífen.',
            'subdomain.not_in' => 'Este subdomínio é reservado e não pode ser utilizado.',
            'subdomain.unique' => 'Este subdomínio já está em uso.',
        ]);

        $subdomain = $validated['subdomain'];

        if ($existing) {
            $existing->update(['subdomain' => $subdomain, 'status' => 'active']);
            $sub = $existing->fresh();
        } else {
            $sub = UserSubdomain::create([
                'user_id' => $user->id,
                'subdomain' => $subdomain,
                'status' => 'active',
            ]);
        }

        return response()->json($this->formatSubdomain($sub), 201);
    }

    /**
     * DELETE /api/subdomain
     *
     * Release the authenticated user's active subdomain. The record is soft-deleted
     * (status = inactive) so the label can be reclaimed by others. Returns 404 if
     * the user has no active subdomain.
     */
    public function release(Request $request): JsonResponse|Response
    {
        $sub = UserSubdomain::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        if (! $sub) {
            return response()->json([
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Nenhum subdomínio ativo encontrado.'],
            ], 404);
        }

        $sub->update(['status' => 'inactive']);

        return response()->noContent();
    }

    /**
     * Serialize a UserSubdomain to the API response shape.
     *
     * @return array{subdomain: string, full_url: string, status: string, created_at: string}
     */
    private function formatSubdomain(UserSubdomain $sub): array
    {
        $scheme = parse_url(config('app.url', 'http://localhost'), PHP_URL_SCHEME);
        return [
            'subdomain' => $sub->subdomain,
            'full_url' => "{$scheme}://{$sub->subdomain}." . config('app.domain'),
            'status' => $sub->status,
            'created_at' => $sub->created_at->toISOString(),
        ];
    }
}
