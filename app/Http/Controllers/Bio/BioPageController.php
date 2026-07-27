<?php

namespace App\Http\Controllers\Bio;

use App\Contracts\Services\BioPageServiceInterface;
use App\Http\Requests\Bio\CreateBioPageItemRequest;
use App\Http\Requests\Bio\ReorderBioPageItemsRequest;
use App\Http\Requests\Bio\UpdateBioPageItemRequest;
use App\Http\Requests\Bio\UpsertBioPageRequest;
use App\Logging\AppLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Authenticated management controller for the "link-in-bio" module.
 *
 * Owns the /api/bio/* route family (all under api.auth:api + verified
 * middleware, see routes/api.php). A bio page is a private per-user
 * resource: page-level actions (show/upsert/handleAvailable) are scoped to
 * `$request->user()->id` directly — there is no numeric bio-page id in any
 * of these URLs, since the MVP allows exactly one page per user. Item-level
 * actions (storeItem/updateItem/destroyItem/reorderItems) take a numeric
 * item id but still resolve ownership through the user's page, never a
 * direct id-to-user check — see BioPageService for the ownership scoping.
 *
 * Depends on: BioPageServiceInterface (injected).
 */
class BioPageController extends Controller
{
    protected BioPageServiceInterface $bioPageService;

    public function __construct(BioPageServiceInterface $bioPageService)
    {
        $this->bioPageService = $bioPageService;
    }

    /**
     * GET /api/bio
     *
     * Return the authenticated user's bio page, or null if they haven't
     * created one yet.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     *
     * Response shape: NormalizeApiResponse envelope: { data: BioPage|null }
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $page = $this->bioPageService->getForUser($request->user()->id);

            // Explicit ['data' => null] (rather than response()->json(null)) so
            // NormalizeApiResponse's array_key_exists('data', ...) branch keeps
            // the null value — mirrors SubdomainController::show().
            return response()->json(['data' => $page]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar página bio.', $e);
        }
    }

    /**
     * PUT /api/bio
     *
     * Create the authenticated user's bio page on first call, update it on
     * every subsequent call (upsert — `bio_pages.user_id` is unique, so this
     * never inserts a second row for the same user).
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     *
     * Body: { handle, title, bio?, theme?, is_active?, subdomain_id? } — see
     * UpsertBioPageRequest. `subdomain_id` absent keeps the current
     * association, `null` removes it, an int must be an active subdomain
     * owned by the caller (422 otherwise — mirrors LinkService::resolveShortDomain).
     * Response shape: NormalizeApiResponse envelope: { data: BioPage } — includes
     * the computed `url` and, in this management shape, `subdomain_id`.
     *
     * @throws \Illuminate\Validation\ValidationException (handled by UpsertBioPageRequest)
     */
    public function upsert(UpsertBioPageRequest $request): JsonResponse
    {
        try {
            $page = $this->bioPageService->upsert($request->user()->id, $request->validated());

            return response()->json(['data' => $page]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao salvar página bio.', $e);
        }
    }

    /**
     * GET /api/bio/handle-available?handle=x
     *
     * Whether `handle` could be saved by the authenticated user right now.
     * The user's own current handle always counts as available (re-saving
     * an unchanged handle must never report itself as taken).
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Rate limit: throttle:bio-handle-check (30/min per user).
     *
     * Response shape: NormalizeApiResponse envelope: { data: { available: bool } }
     */
    public function handleAvailable(Request $request): JsonResponse
    {
        try {
            $handle = strtolower(trim((string) $request->query('handle', '')));
            $available = $this->bioPageService->isHandleAvailable($request->user()->id, $handle);

            return response()->json(['data' => ['available' => $available]]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao verificar identificador.', $e);
        }
    }

    /**
     * POST /api/bio/items
     *
     * Append a new button to the authenticated user's bio page, pointing at
     * one of their own existing shortened links.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — `link_id` must belong to the authenticated user
     * (enforced in BioPageService::addItem, surfaced here as 422).
     *
     * Body: { link_id, label? } — see CreateBioPageItemRequest.
     * Response shape: NormalizeApiResponse envelope: { data: BioPageItem } (201)
     *
     * @throws \Illuminate\Validation\ValidationException (handled by CreateBioPageItemRequest)
     */
    public function storeItem(CreateBioPageItemRequest $request): JsonResponse
    {
        try {
            $item = $this->bioPageService->addItem($request->user()->id, $request->validated());

            return response()->json(['data' => $item], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao adicionar item à página bio.', $e);
        }
    }

    /**
     * PUT /api/bio/items/{id}
     *
     * Update a button on the authenticated user's bio page.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — the item's bio page must belong to the authenticated user.
     *
     * Body: { label?, is_active? } — see UpdateBioPageItemRequest.
     * Response shape: NormalizeApiResponse envelope: { data: BioPageItem }
     *
     * @param  int  $id  Numeric bio page item ID.
     *
     * @throws \Illuminate\Validation\ValidationException (handled by UpdateBioPageItemRequest)
     */
    public function updateItem(UpdateBioPageItemRequest $request, int $id): JsonResponse
    {
        try {
            $item = $this->bioPageService->updateItem($request->user()->id, $id, $request->validated());

            if (! $item) {
                return response()->json(['message' => 'Item não encontrado ou sem permissão.'], 404);
            }

            return response()->json(['data' => $item]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao atualizar item da página bio.', $e);
        }
    }

    /**
     * DELETE /api/bio/items/{id}
     *
     * Permanently remove a button from the authenticated user's bio page.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — the item's bio page must belong to the authenticated user.
     *
     * Response shape: NormalizeApiResponse envelope: { data: { deleted: true } }
     *
     * @param  int  $id  Numeric bio page item ID.
     */
    public function destroyItem(Request $request, int $id): JsonResponse
    {
        try {
            $deleted = $this->bioPageService->deleteItem($request->user()->id, $id);

            if (! $deleted) {
                return response()->json(['message' => 'Item não encontrado ou sem permissão.'], 404);
            }

            return response()->json(['data' => ['deleted' => true]]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao remover item da página bio.', $e);
        }
    }

    /**
     * PUT /api/bio/items-order
     *
     * Reorder every button on the authenticated user's bio page. `ids` must
     * contain exactly the set of item ids currently on the page.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     *
     * Body: { ids: number[] } — see ReorderBioPageItemsRequest.
     * Response shape: NormalizeApiResponse envelope: { data: BioPage }
     *
     * @throws \Illuminate\Validation\ValidationException (handled by ReorderBioPageItemsRequest)
     */
    public function reorderItems(ReorderBioPageItemsRequest $request): JsonResponse
    {
        try {
            $page = $this->bioPageService->reorderItems($request->user()->id, $request->validated()['ids']);

            return response()->json(['data' => $page]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao reordenar itens da página bio.', $e);
        }
    }

    /**
     * Log an unexpected exception and return a standardised 500 JSON response.
     *
     * Duplicated (rather than inherited from BaseController) because
     * BaseController is Link-ownership-specific (findOwnedLink/linkNotFound)
     * and this controller has no link-ownership concept of its own.
     *
     * @param  string  $message  Human-readable description of the operation that failed.
     * @param  \Throwable  $e  The caught exception.
     * @return JsonResponse 500 with $message (and 'detail' in debug mode).
     */
    private function serverError(string $message, \Throwable $e): JsonResponse
    {
        AppLogger::httpServerError($message, $e, optional(request()?->user())->id ?? null);

        $body = ['message' => $message];
        if (config('app.debug')) {
            $body['detail'] = $e->getMessage();
        }

        return response()->json($body, 500);
    }
}
