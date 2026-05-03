<?php

namespace App\Http\Controllers\Links;

use App\Jobs\FetchLinkPreviewJob;
use App\Models\Link;
use App\Models\LinkPreview;
use App\Services\Analytics\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LinkMetaController extends Controller
{
    public function __construct(private MetricsService $metricsService) {}

    /**
     * Single batch call: sparkline + trend + preview + health for all requested IDs.
     *
     * POST /api/links/batch-meta
     * Body: { ids: int[], days?: int }
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
     */
    public function sparkline(Request $request, int $id): JsonResponse
    {
        $link = Link::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        $days = max(1, min(90, (int) $request->query('days', 7)));

        return response()->json(['data' => $this->metricsService->getLinkSparkline($link->id, $days)]);
    }

    /**
     * GET /api/links/{id}/trend?window=7
     */
    public function trend(Request $request, int $id): JsonResponse
    {
        $link = Link::where('id', $id)->where('user_id', auth()->id())->firstOrFail();
        $window = max(1, min(90, (int) $request->query('window', 7)));

        return response()->json(['data' => $this->metricsService->getLinkTrend($link->id, $window)]);
    }

    /**
     * GET /api/links/{id}/preview
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
