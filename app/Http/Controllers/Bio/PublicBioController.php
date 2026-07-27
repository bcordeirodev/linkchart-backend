<?php

namespace App\Http\Controllers\Bio;

use App\Contracts\Services\BioPageServiceInterface;
use App\Logging\AppLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Public (unauthenticated) controller for the "link-in-bio" module.
 *
 * Owns GET /api/public/bio/{handle} — the endpoint the frontend's public
 * bio page fetches directly. Never exposes user_id, email, or
 * original_url; only handle/title/bio/theme and each active item's
 * id/label/short url (see BioPageServiceInterface::getPublicByHandle).
 *
 * Middleware: throttle:public-bio (60/min per IP) — see routes/api.php.
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
     *   { data: { handle, title, bio, theme, items: [{ id, label, url }] } }
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
}
