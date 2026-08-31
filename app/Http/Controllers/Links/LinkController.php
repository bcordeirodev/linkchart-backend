<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\Analytics\AnalyticsFilters;
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
 *   POST   /api/links/bulk-action        → bulkAction (registered before {id})
 *   POST   /api/links/claim             → claim      (registered before {id}, throttle:claim-link)
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
     * Return the authenticated user's links, wrapped in a LinkResource collection.
     *
     * Two response contracts, opt-in via the `page` query parameter, kept
     * side-by-side for blue/green deploy compatibility (frontend and backend
     * containers of different versions may serve traffic simultaneously):
     *
     *   - WITHOUT `page`: legacy behaviour, unchanged — returns the full list
     *     of the user's links, newest first, no filtering/sorting/pagination.
     *   - WITH `page`: paginated + filterable branch. Accepts:
     *       page      int, >= 1
     *       per_page  int, 1–50 (default 12)
     *       q         string, max 255 — case-insensitive search over
     *                 title, original_url, and slug
     *       status    active|inactive|expired
     *       sort      created_at|clicks|title (default created_at)
     *       order     asc|desc (default desc)
     *     Invalid params (e.g. per_page > 50) yield a 422 validation response.
     *
     * The per-item shape is identical in both branches — both ultimately
     * serialise through LinkResource — only the envelope differs.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — LinkService filters by auth user id.
     *
     * Response shape:
     *   - Legacy: NormalizeApiResponse envelope: { data: LinkResource[] }
     *   - Paginated: NormalizeApiResponse envelope: { data: LinkResource[], meta: { current_page, per_page, total, last_page } }
     *
     * @param  Request  $request  Query string parameters described above.
     *
     * @throws \Illuminate\Validation\ValidationException When the paginated branch receives invalid filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            if (! $request->has('page')) {
                $links = $this->linkService->getAllUserLinks();

                return response()->json(LinkResource::collection($links));
            }

            $validated = $request->validate([
                'page' => 'integer|min:1',
                'per_page' => 'integer|min:1|max:50',
                'q' => 'nullable|string|max:255',
                'status' => 'nullable|in:active,inactive,expired',
                'sort' => 'nullable|in:created_at,clicks,title',
                'order' => 'nullable|in:asc,desc',
            ]);
            $validated['page'] = $validated['page'] ?? 1;
            $validated['per_page'] = $validated['per_page'] ?? 12;

            $paginator = $this->linkService->searchUserLinks($validated);

            return response()->json([
                'data' => LinkResource::collection($paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar links.', $e);
        }
    }

    /**
     * POST /api/links/bulk-action
     *
     * Execute an action (activate/deactivate/delete) over up to 50 links
     * owned by the authenticated user in a single request. Ids that belong
     * to another user are silently ignored — the response never reveals
     * whether a foreign id exists, only how many of the requested ids were
     * actually affected.
     *
     * Registered BEFORE the `links/{id}` wildcard routes in routes/api.php so
     * the literal "bulk-action" path segment cannot collide with the numeric
     * `{id}` constraint.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — LinkService::bulkAction scopes by auth user id.
     *
     * Body: { action: "activate"|"deactivate"|"delete", ids: number[] } (1–50 ids)
     * Response shape: NormalizeApiResponse envelope: { data: { affected: number, requested: number } }
     *
     * @param  Request  $request  JSON body described above.
     *
     * @throws \Illuminate\Validation\ValidationException When action/ids are missing or invalid.
     */
    public function bulkAction(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'action' => 'required|in:activate,deactivate,delete',
                'ids' => 'required|array|min:1|max:50',
                'ids.*' => 'integer',
            ]);

            $result = $this->linkService->bulkAction(
                auth()->guard('api')->id(),
                $validated['action'],
                $validated['ids']
            );

            return response()->json(['data' => $result]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->serverError('Erro ao executar ação em massa.', $e);
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
     * POST /api/links/claim
     *
     * Reivindica um link criado anonimamente no encurtador público, provando a
     * autoria com o token devolvido uma única vez na resposta 201 do
     * `POST /api/public/shorten`. É o momento de conversão que faltava:
     * ~87% dos cliques de prod vinham de links sem dono, sem nenhum caminho de
     * anônimo → conta. O link passa a pertencer ao usuário autenticado com todo
     * o histórico de cliques junto (as linhas de `clicks` apontam para
     * `link_id`, não para o dono — nada é migrado).
     *
     * A troca de dono acontece num único UPDATE condicional dentro de
     * {@see \App\Services\Links\LinkService::claimLink()}, então duas chamadas
     * simultâneas com o mesmo token nunca produzem dois donos: uma responde 200
     * e a outra 409.
     *
     * Registrada ANTES dos wildcards `links/{id}` em routes/api.php — "claim"
     * não é numérico, logo a constraint [0-9]+ já impediria a colisão, mas a
     * ordem explícita documenta a invariante para rotas futuras.
     *
     * Middleware: api.auth:api, verified, throttle:claim-link (10/min por usuário)
     * Auth: required — reivindicar exige uma conta para receber o link.
     * Owner check: não se aplica — o link ainda não tem dono; a posse do token
     *              É a autorização.
     *
     * Body: { slug: string, claim_token: string }
     * Response shape:
     *   200 → NormalizeApiResponse envelope: { message, data: LinkResource }
     *   409 → { error: { code: "ALREADY_CLAIMED", message } } quando o link já
     *         tem dono (reivindicado antes, ou nascido de um shorten logado).
     *   422 → { error: { code: "INVALID_CLAIM_TOKEN", message } } para token
     *         errado, slug inexistente OU link anônimo antigo sem token. Os
     *         três casos compartilham o código de propósito: distingui-los
     *         transformaria o endpoint num oráculo de enumeração de slugs.
     *
     * @param  Request  $request  Corpo JSON descrito acima.
     *
     * @throws \Illuminate\Validation\ValidationException Quando slug ou claim_token faltam.
     */
    public function claim(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'slug' => 'required|string|max:255',
                'claim_token' => 'required|string|max:255',
            ]);

            $userId = auth()->guard('api')->id();

            $result = $this->linkService->claimLink(
                $validated['slug'],
                $validated['claim_token'],
                $userId
            );

            if ($result['status'] === 'already_claimed') {
                return response()->json([
                    'error' => [
                        'code' => 'ALREADY_CLAIMED',
                        'message' => 'Este link já foi reivindicado.',
                    ],
                ], 409);
            }

            if ($result['status'] !== 'claimed') {
                return response()->json([
                    'error' => [
                        'code' => 'INVALID_CLAIM_TOKEN',
                        'message' => 'Não foi possível reivindicar este link.',
                    ],
                ], 422);
            }

            return response()->json([
                'message' => 'Link reivindicado com sucesso.',
                'data' => new LinkResource($result['link']),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->serverError('Erro ao reivindicar link.', $e);
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
     *   - country, device, channel, continent (drill-down dimensions, same
     *     semantics as the analytics endpoints — see AnalyticsFilters)
     *
     * Filters are parsed via AnalyticsFilters::fromRequest() and applied with
     * applyToQuery() so this list always matches the same scope as the charts
     * on the same screen. `channel=direct` is a COALESCE bucket that also
     * matches rows with a NULL click_source (see AnalyticsFilters::applyChannel).
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

            // NOTE: 'ip' is intentionally absent — the visitor IP is personal
            // data (LGPD) and is neither returned nor sortable/searchable.
            $allowedSorts = [
                'created_at', 'country', 'city', 'state', 'device',
                'browser', 'os', 'referer',
            ];
            $sortBy = in_array($request->input('sort_by'), $allowedSorts, true)
                ? $request->input('sort_by')
                : 'created_at';
            $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

            // Filtros compartilhados com os gráficos — a aba Cliques precisa
            // enxergar exatamente o mesmo recorte que os painéis da mesma tela.
            $filters = AnalyticsFilters::fromRequest($request);

            $query = $filters->applyToQuery(
                \App\Models\Click::where('link_id', $link->id)->with('utm')
            );

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
                        ->orWhere('referer', 'ilike', $needle);
                });
            }

            $paginator = $query->orderBy($sortBy, $sortDir)
                ->orderBy('id', 'desc')
                ->paginate(perPage: $perPage, page: $page);

            $items = collect($paginator->items())->map(function ($click) {
                $referer = $click->referer;
                $refererHost = null;
                if ($referer && $referer !== '-') {
                    $refererHost = parse_url($referer, PHP_URL_HOST) ?: null;
                    // LGPD: drop query string/fragment, which can carry PII
                    // (tokens, emails, names) leaked from the referring page.
                    $referer = $this->stripUrlSensitiveParts($referer);
                }

                // NOTE: the visitor IP is deliberately omitted — it is personal
                // data (LGPD) and is not consumed by the clicks tab UI.
                return [
                    'id' => $click->id,
                    'created_at' => $click->created_at->toIso8601String(),
                    'local_time' => $click->local_time,
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
                    'date_from' => $filters->dateFrom?->toDateTimeString(),
                    'date_to' => $filters->dateTo?->toDateTimeString(),
                    'exclude_bots' => $filters->excludeBots,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao listar cliques.', $e);
        }
    }

    /**
     * Remove the query string and fragment from a URL, keeping scheme/host/path.
     *
     * Used to sanitise the click `referer` before exposing it in the API:
     * referer query strings often carry PII (auth tokens, emails, names) that
     * must not leak to the link owner under the LGPD.
     *
     * @param  string  $url  Raw referer URL.
     * @return string The URL truncated at the first '?' or '#'.
     */
    private function stripUrlSensitiveParts(string $url): string
    {
        return preg_split('/[?#]/', $url, 2)[0];
    }
}
