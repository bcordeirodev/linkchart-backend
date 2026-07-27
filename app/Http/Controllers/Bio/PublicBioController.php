<?php

namespace App\Http\Controllers\Bio;

use App\Contracts\Services\BioPageServiceInterface;
use App\Logging\AppLogger;
use App\Models\UserSubdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public (unauthenticated) controller for the "link-in-bio" module.
 *
 * Owns GET /api/public/bio/{handle} — the endpoint the frontend's public
 * bio page fetches directly. Never exposes user_id, subdomain_id, email, or
 * original_url; only handle/title/bio/theme/url and each active item's
 * id/label/short url (see BioPageServiceInterface::getPublicByHandle). `url`
 * is the computed shareable address — the associated subdomain's root when
 * one is set, otherwise a path-based fallback; see BioPageService::computeUrl().
 *
 * Also owns GET / (root, no slug) on subdomain hosts — see
 * {@see self::redirectFromSubdomainRoot()} — registered in routes/web.php,
 * not routes/api.php, since it competes with the plain root path.
 *
 * Middleware: throttle:public-bio (60/min per IP) on the API route — see
 * routes/api.php. The web root route has its own middleware, see routes/web.php.
 *
 * Depends on: BioPageServiceInterface (injected).
 */
class PublicBioController extends Controller
{
    protected BioPageServiceInterface $bioPageService;

    public function __construct(BioPageServiceInterface $bioPageService)
    {
        $this->bioPageService = $bioPageService;
    }

    /**
     * GET /api/public/bio/{handle}
     *
     * Return the public shape of an active bio page. 404 when the handle
     * does not exist, the page is inactive, or (implicitly, since a handle
     * only exists once a page is created) the user never created one.
     *
     * Middleware: throttle:public-bio
     * Auth: none
     *
     * Response shape: NormalizeApiResponse envelope:
     *   { data: { handle, title, bio, theme, url, items: [{ id, label, url }] } }
     *
     * @param  string  $handle  Bio page handle from the URL path (case-insensitive).
     */
    public function show(string $handle): JsonResponse
    {
        try {
            $page = $this->bioPageService->getPublicByHandle($handle);

            if (! $page) {
                return response()->json(['message' => 'Página não encontrada.'], 404);
            }

            return response()->json(['data' => $page]);
        } catch (\Exception $e) {
            AppLogger::event('app', 'error', 'bio.public_lookup_failed', [
                'handle' => $handle,
                'error' => $e->getMessage(),
            ]);

            $body = ['message' => 'Erro ao buscar página bio.'];
            if (config('app.debug')) {
                $body['detail'] = $e->getMessage();
            }

            return response()->json($body, 500);
        }
    }

    /**
     * GET / (root, no slug) — registered in routes/web.php on the SAME
     * literal path as the plain welcome route it replaces, since Laravel has
     * no per-request "fall through to the next matching route" mechanism;
     * this method reproduces that welcome payload byte-for-byte for every
     * case that isn't a subdomain-with-active-bio, so registering it here
     * instead of a raw closure changes nothing observable except the new case.
     *
     * Option B of the bio<->subdomain integration (Option A was the
     * `bio_pages.subdomain_id` data relationship + computed `url` field,
     * already live). This is the part that actually redirects:
     *
     *   - `resolve.subdomain` middleware (see routes/web.php) sets the
     *     `subdomain_context` request attribute: a UserSubdomain instance
     *     when the host is a registered, ACTIVE, non-reserved subdomain;
     *     null/false for the root domain, an unrelated host, a reserved
     *     system label (www/api/...), or an unregistered subdomain.
     *   - When it IS a UserSubdomain AND that specific subdomain has an
     *     active bio page associated with it (`bio_pages.subdomain_id` —
     *     NOT just any bio page owned by the same user) → 302 to
     *     `{app.frontend_url}/@{handle}`.
     *   - Every other case → the exact original welcome JSON, 200.
     *
     * Middleware: resolve.subdomain, throttle:redirect (see routes/web.php).
     * Auth: none.
     *
     * Slug routes (`/{slug}`, `/r/{slug}`) are untouched — this only takes
     * over the empty-path root, which never collided with a slug (a slug is
     * always a non-empty path segment).
     */
    public function redirectFromSubdomainRoot(Request $request): JsonResponse|RedirectResponse
    {
        $context = $request->attributes->get('subdomain_context');

        if ($context instanceof UserSubdomain) {
            $handle = $this->bioPageService->findActiveHandleBySubdomainId($context->id);

            if ($handle !== null) {
                $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

                return redirect()->away("{$frontendUrl}/@{$handle}");
            }
        }

        // Identical payload to the original closure route it replaces (see
        // routes/web.php history) — root domain, reserved subdomain,
        // unregistered subdomain, and subdomain-without-active-bio all land here.
        return response()->json([
            'message' => 'Link Charts API is running!',
            'version' => '1.0.0',
            'status' => 'active',
        ]);
    }
}
