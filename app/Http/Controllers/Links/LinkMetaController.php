<?php

namespace App\Http\Controllers\Links;

use App\Jobs\FetchLinkPreviewJob;
use App\Models\Link;
use App\Models\LinkPreview;
use App\Services\Analytics\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Auxiliary metadata controller for the links list UI.
 *
 * Serves sparkline time-series, trend indicators, OG preview data, and URL
 * health status for owned links. All five action response shapes are locked
 * (stable contracts consumed by the frontend dashboard) — do not change
 * field names or nesting without a corresponding frontend update.
 *
 * Routes (all under api.auth:api + verified middleware, prefix /api/links):
 *   POST   /api/links/batch-meta        → batchMeta
 *   GET    /api/links/{id}/sparkline    → sparkline
 *   GET    /api/links/{id}/trend        → trend
 *   GET    /api/links/{id}/preview      → preview
 *   GET    /api/links/{id}/health       → health
 *
 * Depends on: MetricsService (injected), FetchLinkPreviewJob (dispatched).
 */
class LinkMetaController extends Controller
{
    public function __construct(private MetricsService $metricsService) {}

    /**
     * POST /api/links/batch-meta
     *
     * Single round-trip to fetch sparkline + trend + OG preview + health for
     * up to 50 link IDs owned by the authenticated user. Dispatches
     * FetchLinkPreviewJob for any link whose preview is missing or older than
     * 24 hours (best-effort, does not block the response).
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — filters Link by user_id before processing.
     *
     * Response shape (LOCKED): { data: { [linkId]: { sparkline, trend, preview, health } } }
     *   preview: { favicon_url, og_title, og_image_url } | null
     *   health:  { status, last_checked_at, http_code }
     *
     * @param  Request  $request  Body: { ids: int[], days?: int (1–90, default 7) }
     */
    public function batchMeta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'integer|min:1',
            'days' => 'integer|min:1|max:90',
        ]);

        $ids = $validated['ids'];
        $days = $validated['days'] ?? 7;
        $userId = auth()->id();

        $links = Link::whereIn('id', $ids)
            ->where('user_id', $userId)
            ->get(['id', 'original_url', 'health_status', 'health_checked_at'])
            ->keyBy('id');

        $previews = LinkPreview::whereIn('link_id', $ids)->get()->keyBy('link_id');

        $result = [];
        foreach ($links as $id => $link) {
            $preview = $previews->get($id);

            if (! $preview || $preview->fetched_at->lt(now()->subDay())) {
                FetchLinkPreviewJob::dispatch((int) $id, $link->original_url);
            }

            $result[$id] = [
                'sparkline' => $this->metricsService->getLinkSparkline((int) $id, $days),
                'trend' => $this->metricsService->getLinkTrend((int) $id, 7),
                'preview' => $preview ? [
                    'favicon_url' => $preview->favicon_url,
                    'og_title' => $preview->og_title,
                    'og_image_url' => $preview->og_image_url,
                ] : null,
                'health' => [
                    'status' => $link->health_status ?? 'unknown',
                    'last_checked_at' => $link->health_checked_at?->toISOString(),
                    'http_code' => null,
                ],
            ];
        }

        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/links/{id}/sparkline?days=7
     *
     * Return daily click counts for the last N days as a sparkline array.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — queries Link by id + user_id (firstOrFail).
     *
     * Response shape (LOCKED): { data: SparklinePoint[] }
     *
     * @param  Request  $request  Query param: days (int, 1–90, default 7).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function sparkline(Request $request, int $id): JsonResponse
    {
        $link = Link::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        $days = max(1, min(90, (int) $request->query('days', 7)));

        return response()->json(['data' => $this->metricsService->getLinkSparkline($link->id, $days)]);
    }

    /**
     * GET /api/links/{id}/trend?window=7
     *
     * Return the click trend indicator (direction + delta percentage) comparing
     * the most recent N-day window to the previous N-day window.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — queries Link by id + user_id (firstOrFail).
     *
     * Response shape (LOCKED): { data: TrendResult }
     *
     * @param  Request  $request  Query param: window (int, 1–90, default 7).
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function trend(Request $request, int $id): JsonResponse
    {
        $link = Link::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        $window = max(1, min(90, (int) $request->query('window', 7)));

        return response()->json(['data' => $this->metricsService->getLinkTrend($link->id, $window)]);
    }

    /**
     * GET /api/links/{id}/preview
     *
     * Return the cached OG metadata (favicon, title, image) for the link's
     * original URL. If the preview is missing or stale (> 24 h), dispatches
     * FetchLinkPreviewJob asynchronously and returns the current (possibly null)
     * data immediately without blocking.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — queries Link by id + user_id (firstOrFail).
     *
     * Response shape (LOCKED): { data: { favicon_url, og_title, og_image_url } | null }
     *
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function preview(int $id): JsonResponse
    {
        $link = Link::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        $preview = LinkPreview::find($link->id);

        if (! $preview || $preview->fetched_at->lt(now()->subDay())) {
            FetchLinkPreviewJob::dispatch($link->id, $link->original_url);
        }

        return response()->json(['data' => $preview ? [
            'favicon_url' => $preview->favicon_url,
            'og_title' => $preview->og_title,
            'og_image_url' => $preview->og_image_url,
        ] : null]);
    }

    /**
     * GET /api/links/{id}/health
     *
     * Return the last known URL health status and when it was checked.
     * Health checks are performed asynchronously by a scheduled job; this
     * action only reads the cached result stored on the links table.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — queries Link by id + user_id (firstOrFail), selects
     *              id/health_status/health_checked_at only.
     *
     * Response shape (LOCKED): { data: { status, last_checked_at, http_code } }
     *   status: 'healthy' | 'unhealthy' | 'unknown'
     *   http_code: null (reserved for future use)
     *
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function health(int $id): JsonResponse
    {
        $link = Link::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail(['id', 'health_status', 'health_checked_at']);

        return response()->json(['data' => [
            'status' => $link->health_status ?? 'unknown',
            'last_checked_at' => $link->health_checked_at?->toISOString(),
            'http_code' => null,
        ]]);
    }
}
