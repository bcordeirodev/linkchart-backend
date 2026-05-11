<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreateLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CreateLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Http\Resources\LinkResource;
use App\Services\Links\LinkAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 *   GET    /api/link/{id}/clicks        → getClicksData
 *   GET    /api/link/{id}/clicks-list   → getClicksList
 *
 * Cross-mount note: GET /api/links/{id}/analytics is defined in the same route
 * group but is handled by AnalyticsController::getLinkLegacyAnalytics, not by
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
     * GET /api/link/{id}/clicks
     *
     * Return aggregated click statistics for a link: totals, unique IPs, hourly
     * distribution over the last 24 h, top countries/devices/referrers, UTM
     * campaigns, and the 10 most recent click records. All aggregations are done
     * via SQL to avoid loading large datasets into PHP memory.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — uses findOwnedLink.
     *
     * Response shape: { link_info, stats, recent_clicks } (200)
     *                 Raw JSON — not wrapped by NormalizeApiResponse.
     *
     * @param  string  $id  Numeric link ID (enforced by route constraint [0-9]+).
     */
    public function getClicksData(string $id): JsonResponse
    {
        try {
            // Buscar link por ID e verificar ownership
            $link = $this->findOwnedLink($id);
            if (! $link) {
                return $this->linkNotFound();
            }

            $base = fn () => \App\Models\Click::where('link_id', $link->id);

            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $hourExpr = $isSqlite
                ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
                : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

            // Estatísticas agregadas via SQL — sem carregar todos os cliques em memória
            $stats = [
                'total_clicks' => $base()->count(),
                'unique_ips' => $base()->distinct('ip')->count('ip'),
                'last_click' => $base()->max('created_at'),
                'first_click' => $base()->min('created_at'),

                // Distribuição por hora nas últimas 24h
                'clicks_by_hour' => $base()
                    ->where('created_at', '>=', now()->subDay())
                    ->selectRaw("$hourExpr AS hour, COUNT(*) AS total")
                    ->groupByRaw($hourExpr)
                    ->orderBy('hour')
                    ->pluck('total', 'hour'),

                // Top países
                'top_countries' => $base()
                    ->whereNotNull('country')
                    ->selectRaw('country, COUNT(*) AS total')
                    ->groupBy('country')
                    ->orderByDesc('total')
                    ->limit(5)
                    ->pluck('total', 'country'),

                // Top dispositivos
                'top_devices' => $base()
                    ->whereNotNull('device')
                    ->selectRaw('device, COUNT(*) AS total')
                    ->groupBy('device')
                    ->orderByDesc('total')
                    ->limit(10)
                    ->pluck('total', 'device'),

                // Top referrers: agrupa por referer bruto no SQL (limit 50),
                // depois reagrega por host em PHP somando totais — sem perder dados de hosts repetidos
                'top_referrers' => $base()
                    ->whereNotNull('referer')
                    ->where('referer', '!=', '-')
                    ->where('referer', '!=', '')
                    ->selectRaw('referer, COUNT(*) AS total')
                    ->groupBy('referer')
                    ->orderByDesc('total')
                    ->limit(50)
                    ->pluck('total', 'referer')
                    ->pipe(function ($collection) {
                        $hostTotals = [];
                        foreach ($collection as $referer => $total) {
                            $host = parse_url($referer, PHP_URL_HOST) ?: 'Unknown';
                            $hostTotals[$host] = ($hostTotals[$host] ?? 0) + $total;
                        }
                        arsort($hostTotals);

                        return collect($hostTotals)->take(5);
                    }),

                // Cliques com UTM
                'utm_campaigns' => \App\Models\Click::where('link_id', $link->id)
                    ->join('link_utms', 'clicks.id', '=', 'link_utms.click_id')
                    ->whereNotNull('link_utms.utm_campaign')
                    ->selectRaw('link_utms.utm_campaign AS campaign, COUNT(*) AS total')
                    ->groupBy('link_utms.utm_campaign')
                    ->orderByDesc('total')
                    ->pluck('total', 'campaign'),
            ];

            $recent_clicks = $base()
                ->with('utm')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($click) {
                    return [
                        'id' => $click->id,
                        'ip' => $click->ip,
                        'country' => $click->country,
                        'city' => $click->city,
                        'device' => $click->device,
                        'referer' => $click->referer,
                        'user_agent' => $click->user_agent,
                        'created_at' => $click->created_at,
                        'utm' => $click->utm ? [
                            'source' => $click->utm->utm_source,
                            'medium' => $click->utm->utm_medium,
                            'campaign' => $click->utm->utm_campaign,
                        ] : null,
                    ];
                });

            return response()->json([
                'link_info' => [
                    'id' => $link->id,
                    'slug' => $link->slug,
                    'title' => $link->title,
                    'original_url' => $link->original_url,
                    'created_at' => $link->created_at,
                    'clicks' => $link->clicks,
                ],
                'stats' => $stats,
                'recent_clicks' => $recent_clicks,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar dados de cliques.', $e);
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
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — uses findOwnedLink.
     *
     * Response shape: { data: Click[], meta: { total, per_page, current_page, last_page, from, to, sort_by, sort_dir, search } }
     *                 Raw JSON — not wrapped by NormalizeApiResponse.
     *
     * @param  string  $id  Numeric link ID (enforced by route constraint [0-9]+).
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

            $query = \App\Models\Click::where('link_id', $link->id)->with('utm');

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
                    'is_return_visitor' => (bool) $click->is_return_visitor,
                    'response_time' => $click->response_time,
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
                ],
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao listar cliques.', $e);
        }
    }
}
