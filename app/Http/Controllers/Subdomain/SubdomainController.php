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
 *
 * Since the multi-subdomain support was added, a user may hold several active
 * subdomains simultaneously (up to `config('app.max_subdomains_per_user')`).
 * The plural endpoints (`index`/`store`/`destroy`, mounted at `/api/subdomains`)
 * are the current API surface consumed by the frontend. The singular endpoints
 * (`show`/`claim`/`release`, mounted at `/api/subdomain`) are `@deprecated` —
 * kept for one release so an old frontend build still in production during a
 * blue/green deploy does not break — and are thin aliases: `claim()` delegates
 * fully to `store()` so both share identical limit/insert semantics.
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
     * @deprecated Use POST /api/subdomains instead. Kept for one release so an
     * old frontend build still in production during a blue/green deploy keeps
     * working. Delegates fully to {@see self::store()} — same per-user limit
     * and always-INSERT semantics (a user may hold several active
     * subdomains now, so the old "409 if already active" / "reuse the
     * inactive row via UPDATE" behavior no longer applies).
     *
     * @throws \Illuminate\Validation\ValidationException on invalid input.
     */
    public function claim(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    /**
     * GET /api/subdomains
     *
     * Lists every active subdomain owned by the authenticated user, oldest first.
     */
    public function index(Request $request): JsonResponse
    {
        $subs = UserSubdomain::findAllActiveByUserCached($request->user()->id);

        return response()->json(['data' => $subs->map(fn (UserSubdomain $s) => $this->formatSubdomain($s))->values()]);
    }

    /**
     * POST /api/subdomains
     *
     * Claims a new subdomain for the authenticated user. Always INSERTs a new
     * row — a released (inactive) subdomain is never reused/updated, since a
     * user may hold several active subdomains simultaneously. Rejects with
     * 422 SUBDOMAIN_LIMIT_REACHED once the user's active count reaches
     * `config('app.max_subdomains_per_user')`.
     *
     * @throws \Illuminate\Validation\ValidationException on invalid input.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $active = UserSubdomain::where('user_id', $userId)->where('status', 'active')->count();
        if ($active >= config('app.max_subdomains_per_user')) {
            return response()->json([
                'error' => [
                    'code' => 'SUBDOMAIN_LIMIT_REACHED',
                    'message' => 'Você atingiu o limite de subdomínios da sua conta.',
                ],
            ], 422);
        }

        $name = $this->validateSubdomainName($request);

        $sub = UserSubdomain::create([
            'user_id' => $userId,
            'subdomain' => $name,
            'status' => 'active',
        ]);

        return response()->json($this->formatSubdomain($sub), 201);
    }

    /**
     * DELETE /api/subdomains/{id}
     *
     * Releases (soft-deletes: status = inactive) a specific subdomain owned
     * by the authenticated user, leaving the user's other active subdomains
     * untouched. Returns 404 if the id does not exist, does not belong to the
     * authenticated user, or is already inactive.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $sub = UserSubdomain::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->first();

        if ($sub === null) {
            return response()->json([
                'error' => ['code' => 'SUBDOMAIN_NOT_FOUND', 'message' => 'Subdomínio não encontrado.'],
            ], 404);
        }

        $sub->update(['status' => 'inactive']);

        return response()->json(null, 204);
    }

    /**
     * Validates and returns a candidate subdomain label from the request.
     *
     * Shared by {@see self::store()} (and, transitively, the deprecated
     * {@see self::claim()}) so both entry points enforce the exact same rules:
     * lowercase alphanumeric + hyphen format, length bounds, not on the
     * reserved list, and not already claimed by another active record.
     *
     * @throws \Illuminate\Validation\ValidationException on invalid input.
     */
    private function validateSubdomainName(Request $request): string
    {
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

        return $validated['subdomain'];
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
     * `id` is required by the plural endpoints (`index`/`destroy` operate on
     * it) and is harmless additional data on the deprecated singular
     * `show`/`claim` responses.
     *
     * @return array{id: int, subdomain: string, full_url: string, status: string, created_at: string}
     */
    private function formatSubdomain(UserSubdomain $sub): array
    {
        $scheme = parse_url(config('app.redirect_url', 'http://localhost:8000'), PHP_URL_SCHEME) ?? 'https';

        return [
            'id' => $sub->id,
            'subdomain' => $sub->subdomain,
            'full_url' => "{$scheme}://{$sub->subdomain}.".config('app.domain'),
            'status' => $sub->status,
            'created_at' => $sub->created_at->toISOString(),
        ];
    }
}
