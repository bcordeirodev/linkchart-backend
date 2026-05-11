<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\DTOs\CreatePublicLinkDTO;
use App\Http\Requests\CreatePublicLinkRequest;
use App\Http\Resources\PublicLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public-facing controller for unauthenticated link operations.
 *
 * Handles URL shortening without authentication (anonymous links have no
 * user_id), public slug lookup, and basic aggregate analytics for the /shorter
 * and /public-analytics frontend pages.
 *
 * Routes (all under /api/public/* prefix, no auth required):
 *   POST   /api/public/shorten          → store       (throttle:public-shorten)
 *   GET    /api/public/link/{slug}      → showBySlug  (no throttle)
 *   GET    /api/public/analytics/{slug} → basicAnalytics (throttle:public-analytics)
 *
 * Depends on: LinkServiceInterface (injected).
 */
class PublicLinkController extends Controller
{
    protected LinkServiceInterface $linkService;

    public function __construct(LinkServiceInterface $linkService)
    {
        $this->linkService = $linkService;
    }

    /**
     * POST /api/public/shorten
     *
     * Create an anonymous shortened link (no user_id). Validates via
     * CreatePublicLinkRequest and maps to CreatePublicLinkDTO.
     *
     * Middleware: throttle:public-shorten (10/min per IP)
     * Auth: not required
     * Owner check: no
     *
     * Response shape: { message, data: PublicLinkResource } (201)
     *                 { error, message } on invalid input (422)
     *                 { error, message } on server error (500)
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
     * GET /api/public/link/{slug}
     *
     * Public-facing slug lookup. Returns basic link metadata (without click
     * details) for an active, non-expired, already-started link. This is the
     * live routed action — not the deleted LinkController::showBySlug variant
     * removed in R-06.
     *
     * Middleware: none (no throttle, no auth)
     * Auth: not required
     * Owner check: no
     *
     * Response shape: { data: PublicLinkResource } (200)
     *                 { message } (404) if not found, expired, or not yet started.
     *                 { error, message } on server error (500)
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
     * GET /api/public/analytics/{slug}
     *
     * Return non-sensitive aggregate analytics for an active, non-expired,
     * already-started public link. Includes total clicks, top countries,
     * device breakdown, hourly distribution (last 7 days), browser breakdown,
     * and day-of-week distribution. Results are cached for 5 minutes
     * (Cache::remember, key: public_analytics_{linkId}).
     *
     * Middleware: throttle:public-analytics
     * Auth: not required
     * Owner check: no — applies active/expires_at/starts_in filters to avoid
     *              leaking existence of inactive slugs.
     *
     * Response shape: { total_clicks, created_at, is_active, short_url,
     *                   has_analytics, charts?: { geographic, audience, temporal } }
     *                 { message } (404) if link not found or filtered out.
     *                 { error, message } on server error (500)
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

            // Links sem cliques retornam imediatamente sem cache, para que o estado
            // vazio resolva na próxima requisição após o primeiro clique chegar.
            if ($link->clicks === 0) {
                return response()->json([
                    'total_clicks' => 0,
                    'created_at' => $link->created_at,
                    'is_active' => $link->is_active,
                    'short_url' => $link->getShortedUrl(),
                    'has_analytics' => false,
                ]);
            }

            $basicData = Cache::remember("public_analytics_{$link->id}", 300, function () use ($link) {
                $data = [
                    'total_clicks' => $link->clicks,
                    'created_at' => $link->created_at,
                    'is_active' => $link->is_active,
                    'short_url' => $link->getShortedUrl(),
                    'has_analytics' => true,
                ];

                // Top 5 países
                $topCountries = \App\Models\Click::where('link_id', $link->id)
                    ->select('country', DB::raw('count(*) as clicks'))
                    ->groupBy('country')
                    ->orderBy('clicks', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(fn ($item) => ['country' => $item->country, 'clicks' => (int) $item->clicks]);

                // Distribuição por dispositivos
                $deviceBreakdown = \App\Models\Click::where('link_id', $link->id)
                    ->select('device', DB::raw('count(*) as clicks'))
                    ->groupBy('device')
                    ->orderBy('clicks', 'desc')
                    ->get()
                    ->map(fn ($item) => ['device' => ucfirst($item->device), 'clicks' => (int) $item->clicks]);

                // Cliques por hora do dia (últimos 7 dias) — usa coluna pré-computada quando disponível
                $hourExpr = DB::connection()->getDriverName() === 'sqlite'
                    ? DB::raw("COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER)) as hour")
                    : DB::raw('COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int) as hour');

                $clicksByHour = \App\Models\Click::where('link_id', $link->id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->select($hourExpr, DB::raw('count(*) as clicks'))
                    ->groupBy('hour')
                    ->orderBy('hour')
                    ->get()
                    ->map(fn ($item) => ['hour' => (int) $item->hour, 'clicks' => (int) $item->clicks]);

                $hourlyData = [];
                for ($i = 0; $i < 24; $i++) {
                    $h = $clicksByHour->firstWhere('hour', $i);
                    $hourlyData[] = ['hour' => $i, 'clicks' => $h ? $h['clicks'] : 0];
                }

                // Distribuição por browser (top 5)
                $browserBreakdown = \App\Models\Click::where('link_id', $link->id)
                    ->whereNotNull('browser')
                    ->select('browser', DB::raw('count(*) as clicks'))
                    ->groupBy('browser')
                    ->orderBy('clicks', 'desc')
                    ->limit(5)
                    ->get()
                    ->map(fn ($item) => ['browser' => ucfirst($item->browser ?? 'Desconhecido'), 'clicks' => (int) $item->clicks]);

                // Distribuição por dia da semana — ISO 1-7 (1=Seg...7=Dom)
                $clicksByDow = \App\Models\Click::where('link_id', $link->id)
                    ->select('day_of_week', DB::raw('count(*) as clicks'))
                    ->groupBy('day_of_week')
                    ->orderBy('day_of_week')
                    ->get();

                $dowData = [];
                for ($i = 1; $i <= 7; $i++) {
                    $d = $clicksByDow->firstWhere('day_of_week', $i);
                    $dowData[] = ['day' => $i, 'clicks' => $d ? (int) $d['clicks'] : 0];
                }

                $data['charts'] = [
                    'geographic' => ['top_countries' => $topCountries],
                    'audience' => ['device_breakdown' => $deviceBreakdown, 'browser_breakdown' => $browserBreakdown],
                    'temporal' => ['clicks_by_hour' => $hourlyData, 'clicks_by_day_of_week' => $dowData],
                ];

                return $data;
            });

            return response()->json($basicData);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar analytics básicos.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
