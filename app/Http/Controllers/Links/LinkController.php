<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreateLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CreateLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Http\Resources\LinkResource;
use App\Jobs\FetchLinkPreviewJob;
use App\Services\Links\LinkAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RESTful controller for authenticated link management (CRUD).
 *
 * Owns the /api/links/* and /api/link/{id}/* route families. Extends
 * BaseController to inherit findOwnedLink, linkNotFound, and serverError helpers.
 *
 * Routes overview (all under api.auth:api + verified middleware):
 *   GET    /api/links                   → index
 *   POST   /api/links                   → store
 *   GET    /api/links/{id}              → show
 *   PUT    /api/links/{id}              → update
 *   DELETE /api/links/{id}              → destroy
 *   GET    /api/link/{id}/clicks-list   → getClicksList
 *
 * Cross-mount note: GET /api/links/{id}/analytics is defined in the same route
 * group but is handled by AnalyticsController::getLinkSummaryAnalytics, not by
 * this controller.
 *
 * Depends on: LinkServiceInterface (injected), LinkAuditService (injected).
 */
class LinkController extends BaseController
{
    protected LinkServiceInterface $linkService;

    protected LinkAuditService $auditService;

    public function __construct(
        LinkServiceInterface $linkService,
        LinkAuditService $auditService
    ) {
        $this->linkService = $linkService;
        $this->auditService = $auditService;
    }

    /**
     * GET /api/links
     *
     * Return all links belonging to the authenticated user, wrapped in a
     * LinkResource collection.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — LinkService filters by auth user id.
     *
     * Response shape: NormalizeApiResponse envelope: { data: LinkResource[] }
     */
    public function index(): JsonResponse
    {
        try {
            $links = $this->linkService->getAllUserLinks();

            return response()->json(LinkResource::collection($links));
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar links.', $e);
        }
    }

    /**
     * GET /api/links/{id}
     *
     * Return a single link that belongs to the authenticated user.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — LinkService::getUserLink enforces user_id match.
     *
     * Response shape: NormalizeApiResponse envelope: { data: LinkResource }
     *
     * @param  string  $id  Numeric link ID (enforced by route constraint [0-9]+).
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Validação adicional de ownership
            $userId = auth()->guard('api')->id();
            if (! $userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            $link = $this->linkService->getUserLink($id);

            if (! $link) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para acessá-lo.'], 404);
            }

            return response()->json([
                'data' => new LinkResource($link),
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar link.', $e);
        }
    }

    /**
     * POST /api/links
     *
     * Create a new shortened link for the authenticated user. Validates via
     * CreateLinkRequest, maps to CreateLinkDTO, and writes an audit record.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — link is always created under the auth user id.
     *
     * Response shape: NormalizeApiResponse envelope: { message, data: LinkResource } (201)
     *
     *
     * @throws \Illuminate\Validation\ValidationException (handled by CreateLinkRequest)
     */
    public function store(CreateLinkRequest $request): JsonResponse
    {
        try {
            $linkDTO = CreateLinkDTO::fromRequest($request);
            $link = $this->linkService->createLink($linkDTO);

            // Log da criação
            $this->auditService->logCreated($link, auth()->guard('api')->id(), $request);

            // Pre-warm the dashboard preview so the thumbnail is ready when the
            // user navigates to the links list immediately after creating a link.
            FetchLinkPreviewJob::dispatch($link->id, $link->original_url);

            return response()->json([
                'message' => 'Link criado com sucesso.',
                'data' => new LinkResource($link),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao criar link.', $e);
        }
    }

    /**
     * PUT /api/links/{id}
     *
     * Update an existing link owned by the authenticated user. Requires at least
     * one field in the payload. Saves an audit record with before/after values.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — verifies ownership before updating.
     *
     * Response shape: NormalizeApiResponse envelope: { message, data: LinkResource } (200)
     *
     * @param  string  $id  Numeric link ID (enforced by route constraint [0-9]+).
     *
     * @throws \Illuminate\Validation\ValidationException (handled by UpdateLinkRequest)
     */
    public function update(UpdateLinkRequest $request, string $id): JsonResponse
    {
        try {
            // Validação adicional de ownership
            $userId = auth()->guard('api')->id();
            if (! $userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            // Verifica se há dados para atualizar
            if (! $request->hasDataToUpdate()) {
                return response()->json([
                    'error' => 'Nenhum dado fornecido.',
                    'message' => 'Pelo menos um campo deve ser fornecido para atualização.',
                ], 422);
            }

            // Verifica se o link existe e pertence ao usuário antes de atualizar
            $existingLink = $this->linkService->getUserLink($id);
            if (! $existingLink) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para editá-lo.'], 404);
            }

            // Salva os valores antigos para auditoria
            $oldValues = $existingLink->toArray();

            $linkDTO = UpdateLinkDTO::fromRequest($request);
            $link = $this->linkService->updateLink($id, $linkDTO);

            if (! $link) {
                return response()->json(['message' => 'Erro ao atualizar link.'], 500);
            }

            // Log da atualização
            $this->auditService->logUpdated($link, $oldValues, $userId, $request);

            return response()->json([
                'message' => 'Link atualizado com sucesso.',
                'data' => new LinkResource($link),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao atualizar link.', $e);
        }
    }

    /**
     * DELETE /api/links/{id}
     *
     * Permanently delete a link owned by the authenticated user. Saves a
     * deletion audit record before the delete executes.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — verifies ownership before deleting.
     *
     * Response shape: NormalizeApiResponse envelope: { message } (200)
     *
     * @param  string  $id  Numeric link ID (enforced by route constraint [0-9]+).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            // Validação adicional de ownership
            $userId = auth()->guard('api')->id();
            if (! $userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            // Verifica se o link existe e pertence ao usuário antes de remover
            $existingLink = $this->linkService->getUserLink($id);
            if (! $existingLink) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para removê-lo.'], 404);
            }

            // Log da exclusão (antes de deletar)
            $this->auditService->logDeleted($existingLink, $userId, $request);

            $deleted = $this->linkService->deleteLink($id);

            if (! $deleted) {
                return response()->json(['message' => 'Erro ao remover link.'], 500);
            }

            return response()->json(['message' => 'Link removido com sucesso.']);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao remover link.', $e);
        }
    }

    /**
     * GET /api/link/{id}/clicks-list
     *
     * Return a paginated, sortable, and searchable list of individual click
     * records for use in the ClicksTable tab. Supports query parameters:
     *   - page (int, default 1)
     *   - per_page (int, 1–100, default 25)
     *   - search (string, matched against country/city/state/device/browser/os/ip/referer)
     *   - sort_by (string, one of created_at|country|city|state|device|browser|os|ip|referer)
     *   - sort_dir (asc|desc, default desc)
     *   - date_from (string, ISO datetime, filters created_at >=)
     *   - date_to (string, ISO datetime, filters created_at <=)
     *   - exclude_bots (bool, default false)
     *
     * Each item in data includes social_platform (nullable, added 2026-05-19)
     * and quality_tier (nullable, added Phase 3) so the frontend can render
     * them with appropriate NULL handling for older clicks.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — uses findOwnedLink.
     *
     * Response shape: { data: Click[], meta: { total, per_page, current_page, last_page,
     *                   from, to, sort_by, sort_dir, search, date_from, date_to, exclude_bots } }
     *                 Raw JSON — not wrapped by NormalizeApiResponse.
     *
     * @param  string  $id  Numeric link ID (enforced by route constraint [0-9]+).
     * @param  Request  $request  Query string parameters described above.
     */
    public function getClicksList(string $id, Request $request): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();
            if (! $userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            $link = $this->findOwnedLink($id);
            if (! $link) {
                return $this->linkNotFound();
            }

            $perPage = (int) min(max($request->input('per_page', 25), 1), 100);
            $page = (int) max($request->input('page', 1), 1);
            $search = trim((string) $request->input('search', ''));

            $allowedSorts = [
                'created_at', 'country', 'city', 'state', 'device',
                'browser', 'os', 'ip', 'referer',
            ];
            $sortBy = in_array($request->input('sort_by'), $allowedSorts, true)
                ? $request->input('sort_by')
                : 'created_at';
            $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

            // Date range filter (sent as "yyyy-MM-dd HH:mm:ss" strings by the frontend)
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            // Bot exclusion filter — defaults to false (show all) for the clicks tab
            $excludeBots = filter_var($request->input('exclude_bots', false), FILTER_VALIDATE_BOOLEAN);

            $query = \App\Models\Click::where('link_id', $link->id)->with('utm');

            if ($dateFrom) {
                $query->where('created_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->where('created_at', '<=', $dateTo);
            }

            if ($excludeBots) {
                $query->where('is_bot', false);
            }

            if ($search !== '') {
                $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function ($q) use ($needle) {
                    $q->where('country', 'ilike', $needle)
                        ->orWhere('city', 'ilike', $needle)
                        ->orWhere('state', 'ilike', $needle)
                        ->orWhere('state_name', 'ilike', $needle)
                        ->orWhere('device', 'ilike', $needle)
                        ->orWhere('browser', 'ilike', $needle)
                        ->orWhere('os', 'ilike', $needle)
                        ->orWhere('ip', 'ilike', $needle)
                        ->orWhere('referer', 'ilike', $needle);
                });
            }

            $paginator = $query->orderBy($sortBy, $sortDir)
                ->orderBy('id', 'desc')
                ->paginate(perPage: $perPage, page: $page);

            $items = collect($paginator->items())->map(function ($click) {
                $referer = $click->referer;
                $refererHost = null;
                if ($referer && $referer !== '-' && $referer !== '') {
                    $refererHost = parse_url($referer, PHP_URL_HOST) ?: null;
                }

                return [
                    'id' => $click->id,
                    'created_at' => $click->created_at?->toIso8601String(),
                    'local_time' => $click->local_time,
                    'ip' => $click->ip,
                    'country' => $click->country,
                    'iso_code' => $click->iso_code,
                    'state' => $click->state,
                    'state_name' => $click->state_name,
                    'city' => $click->city,
                    'continent' => $click->continent,
                    'timezone' => $click->timezone,
                    'device' => $click->device,
                    'browser' => $click->browser,
                    'browser_version' => $click->browser_version,
                    'os' => $click->os,
                    'os_version' => $click->os_version,
                    'is_mobile' => (bool) $click->is_mobile,
                    'is_tablet' => (bool) $click->is_tablet,
                    'is_desktop' => (bool) $click->is_desktop,
                    'is_bot' => (bool) $click->is_bot,
                    'referer' => $referer,
                    'referer_host' => $refererHost ?: ($referer && $referer !== '-' ? null : 'Direct'),
                    'click_source' => $click->click_source,
                    'social_platform' => $click->social_platform,
                    'navigation_context' => $click->navigation_context,
                    'is_return_visitor' => (bool) $click->is_return_visitor,
                    'response_time' => $click->response_time,
                    'quality_tier' => $click->quality_tier,
                    'utm' => $click->utm ? [
                        'source' => $click->utm->utm_source,
                        'medium' => $click->utm->utm_medium,
                        'campaign' => $click->utm->utm_campaign,
                        'term' => $click->utm->utm_term,
                        'content' => $click->utm->utm_content,
                    ] : null,
                ];
            })->values();

            return response()->json([
                'data' => $items,
                'meta' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'sort_by' => $sortBy,
                    'sort_dir' => $sortDir,
                    'search' => $search,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'exclude_bots' => $excludeBots,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao listar cliques.', $e);
        }
    }
}
