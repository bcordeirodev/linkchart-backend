<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreateLinkDTO;
use App\DTOs\UpdateLinkDTO;
use App\Http\Requests\CreateLinkRequest;
use App\Http\Requests\UpdateLinkRequest;
use App\Http\Resources\LinkResource;
use App\Services\Links\LinkAuditService;
use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller para gerenciamento de Links
 *
 * Segue os princípios SOLID:
 * - SRP: Responsável apenas por receber requisições HTTP e retornar respostas
 * - DIP: Depende da abstração LinkServiceInterface
 */
class LinkController extends Controller
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
            return response()->json([
                'error' => 'Erro ao buscar links.',
                'message' => $e->getMessage()
            ], 500);
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
            if (!$userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            $link = $this->linkService->getUserLink($id);

            if (!$link) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para acessá-lo.'], 404);
            }

            return response()->json([
                'data' => new LinkResource($link)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar link.',
                'message' => $e->getMessage()
            ], 500);
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
                'data' => new LinkResource($link)
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao criar link.',
                'message' => $e->getMessage()
            ], 500);
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
            if (!$userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            // Verifica se há dados para atualizar
            if (!$request->hasDataToUpdate()) {
                return response()->json([
                    'error' => 'Nenhum dado fornecido.',
                    'message' => 'Pelo menos um campo deve ser fornecido para atualização.'
                ], 422);
            }

            // Verifica se o link existe e pertence ao usuário antes de atualizar
            $existingLink = $this->linkService->getUserLink($id);
            if (!$existingLink) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para editá-lo.'], 404);
            }

            // Salva os valores antigos para auditoria
            $oldValues = $existingLink->toArray();

            $linkDTO = UpdateLinkDTO::fromRequest($request);
            $link = $this->linkService->updateLink($id, $linkDTO);

            if (!$link) {
                return response()->json(['message' => 'Erro ao atualizar link.'], 500);
            }

            // Log da atualização
            $this->auditService->logUpdated($link, $oldValues, $userId, $request);

            return response()->json([
                'message' => 'Link atualizado com sucesso.',
                'data' => new LinkResource($link)
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar link.',
                'message' => $e->getMessage()
            ], 500);
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
            if (!$userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            // Verifica se o link existe e pertence ao usuário antes de remover
            $existingLink = $this->linkService->getUserLink($id);
            if (!$existingLink) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para removê-lo.'], 404);
            }

            // Log da exclusão (antes de deletar)
            $this->auditService->logDeleted($existingLink, $userId, $request);

            $deleted = $this->linkService->deleteLink($id);

            if (!$deleted) {
                return response()->json(['message' => 'Erro ao remover link.'], 500);
            }

            return response()->json(['message' => 'Link removido com sucesso.']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao remover link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtém analytics detalhados de um link específico.
     */
    public function analytics(string $slug): JsonResponse
    {
        try {
            // Buscar link por slug primeiro
            $link = \App\Models\Link::where('slug', $slug)
                                  ->where('user_id', auth()->guard('api')->id())
                                  ->first();

            if (!$link) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para acessá-lo.'], 404);
            }

                        // Gerar dados de analytics baseados nos cliques reais
            $totalClicks = $link->clicks;

            // REGRA MVP: Se não há cliques, retornar indicação de que não há dados suficientes
            if ($totalClicks == 0) {
                return response()->json([
                    'has_sufficient_data' => false,
                    'message' => 'Analytics disponíveis após o primeiro clique no link',
                    'total_clicks' => 0,
                    'link_info' => [
                        'id' => $link->id,
                        'slug' => $link->slug,
                        'title' => $link->title,
                        'original_url' => $link->original_url,
                        'shorted_url' => $link->shorted_url,
                        'created_at' => $link->created_at,
                        'is_active' => $link->is_active,
                        'expires_at' => $link->expires_at,
                    ]
                ]);
            }

                        // Buscar dados reais da tabela clicks
            $clicks = \App\Models\Click::where('link_id', $link->id)->get();

            // Calcular métricas reais
            $uniqueVisitors = $clicks->unique('ip')->count();
            $avgDailyClicks = $totalClicks > 0 ? round($totalClicks / max(1, now()->diffInDays($link->created_at)), 1) : 0;

            // Taxa de conversão baseada em visitantes únicos vs total de cliques
            $conversionRate = $uniqueVisitors > 0 ? round(($totalClicks / $uniqueVisitors) * 100, 1) : 0;

                        // Cliques ao longo do tempo (dados reais dos últimos 30 dias)
            $clicksOverTime = [];
            for ($i = 29; $i >= 0; $i--) {
                $date = now()->subDays($i);

                $dailyClicks = $clicks->filter(function($click) use ($date) {
                    $clickDate = \Carbon\Carbon::parse($click->created_at);
                    return $clickDate->isSameDay($date);
                })->count();

                $clicksOverTime[] = [
                    'date' => $date->format('Y-m-d'),
                    'clicks' => $dailyClicks
                ];
            }

            // Cliques por país (dados reais)
            $clicksByCountry = $clicks->whereNotNull('country')
                ->groupBy('country')
                ->map(function ($countryClicks) {
                    return [
                        'country' => $countryClicks->first()->country,
                        'clicks' => $countryClicks->count()
                    ];
                })
                ->values()
                ->sortByDesc('clicks')
                ->take(10)
                ->toArray();

            // Cliques por dispositivo (dados reais)
            $clicksByDevice = $clicks->whereNotNull('device')
                ->groupBy('device')
                ->map(function ($deviceClicks) {
                    return [
                        'device' => $deviceClicks->first()->device,
                        'clicks' => $deviceClicks->count()
                    ];
                })
                ->values()
                ->sortByDesc('clicks')
                ->toArray();

            // Cliques por referrer (dados reais)
            $clicksByReferer = $clicks->map(function ($click) {
                    // Extrair domínio do referer ou marcar como Direct
                    if (empty($click->referer) || $click->referer === '-') {
                        return 'Direct';
                    }

                    $domain = parse_url($click->referer, PHP_URL_HOST);
                    return $domain ?: 'Unknown';
                })
                ->groupBy(function ($referer) {
                    return $referer;
                })
                ->map(function ($refererClicks, $referer) {
                    return [
                        'referer' => $referer,
                        'clicks' => $refererClicks->count()
                    ];
                })
                ->values()
                ->sortByDesc('clicks')
                ->take(10)
                ->toArray();

            return response()->json([
                'total_clicks' => $totalClicks,
                'unique_visitors' => $uniqueVisitors,
                'avg_daily_clicks' => $avgDailyClicks,
                'conversion_rate' => $conversionRate . '%',
                'clicks_over_time' => $clicksOverTime,
                'clicks_by_country' => $clicksByCountry,
                'clicks_by_device' => $clicksByDevice,
                'clicks_by_referer' => $clicksByReferer,
                'link_info' => [
                    'id' => $link->id,
                    'slug' => $link->slug,
                    'title' => $link->title,
                    'original_url' => $link->original_url,
                    'shorted_url' => $link->shorted_url,
                    'created_at' => $link->created_at,
                    'is_active' => $link->is_active,
                    'expires_at' => $link->expires_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics do link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtém analytics de um link específico por ID.
     */
    public function analyticsByLinkId(string $id): JsonResponse
    {
        try {
            // Buscar link por ID e verificar ownership
            $link = \App\Models\Link::where('id', $id)
                                  ->where('user_id', auth()->guard('api')->id())
                                  ->first();

            if (!$link) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para acessá-lo.'], 404);
            }

            // Reutilizar a lógica do método analytics passando o slug
            return $this->analytics($link->slug);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics do link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtém dados detalhados de cliques de um link específico.
     */
    public function getClicksData(string $id): JsonResponse
    {
        try {
            // Buscar link por ID e verificar ownership
            $link = \App\Models\Link::where('id', $id)
                                  ->where('user_id', auth()->guard('api')->id())
                                  ->first();

            if (!$link) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para acessá-lo.'], 404);
            }

            // Buscar todos os cliques com dados relacionados
            $clicks = \App\Models\Click::where('link_id', $link->id)
                ->with('utm')
                ->orderBy('created_at', 'desc')
                ->get();

            // Estatísticas em tempo real
            $stats = [
                'total_clicks' => $clicks->count(),
                'unique_ips' => $clicks->unique('ip')->count(),
                'last_click' => $clicks->first()?->created_at,
                'first_click' => $clicks->last()?->created_at,

                // Distribuição por hora nas últimas 24h
                'clicks_by_hour' => $clicks->where('created_at', '>=', now()->subDay())
                    ->groupBy(function($click) {
                        return $click->created_at->format('H');
                    })
                    ->map->count()
                    ->toArray(),

                // Top países
                'top_countries' => $clicks->whereNotNull('country')
                    ->groupBy('country')
                    ->map->count()
                    ->sortDesc()
                    ->take(5)
                    ->toArray(),

                // Top dispositivos
                'top_devices' => $clicks->whereNotNull('device')
                    ->groupBy('device')
                    ->map->count()
                    ->sortDesc()
                    ->toArray(),

                // Top referrers
                'top_referrers' => $clicks->map(function($click) {
                        if (empty($click->referer) || $click->referer === '-') {
                            return 'Direct';
                        }
                        $domain = parse_url($click->referer, PHP_URL_HOST);
                        return $domain ?: 'Unknown';
                    })
                    ->groupBy(function($referer) {
                        return $referer;
                    })
                    ->map->count()
                    ->sortDesc()
                    ->take(5)
                    ->toArray(),

                // Cliques com UTM
                'utm_campaigns' => $clicks->filter(function($click) {
                        return $click->utm !== null;
                    })
                    ->map(function($click) {
                        return $click->utm->utm_campaign ?? 'No Campaign';
                    })
                    ->groupBy(function($campaign) {
                        return $campaign;
                    })
                    ->map->count()
                    ->sortDesc()
                    ->toArray()
            ];

            return response()->json([
                'link_info' => [
                    'id' => $link->id,
                    'slug' => $link->slug,
                    'title' => $link->title,
                    'original_url' => $link->original_url,
                    'created_at' => $link->created_at,
                    'clicks' => $link->clicks
                ],
                'stats' => $stats,
                'recent_clicks' => $clicks->take(10)->map(function($click) {
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
                        ] : null
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados de cliques.',
                'message' => $e->getMessage()
            ], 500);
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
            if (!$userId) {
                return response()->json(['message' => 'Usuário não autenticado.'], 401);
            }

            // Verifica se o link existe e pertence ao usuário
            $link = $this->linkService->getUserLink($id);
            if (!$link) {
                return response()->json(['message' => 'Link não encontrado ou você não tem permissão para acessá-lo.'], 404);
            }

            $history = $this->auditService->getLinkHistory((int)$id, $userId);

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
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar histórico de auditoria.',
                'message' => $e->getMessage()
            ], 500);
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

            if (!$link) {
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
                'data' => new LinkResource($link)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar link.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
