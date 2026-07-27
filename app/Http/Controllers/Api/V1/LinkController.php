<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Analytics\DashboardAnalyticsInterface;
use App\Contracts\Repositories\LinkRepositoryInterface;
use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreateLinkDTO;
use App\Http\Requests\Api\V1\StoreLinkRequest;
use App\Http\Resources\LinkResource;
use App\Jobs\FetchLinkPreviewJob;
use App\Services\Analytics\MetricsService;
use App\Services\Links\LinkAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * API pública v1 de links (autenticação via API key/Sanctum).
 *
 * Owns the /api/v1/links route family, protected by auth:sanctum +
 * throttle:public-api (60/min por token). Every route is scoped to the owner
 * of the Bearer token — cross-user ids always answer 404, never 403, so the
 * API does not reveal whether a foreign id exists.
 *
 * Link creation goes through the same {@see LinkServiceInterface} as the
 * panel, which means the full safety pipeline applies: local anti-phishing
 * heuristic + Google Safe Browsing (see
 * {@see \App\Http\Requests\Api\V1\StoreLinkRequest}), unique slug generation,
 * and audit logging.
 *
 * Routes overview:
 *   POST /api/v1/links            → store
 *   GET  /api/v1/links            → index
 *   GET  /api/v1/links/{id}       → show
 *   GET  /api/v1/links/{id}/stats → stats
 */
class LinkController extends Controller
{
    /**
     * @param  LinkServiceInterface  $linkService  Regras de negócio de links (criação, slug, safety).
     * @param  LinkRepositoryInterface  $linkRepository  Acesso a dados de links (busca paginada, ownership).
     * @param  DashboardAnalyticsInterface  $dashboardAnalytics  Agregações de analytics reutilizadas em stats().
     * @param  MetricsService  $metricsService  Série diária (sparkline) reutilizada em stats().
     * @param  LinkAuditService  $auditService  Trilha de auditoria de criação.
     */
    public function __construct(
        private readonly LinkServiceInterface $linkService,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly DashboardAnalyticsInterface $dashboardAnalytics,
        private readonly MetricsService $metricsService,
        private readonly LinkAuditService $auditService,
    ) {}

    /**
     * Criar link encurtado
     *
     * POST /api/v1/links
     *
     * Create a shortened link owned by the API token's user. The destination
     * URL runs through the full safety pipeline (local anti-phishing
     * heuristic + Google Safe Browsing) before anything is persisted; unsafe
     * URLs are rejected with 422. When `slug` is omitted a unique slug is
     * generated automatically.
     *
     * `subdomain_id` follows the panel's tri-state semantics (see
     * CreateLinkDTO::$subdomain_id_provided): absent → the user's default
     * (oldest active) subdomain; explicit null → force the root domain; an id
     * → that subdomain, which must be active and owned by the token's user
     * (422 otherwise).
     *
     * Body: { original_url (required), slug?, title?, expires_at?,
     *         click_limit?, subdomain_id?, utm_source?, utm_medium?,
     *         utm_campaign?, utm_term?, utm_content? }
     *
     * Middleware: auth:sanctum, throttle:public-api
     * Auth: required (Bearer API key)
     *
     * Response shape: NormalizeApiResponse envelope: { data: LinkResource } (201)
     * — includes short_url, has_password, is_active, clicks etc.
     *
     * @param  StoreLinkRequest  $request  Validated payload described above.
     *
     * @throws \Illuminate\Validation\ValidationException (handled by StoreLinkRequest)
     */
    public function store(StoreLinkRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $linkDTO = new CreateLinkDTO(
                original_url: $request->validated('original_url'),
                user_id: $user->id,
                title: $request->validated('title'),
                expires_at: $request->validated('expires_at'),
                custom_slug: $request->validated('slug'),
                click_limit: $request->validated('click_limit') !== null
                    ? (int) $request->validated('click_limit')
                    : null,
                subdomain_id: $request->validated('subdomain_id') !== null
                    ? (int) $request->validated('subdomain_id')
                    : null,
                subdomain_id_provided: $request->has('subdomain_id'),
                utm_source: $request->validated('utm_source') ?: null,
                utm_medium: $request->validated('utm_medium') ?: null,
                utm_campaign: $request->validated('utm_campaign') ?: null,
                utm_term: $request->validated('utm_term') ?: null,
                utm_content: $request->validated('utm_content') ?: null,
            );

            $link = $this->linkService->createLink($linkDTO);

            // Mesma trilha de auditoria do painel — a origem (API pública) fica
            // registrada pelos metadados da request (IP/UA do cliente da API).
            $this->auditService->logCreated($link, $user->id, $request);

            // Pre-warm do preview, como no painel, para o thumbnail do dashboard.
            FetchLinkPreviewJob::dispatch($link->id, $link->original_url);

            return response()->json([
                'data' => new LinkResource($link),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Listar links
     *
     * GET /api/v1/links
     *
     * Return the token owner's links, newest first, paginated.
     *
     * Query: page (int, >= 1, default 1), per_page (int, 1–50, default 15).
     *
     * Middleware: auth:sanctum, throttle:public-api
     * Auth: required (Bearer API key)
     *
     * Response shape: NormalizeApiResponse envelope:
     *   { data: LinkResource[], meta: { current_page, per_page, total, last_page } }
     *
     * @param  Request  $request  Query string parameters described above.
     *
     * @throws \Illuminate\Validation\ValidationException When page/per_page are invalid (e.g. per_page > 50).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
        ]);

        $paginator = $this->linkRepository->searchByUser($request->user()->id, [
            'page' => $validated['page'] ?? 1,
            'per_page' => $validated['per_page'] ?? 15,
            'sort' => 'created_at',
            'order' => 'desc',
        ]);

        return response()->json([
            'data' => LinkResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Detalhar link
     *
     * GET /api/v1/links/{id}
     *
     * Return a single link owned by the token's user. Answers 404 both when
     * the id does not exist and when it belongs to another user.
     *
     * Middleware: auth:sanctum, throttle:public-api
     * Auth: required (Bearer API key)
     * Owner check: yes — lookup scoped by user_id.
     *
     * Response shape: NormalizeApiResponse envelope: { data: LinkResource }
     *
     * @param  Request  $request  Current HTTP request (token-authenticated).
     * @param  string  $id  Numeric link ID (route-constrained to digits).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $link = $this->linkRepository->findByIdAndUser($id, $request->user()->id);

        if (! $link) {
            return response()->json(['message' => 'Link não encontrado.'], 404);
        }

        return response()->json(['data' => new LinkResource($link)]);
    }

    /**
     * Estatísticas do link
     *
     * GET /api/v1/links/{id}/stats
     *
     * Return a compact stats summary for one link, reusing the same analytics
     * aggregations that power the panel dashboard (no bespoke SQL here):
     *
     *   - total_clicks / unique_visitors → dashboard summary (distinct IPs);
     *   - top_countries → top 5 countries by clicks: [{ country, clicks }];
     *   - devices → clicks per device type: [{ device, clicks }];
     *   - clicks_last_30d → daily series, zero-filled, 30 entries:
     *     [{ date: "YYYY-MM-DD", clicks }].
     *
     * Middleware: auth:sanctum, throttle:public-api
     * Auth: required (Bearer API key)
     * Owner check: yes — 404 for foreign/missing ids.
     *
     * Response shape: NormalizeApiResponse envelope:
     *   { data: { total_clicks, unique_visitors, top_countries, devices, clicks_last_30d } }
     *
     * @param  Request  $request  Current HTTP request (token-authenticated).
     * @param  string  $id  Numeric link ID (route-constrained to digits).
     */
    public function stats(Request $request, string $id): JsonResponse
    {
        $link = $this->linkRepository->findByIdAndUser($id, $request->user()->id);

        if (! $link) {
            return response()->json(['message' => 'Link não encontrado.'], 404);
        }

        $dashboard = $this->dashboardAnalytics->getLinkDashboardAnalytics($link->id);

        return response()->json([
            'data' => [
                'total_clicks' => $dashboard['summary']['total_clicks'],
                'unique_visitors' => $dashboard['summary']['unique_visitors'],
                'top_countries' => collect($dashboard['geographic_data']['top_countries'])
                    ->take(5)
                    ->map(fn (array $row): array => [
                        'country' => $row['country'],
                        'clicks' => $row['clicks'],
                    ])
                    ->values()
                    ->all(),
                'devices' => collect($dashboard['audience_data']['device_breakdown'])
                    ->map(fn (array $row): array => [
                        'device' => $row['device'],
                        'clicks' => $row['clicks'],
                    ])
                    ->values()
                    ->all(),
                'clicks_last_30d' => $this->metricsService->getLinkSparkline($link->id, 30),
            ],
        ]);
    }
}
