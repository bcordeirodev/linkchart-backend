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
 * Controller para gerenciamento de Links
 *
 * Segue os princípios SOLID:
 * - SRP: Responsável apenas por receber requisições HTTP e retornar respostas
 * - DIP: Depende da abstração LinkServiceInterface
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
     * Lista todos os links do usuário autenticado.
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
     * Exibe um link específico do usuário.
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
     * Cria um novo link encurtado.
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
     * Atualiza um link existente.
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
     * Remove um link.
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
     * Obtém dados detalhados de cliques de um link específico.
     */
    public function getClicksData(string $id): JsonResponse
    {
        try {
            // Buscar link por ID e verificar ownership
            $link = $this->findOwnedLink($id);
            if (! $link) return $this->linkNotFound();

            $base = fn () => \App\Models\Click::where('link_id', $link->id);

            $isSqlite = DB::connection()->getDriverName() === 'sqlite';
            $hourExpr = $isSqlite
                ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
                : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

            // Estatísticas agregadas via SQL — sem carregar todos os cliques em memória
            $stats = [
                'total_clicks' => $base()->count(),
                'unique_ips'   => $base()->distinct('ip')->count('ip'),
                'last_click'   => $base()->max('created_at'),
                'first_click'  => $base()->min('created_at'),

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
                        'id'         => $click->id,
                        'ip'         => $click->ip,
                        'country'    => $click->country,
                        'city'       => $click->city,
                        'device'     => $click->device,
                        'referer'    => $click->referer,
                        'user_agent' => $click->user_agent,
                        'created_at' => $click->created_at,
                        'utm'        => $click->utm ? [
                            'source'   => $click->utm->utm_source,
                            'medium'   => $click->utm->utm_medium,
                            'campaign' => $click->utm->utm_campaign,
                        ] : null,
                    ];
                });

            return response()->json([
                'link_info' => [
                    'id'           => $link->id,
                    'slug'         => $link->slug,
                    'title'        => $link->title,
                    'original_url' => $link->original_url,
                    'created_at'   => $link->created_at,
                    'clicks'       => $link->clicks,
                ],
                'stats'         => $stats,
                'recent_clicks' => $recent_clicks,
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar dados de cliques.', $e);
        }
    }

    /**
     * Lista paginada de cliques de um link específico para exibição em tabela.
     *
     * Suporta paginação (page, per_page), busca textual (search) em campos
     * relevantes e ordenação (sort_by, sort_dir). Retorna `data` + `meta` no
     * envelope padronizado pelo NormalizeApiResponse middleware.
     */
    public function getClicksList(string $id, Request $request): JsonResponse
    {
        try {
            $userId = auth()->guard('api')->id();
            if (! $userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            $link = $this->findOwnedLink($id);
            if (! $link) return $this->linkNotFound();

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

    /**
     * Obtém o histórico de auditoria de um link específico.
     */
    public function auditHistory(string $id): JsonResponse
    {
        try {
            // Validação adicional de ownership
            $userId = auth()->guard('api')->id();
            if (! $userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            // Verifica se o link existe e pertence ao usuário
            $link = $this->linkService->getUserLink($id);
            if (! $link) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para acessá-lo.'], 404);
            }

            $history = $this->auditService->getLinkHistory((int) $id, $userId);

            return response()->json([
                'data' => $history->map(function ($audit) {
                    return [
                        'id' => $audit->id,
                        'action' => $audit->action,
                        'old_values' => $audit->old_values,
                        'new_values' => $audit->new_values,
                        'ip_address' => $audit->ip_address,
                        'user_agent' => $audit->user_agent,
                        'created_at' => $audit->created_at,
                        'user' => $audit->user ? [
                            'id' => $audit->user->id,
                            'name' => $audit->user->name,
                            'email' => $audit->user->email,
                        ] : null,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar histórico de auditoria.', $e);
        }
    }

    /**
     * Exibe um link pelo slug (rota pública para preview).
     */
    public function showBySlug(string $slug): JsonResponse
    {
        try {
            $link = \App\Models\Link::where('slug', $slug)
                ->where('is_active', true)
                ->first();

            if (! $link) {
                return response()->json(['message' => 'Link não encontrado ou inativo.'], 404);
            }

            // Verifica se o link não expirou
            if ($link->expires_at && now()->isAfter($link->expires_at)) {
                return response()->json(['message' => 'Link expirado.'], 404);
            }

            // Verifica se já pode ser usado (starts_in)
            if ($link->starts_in && now()->isBefore($link->starts_in)) {
                return response()->json(['message' => 'Link ainda não está disponível.'], 404);
            }

            return response()->json([
                'data' => new LinkResource($link),
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar link.', $e);
        }
    }
}
