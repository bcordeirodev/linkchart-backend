<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\AudienceAnalyticsInterface;
use App\Contracts\Analytics\DashboardAnalyticsInterface;
use App\Contracts\Analytics\GeographicAnalyticsInterface;
use App\Contracts\Analytics\InsightsAnalyticsInterface;
use App\Contracts\Analytics\LinkAnalyticsOrchestratorInterface;
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\Models\Click;
use App\Models\Link;

/**
 * Thin orchestrator that fans out analytics requests to specialized services.
 *
 * @see \App\Contracts\Analytics\LinkAnalyticsOrchestratorInterface
 *
 * Injected by AnalyticsController to serve all analytics endpoints. Each public
 * method delegates directly to the appropriate specialized service:
 *   - getLinkDashboardAnalytics → DashboardAnalyticsInterface
 *   - getLinkGeographicAnalytics → GeographicAnalyticsInterface
 *   - getLinkTemporalAnalytics → TemporalAnalyticsInterface
 *   - getLinkAudienceAnalytics → AudienceAnalyticsInterface
 *   - getLinkInsightsAnalytics → InsightsAnalyticsInterface
 *   - getComprehensiveLinkAnalytics → fans out to all five and merges results
 *
 * No aggregation logic lives in this class — it exists solely to provide a
 * single injectable contract for the controller and to assemble the comprehensive
 * endpoint payload.
 *
 * Side effects: none directly — all side effects belong to the delegate services.
 */
class LinkAnalyticsOrchestrator implements LinkAnalyticsOrchestratorInterface
{
    public function __construct(
        private readonly DashboardAnalyticsInterface $dashboard,
        private readonly GeographicAnalyticsInterface $geographic,
        private readonly TemporalAnalyticsInterface $temporal,
        private readonly AudienceAnalyticsInterface $audience,
        private readonly InsightsAnalyticsInterface $insights,
    ) {}

    /**
     * Returns a merged payload combining overview, geographic, temporal, audience, and insights.
     *
     * Returns a short has_data=false payload if the link has no clicks yet.
     * Otherwise fans out to all five specialized services and merges the results.
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed> Keys: has_data, link_info, overview, geographic, temporal, audience, insights.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If link does not exist.
     */
    public function getComprehensiveLinkAnalytics(int $linkId): array
    {
        $link = Link::findOrFail($linkId);

        if (! Click::where('link_id', $linkId)->exists()) {
            return [
                'has_data' => false,
                'link_info' => $this->linkInfo($link),
                'message' => 'Analytics will be available after the first clicks on your link.',
            ];
        }

        $total = Click::where('link_id', $linkId)->count();
        $unique = Click::where('link_id', $linkId)->distinct('ip')->count();
        $regions = Click::where('link_id', $linkId)->whereNotNull('country')->where('country', '!=', 'localhost')->distinct('country')->count();

        $insightsPayload = $this->insights->getLinkInsightsAnalytics($linkId);

        return [
            'has_data' => true,
            'link_info' => $this->linkInfo($link),
            'overview' => [
                'total_clicks' => $total,
                'unique_visitors' => $unique,
                'countries_reached' => $regions,
                'avg_daily_clicks' => $total > 0 ? round($total / 30, 1) : 0,
            ],
            'geographic' => $this->geographic->getLinkGeographicAnalytics($linkId),
            'temporal' => $this->temporal->getLinkTemporalAnalytics($linkId),
            'audience' => $this->audience->getLinkAudienceAnalytics($linkId),
            'insights' => $insightsPayload['insights'] ?? [],
        ];
    }

    /**
     * Delegates to DashboardAnalyticsService::getLinkDashboardAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $hours  Time window in hours (0 = all time).
     * @return array<string, mixed>
     */
    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array
    {
        return $this->dashboard->getLinkDashboardAnalytics($linkId, $hours);
    }

    /**
     * Delegates to GeographicAnalyticsService::getLinkGeographicAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed>
     */
    public function getLinkGeographicAnalytics(int $linkId): array
    {
        return $this->geographic->getLinkGeographicAnalytics($linkId);
    }

    /**
     * Delegates to TemporalAnalyticsService::getLinkTemporalAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed>
     */
    public function getLinkTemporalAnalytics(int $linkId): array
    {
        return $this->temporal->getLinkTemporalAnalytics($linkId);
    }

    /**
     * Delegates to AudienceAnalyticsService::getLinkAudienceAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed>
     */
    public function getLinkAudienceAnalytics(int $linkId): array
    {
        return $this->audience->getLinkAudienceAnalytics($linkId);
    }

    /**
     * Delegates to InsightsAnalyticsService::getLinkInsightsAnalytics.
     *
     * @param  int  $linkId  Link primary key.
     * @return array<string, mixed>
     */
    public function getLinkInsightsAnalytics(int $linkId): array
    {
        return $this->insights->getLinkInsightsAnalytics($linkId);
    }

    private function linkInfo(Link $link): array
    {
        return [
            'id' => $link->id,
            'title' => $link->title,
            'short_url' => $link->getShortedUrl(),
            'original_url' => $link->original_url,
            'clicks' => $link->clicks,
            'is_active' => $link->is_active,
            'created_at' => $link->created_at,
        ];
    }
}
