<?php

namespace App\Http\Controllers\Subdomain;

use App\Logging\AppLogger;
use App\Models\Link;
use App\Models\UserSubdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manages subdomain claim (create/list/release) and availability check for authenticated users.
 *
 * All routes require auth:api + verified middleware (configured in routes/api.php).
 * Responses are wrapped by NormalizeApiResponse middleware — return raw data, not wrapped.
 *
 * Rate limiting for POST: throttle:subdomain-claim (5 claims/hour per user).
 *
 * Since the multi-subdomain support was added, a user may hold several active
 * subdomains simultaneously (up to `config('app.max_subdomains_per_user')`).
 * The plural endpoints (`index`/`store`/`destroy`/`check`, mounted at
 * `/api/subdomains`) are the only API surface — the legacy singular endpoints
 * (`show`/`claim`/`release`, mounted at `/api/subdomain`) were removed once
 * the frontend's last caller (`checkAvailability`) migrated to
 * `GET /api/subdomains/check`.
 */
class SubdomainController extends Controller
{
    /**
     * GET /api/subdomains/check?name=acme
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
     * GET /api/subdomains
     *
     * Lists every active subdomain owned by the authenticated user, oldest
     * first. Each entry carries `links_count` — how many of the user's links
     * currently point at that subdomain's hostname — so the UI can warn, on
     * release, that those links will revert to the default domain.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $subs = UserSubdomain::findAllActiveByUserCached($userId);

        // One grouped query for all counts instead of a COUNT per subdomain.
        $counts = Link::where('user_id', $userId)
            ->whereNotNull('short_domain')
            ->selectRaw('short_domain, COUNT(*) as total')
            ->groupBy('short_domain')
            ->pluck('total', 'short_domain');

        return response()->json([
            'data' => $subs
                ->map(fn (UserSubdomain $s) => $this->formatSubdomain($s, (int) ($counts[$this->hostnameFor($s)] ?? 0)))
                ->values(),
        ]);
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
     *
     * Links that pointed at the released hostname are migrated back to the
     * default domain (`short_domain = null`) in the same transaction —
     * otherwise their short URLs keep targeting a host the redirect now
     * blocks with `subdomain_not_found`. The migration saves each Link model
     * individually (never a mass UPDATE) so the slug-cache invalidation in
     * `Link::booted()` fires per link and `getShortUrl()` heals immediately.
     * Click history is untouched: clicks reference `link_id`, and the link
     * row (including the denormalized `clicks` counter) survives as-is.
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

        $hostname = $this->hostnameFor($sub);

        $migrated = DB::transaction(function () use ($request, $sub, $hostname): int {
            $sub->update(['status' => 'inactive']);

            $links = Link::where('user_id', $request->user()->id)
                ->where('short_domain', $hostname)
                ->get();

            $links->each(fn (Link $link) => $link->update(['short_domain' => null]));

            return $links->count();
        });

        if ($migrated > 0) {
            AppLogger::event('app', 'info', 'subdomain.released.links_migrated', [
                'subdomain_id' => $sub->id,
                'hostname' => $hostname,
                'links_migrated' => $migrated,
            ]);
        }

        return response()->json(null, 204);
    }

    /**
     * Validates and returns a candidate subdomain label from the request.
     *
     * Used by {@see self::store()} to enforce: lowercase alphanumeric +
     * hyphen format, length bounds, not on the reserved list, and not
     * already claimed by another active record.
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
     * Full hostname a subdomain serves, e.g. "acme.linkcharts.com.br" —
     * the exact value persisted in `links.short_domain`.
     */
    private function hostnameFor(UserSubdomain $sub): string
    {
        return $sub->subdomain.'.'.config('app.domain');
    }

    /**
     * Serialize a UserSubdomain to the API response shape.
     *
     * @param  int  $linksCount  How many of the owner's links point at this subdomain's hostname (0 for a fresh claim).
     * @return array{id: int, subdomain: string, full_url: string, status: string, links_count: int, created_at: string}
     */
    private function formatSubdomain(UserSubdomain $sub, int $linksCount = 0): array
    {
        $scheme = parse_url(config('app.redirect_url', 'http://localhost:8000'), PHP_URL_SCHEME) ?? 'https';

        return [
            'id' => $sub->id,
            'subdomain' => $sub->subdomain,
            'full_url' => "{$scheme}://{$this->hostnameFor($sub)}",
            'status' => $sub->status,
            'links_count' => $linksCount,
            'created_at' => $sub->created_at->toISOString(),
        ];
    }
}
