<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\AudienceAnalyticsInterface;
use App\Contracts\Analytics\DashboardAnalyticsInterface;
use App\Contracts\Analytics\GeographicAnalyticsInterface;
use App\Contracts\Analytics\InsightsAnalyticsInterface;
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\Models\Click;
use App\Models\Link;

class LinkAnalyticsOrchestrator
{
    public function __construct(
        private readonly DashboardAnalyticsInterface $dashboard,
        private readonly GeographicAnalyticsInterface $geographic,
        private readonly TemporalAnalyticsInterface $temporal,
        private readonly AudienceAnalyticsInterface $audience,
        private readonly InsightsAnalyticsInterface $insights,
    ) {}

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
            'geographic' => array_merge(
                ['heatmap_data' => $this->geographic->getHeatmapData($linkId)],
                $this->geographic->getLinkGeographicAnalytics($linkId)
            ),
            'temporal' => $this->temporal->getLinkTemporalAnalytics($linkId),
            'audience' => $this->audience->getLinkAudienceAnalytics($linkId),
            'insights' => $insightsPayload['insights'] ?? [],
        ];
    }

    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array
    {
        return $this->dashboard->getLinkDashboardAnalytics($linkId, $hours);
    }

    public function getLinkGeographicAnalytics(int $linkId): array
    {
        return $this->geographic->getLinkGeographicAnalytics($linkId);
    }

    public function getLinkTemporalAnalytics(int $linkId): array
    {
        return $this->temporal->getLinkTemporalAnalytics($linkId);
    }

    public function getLinkAudienceAnalytics(int $linkId): array
    {
        return $this->audience->getLinkAudienceAnalytics($linkId);
    }

    public function getLinkInsightsAnalytics(int $linkId): array
    {
        return $this->insights->getLinkInsightsAnalytics($linkId);
    }

    public function getHeatmapData(int $linkId): array
    {
        return $this->geographic->getHeatmapData($linkId);
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
