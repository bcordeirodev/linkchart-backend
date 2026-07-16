<?php

namespace App\Http\Controllers\Reports;

use App\Contracts\Analytics\ReportsAnalyticsServiceInterface;
use App\DTOs\Analytics\AnalyticsFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatórios agregados multi-link do usuário autenticado (módulo `/reports`).
 *
 * Todos os endpoints ficam sob o grupo de rotas `api.auth:api + verified`
 * (mesmo grupo do analytics por-link) e são cacheados por 60s por
 * usuário+método+filtros, seguindo o mesmo padrão de
 * {@see \App\Services\Analytics\LinkAnalyticsOrchestrator}.
 *
 * Routes (prefix /api/reports):
 *   GET /summary           → summary
 *   GET /timeseries        → timeseries
 *   GET /top-links         → topLinks
 *   GET /breakdown         → breakdown
 *   GET /link-performance  → linkPerformance
 *   GET /insights          → insights
 *   GET /export/clicks     → exportClicks
 */
class ReportsController extends Controller
{
    /** Seconds a reports payload stays cached. */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * @param  ReportsAnalyticsServiceInterface  $reports  Multi-link aggregation service.
     */
    public function __construct(private readonly ReportsAnalyticsServiceInterface $reports) {}

    /**
     * GET /api/reports/summary
     *
     * Returns aggregated KPIs (total_clicks, unique_visitors, total_links,
     * active_links, avg_clicks_per_day, variation_pct) across the
     * authenticated user's own, non-demo links.
     *
     * Middleware: api.auth:api, verified
     *
     * @param  Request  $request  Incoming HTTP request; accepts date_from/date_to/exclude_bots.
     * @return JsonResponse Response shape: { data: {...summary} }.
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->cached($request, 'summary', fn (int $userId, AnalyticsFilters $f) => $this->reports->getSummary($userId, $f));
    }

    /**
     * GET /api/reports/timeseries
     *
     * Returns daily click counts across the authenticated user's own,
     * non-demo links.
     *
     * Middleware: api.auth:api, verified
     *
     * @param  Request  $request  Incoming HTTP request; accepts date_from/date_to/exclude_bots.
     * @return JsonResponse Response shape: { data: { series: [{date, clicks, unique_visitors}], previous: [{date, clicks}] } }.
     */
    public function timeseries(Request $request): JsonResponse
    {
        return $this->cached($request, 'timeseries', fn (int $userId, AnalyticsFilters $f) => $this->reports->getTimeseries($userId, $f));
    }

    /**
     * GET /api/reports/top-links
     *
     * Returns the authenticated user's top links by click count within the
     * filter window.
     *
     * Middleware: api.auth:api, verified
     *
     * @param  Request  $request  Incoming HTTP request; accepts date_from/date_to/exclude_bots/limit.
     * @return JsonResponse Response shape: { data: [{link_id, title, slug, short_domain, clicks, unique_visitors}, ...] }.
     */
    public function topLinks(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        return $this->cached($request, "top-links:{$limit}", fn (int $userId, AnalyticsFilters $f) => $this->reports->getTopLinks($userId, $f, $limit));
    }

    /**
     * GET /api/reports/breakdown
     *
     * Returns a click breakdown by a whitelisted dimension across the
     * authenticated user's own, non-demo links.
     *
     * Middleware: api.auth:api, verified
     *
     * @param  Request  $request  Incoming HTTP request; requires `dimension`, accepts date_from/date_to/exclude_bots.
     * @return JsonResponse Response shape: { data: [{label, clicks, pct}, ...] }.
     *
     * @throws \Illuminate\Validation\ValidationException When `dimension` is missing or not whitelisted.
     */
    public function breakdown(Request $request): JsonResponse
    {
        $request->validate([
            'dimension' => 'required|string|in:country,device,browser,navigation_context,quality_tier',
        ]);
        $dimension = $request->query('dimension');

        return $this->cached($request, "breakdown:{$dimension}", fn (int $userId, AnalyticsFilters $f) => $this->reports->getBreakdown($userId, $dimension, $f));
    }

    /**
     * GET /api/reports/link-performance
     *
     * Returns the authenticated user's own, non-demo links ranked by clicks
     * in the filter window — the portfolio leaderboard. Each row also
     * carries the variation vs. the immediately preceding period of equal
     * length and this link's share of the user's total clicks.
     *
     * Middleware: api.auth:api, verified
     *
     * @param  Request  $request  Incoming HTTP request; accepts date_from/date_to/exclude_bots/limit.
     * @return JsonResponse Response shape: { data: [{link_id, title, slug, short_domain, clicks, variation_pct, share_pct, spark}, ...] }.
     */
    public function linkPerformance(Request $request): JsonResponse
    {
        $limit = min(50, max(1, (int) $request->query('limit', 10)));

        return $this->cached($request, "link-performance:{$limit}", fn (int $userId, AnalyticsFilters $f) => $this->reports->getLinkPerformance($userId, $f, $limit));
    }

    /**
     * GET /api/reports/insights
     *
     * Returns portfolio-level (account-wide) computed insights — best
     * performing link, fastest growing link, top-3 traffic concentration and
     * overall account growth vs. the previous period. Values are raw and
     * language-agnostic; the frontend maps `key` to a localized label + icon.
     *
     * Middleware: api.auth:api, verified
     *
     * @param  Request  $request  Incoming HTTP request; accepts date_from/date_to/exclude_bots.
     * @return JsonResponse Response shape: { data: [{key, value, unit, meta}, ...] }.
     */
    public function insights(Request $request): JsonResponse
    {
        return $this->cached($request, 'insights', fn (int $userId, AnalyticsFilters $f) => $this->reports->getInsights($userId, $f));
    }

    /**
     * GET /api/reports/export/clicks
     *
     * Streams the user's clicks (across all own, non-demo links) as CSV,
     * chunked in batches of 1000 rows so the full result set is never
     * materialized in memory. Same date_from/date_to/exclude_bots filters as
     * the other endpoints. NOT cached — export is a one-off action, not a
     * dashboard payload.
     *
     * LGPD: the `ip` column is intentionally never selected or written —
     * see {@see \App\Contracts\Analytics\ReportsAnalyticsServiceInterface::exportClicksQuery}.
     *
     * Middleware: api.auth:api, verified
     *
     * @param  Request  $request  Incoming HTTP request; accepts date_from/date_to/exclude_bots.
     * @return StreamedResponse `text/csv` download named `relatorio-cliques.csv`.
     */
    public function exportClicks(Request $request): StreamedResponse
    {
        $userId = $request->user()->id;
        $filters = AnalyticsFilters::fromRequest($request);
        $query = $this->reports->exportClicksQuery($userId, $filters);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['data', 'link', 'slug', 'pais', 'cidade', 'dispositivo', 'navegador', 'so', 'origem', 'contexto', 'qualidade']);

            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r->created_at, $r->title, $r->slug, $r->country, $r->city,
                        $r->device, $r->browser, $r->os, $r->referer,
                        $r->navigation_context, $r->quality_tier,
                    ]);
                }
            });

            fclose($out);
        }, 'relatorio-cliques.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Executa o callback com cache de 60s chaveado por usuário + método + filtros.
     *
     * @param  Request  $request  Incoming HTTP request (used to resolve the authenticated user and filters).
     * @param  string  $method  Cache key component identifying the endpoint (and any extra params, e.g. limit/dimension).
     * @param  \Closure(int, AnalyticsFilters): array  $fn  Producer invoked on cache miss.
     * @return JsonResponse Response shape: { data: mixed }.
     */
    private function cached(Request $request, string $method, \Closure $fn): JsonResponse
    {
        $userId = $request->user()->id;
        $filters = AnalyticsFilters::fromRequest($request);
        $key = "reports:{$userId}:{$method}:".$filters->cacheKey();

        $data = Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $fn($userId, $filters));

        return response()->json(['data' => $data]);
    }
}
