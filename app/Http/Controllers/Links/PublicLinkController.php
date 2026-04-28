<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreatePublicLinkDTO;
use App\Http\Requests\CreatePublicLinkRequest;
use App\Http\Resources\PublicLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Controller para encurtamento público de URLs
 *
 * FUNCIONALIDADE:
 * - Permite encurtamento de URLs sem autenticação
 * - Links criados não têm userId (são públicos)
 * - Validação básica de segurança
 * - Rate limiting aplicado via middleware
 *
 * Segue os princípios SOLID:
 * - SRP: Responsável apenas por receber requisições HTTP públicas
 * - DIP: Depende da abstração LinkServiceInterface
 */
class PublicLinkController extends Controller
{
    protected LinkServiceInterface $linkService;

    public function __construct(LinkServiceInterface $linkService)
    {
        $this->linkService = $linkService;
    }

    /**
     * Cria um novo link encurtado público.
     */
    public function store(CreatePublicLinkRequest $request): JsonResponse
    {
        try {
            $linkDTO = CreatePublicLinkDTO::fromRequest($request);
            $link = $this->linkService->createPublicLink($linkDTO);

            return response()->json([
                'message' => 'Link criado com sucesso.',
                'data' => new PublicLinkResource($link),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao criar link.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Exibe informações básicas de um link público pelo slug.
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
                'data' => new PublicLinkResource($link),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar link.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtém analytics básicos de um link público (sem dados sensíveis).
     */
    public function basicAnalytics(string $slug): JsonResponse
    {
        try {
            // Filtros de validade aplicados na própria query para evitar
            // vazar a existência de slugs inativos/expirados/não iniciados.
            // Qualquer link que não passe nesses filtros retorna 404 genérico.
            $now = now();
            $link = \App\Models\Link::where('slug', $slug)
                ->where('is_active', true)
                ->where(function ($query) use ($now) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $now);
                })
                ->where(function ($query) use ($now) {
                    $query->whereNull('starts_in')
                        ->orWhere('starts_in', '<=', $now);
                })
                ->first();

            if (! $link) {
                return response()->json(['message' => 'Link não encontrado.'], 404);
            }

            $basicData = [
                'total_clicks' => $link->clicks,
                'created_at' => $link->created_at,
                'is_active' => $link->is_active,
                'short_url' => $link->getShortedUrl(),
                'has_analytics' => $link->clicks > 0,
            ];

            // Se há cliques, incluir dados básicos de gráficos
            if ($link->clicks > 0) {
                // Top 5 países
                $topCountries = \App\Models\Click::where('link_id', $link->id)
                    ->select('country', \DB::raw('count(*) as clicks'))
                    ->groupBy('country')
                    ->orderBy('clicks', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'country' => $item->country,
                            'clicks' => (int) $item->clicks,
                        ];
                    });

                // Distribuição por dispositivos
                $deviceBreakdown = \App\Models\Click::where('link_id', $link->id)
                    ->select('device', \DB::raw('count(*) as clicks'))
                    ->groupBy('device')
                    ->orderBy('clicks', 'desc')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'device' => ucfirst($item->device),
                            'clicks' => (int) $item->clicks,
                        ];
                    });

                // Cliques por hora do dia (últimos 7 dias)
                $hourExpression = \DB::connection()->getDriverName() === 'sqlite'
                    ? \DB::raw("CAST(strftime('%H', created_at) AS INTEGER) as hour")
                    : \DB::raw('EXTRACT(HOUR FROM created_at) as hour');

                $clicksByHour = \App\Models\Click::where('link_id', $link->id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->select($hourExpression, \DB::raw('count(*) as clicks'))
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->get()
                    ->map(function ($item) {
                        return [
                            'hour' => (int) $item->hour,
                            'clicks' => (int) $item->clicks,
                        ];
                    });

                // Preencher horas faltantes com 0
                $hourlyData = [];
                for ($i = 0; $i < 24; $i++) {
                    $hourData = $clicksByHour->firstWhere('hour', $i);
                    $hourlyData[] = [
                        'hour' => $i,
                        'clicks' => $hourData ? $hourData['clicks'] : 0,
                    ];
                }

                // Distribuição por browser (top 5, excluindo nulos)
                $browserBreakdown = \App\Models\Click::where('link_id', $link->id)
                    ->whereNotNull('browser')
                    ->select('browser', \DB::raw('count(*) as clicks'))
                    ->groupBy('browser')
                    ->orderBy('clicks', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'browser' => ucfirst($item->browser ?? 'Desconhecido'),
                            'clicks' => (int) $item->clicks,
                        ];
                    });

                // Distribuição por dia da semana (0=Dom … 6=Sáb) usando campo pré-computado
                $clicksByDow = \App\Models\Click::where('link_id', $link->id)
                    ->select('day_of_week', \DB::raw('count(*) as clicks'))
                    ->groupBy('day_of_week')
                    ->orderBy('day_of_week')
                    ->get();

                // Preencher todos os 7 dias, mesmo os sem clique
                $dowData = [];
                for ($i = 0; $i < 7; $i++) {
                    $dayData = $clicksByDow->firstWhere('day_of_week', $i);
                    $dowData[] = [
                        'day' => $i,
                        'clicks' => $dayData ? (int) $dayData['clicks'] : 0,
                    ];
                }

                $basicData['charts'] = [
                    'geographic' => [
                        'top_countries' => $topCountries,
                    ],
                    'audience' => [
                        'device_breakdown' => $deviceBreakdown,
                        'browser_breakdown' => $browserBreakdown,
                    ],
                    'temporal' => [
                        'clicks_by_hour' => $hourlyData,
                        'clicks_by_day_of_week' => $dowData,
                    ],
                ];
            }

            return response()->json($basicData);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics básicos.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
