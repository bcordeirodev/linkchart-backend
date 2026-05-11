<?php

namespace App\Contracts\Analytics;

/**
 * Contract for the analytics orchestrator that fans out to specialized
 * analytics services (dashboard, geographic, temporal, audience, insights).
 *
 * Implementation: App\Services\Analytics\LinkAnalyticsOrchestrator.
 * Bound in App\Providers\AppServiceProvider::register().
 */
interface LinkAnalyticsOrchestratorInterface
{
    /**
     * Returns a comprehensive analytics payload for the given link,
     * merging geographic, temporal, audience, and insights data.
     */
    public function getComprehensiveLinkAnalytics(int $linkId): array;

    /**
     * Returns dashboard analytics for the given link, optionally filtered
     * to the last N hours.
     */
    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array;

    /**
     * Returns geographic analytics (countries, regions, cities) for the given link.
     */
    public function getLinkGeographicAnalytics(int $linkId): array;

    /**
     * Returns temporal analytics (clicks by hour, day of week, etc.) for the given link.
     */
    public function getLinkTemporalAnalytics(int $linkId): array;

    /**
     * Returns audience analytics (devices, browsers, OS, engagement) for the given link.
     */
    public function getLinkAudienceAnalytics(int $linkId): array;

    /**
     * Returns business insights (automated insights + summary + analytics data)
     * for the given link.
     */
    public function getLinkInsightsAnalytics(int $linkId): array;
}
