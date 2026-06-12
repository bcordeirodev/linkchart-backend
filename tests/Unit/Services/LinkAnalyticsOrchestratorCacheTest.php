<?php

namespace Tests\Unit\Services;

use App\Contracts\Analytics\AudienceAnalyticsInterface;
use App\Contracts\Analytics\DashboardAnalyticsInterface;
use App\Contracts\Analytics\GeographicAnalyticsInterface;
use App\Contracts\Analytics\InsightsAnalyticsInterface;
use App\Contracts\Analytics\TemporalAnalyticsInterface;
use App\DTOs\Analytics\AnalyticsFilters;
use App\Services\Analytics\LinkAnalyticsOrchestrator;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Verifies the orchestrator memoizes delegate calls in the cache (60s TTL)
 * and that distinct filters produce distinct cache entries.
 */
class LinkAnalyticsOrchestratorCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Builds an orchestrator where only the dashboard delegate has expectations. */
    private function makeOrchestrator(DashboardAnalyticsInterface $dashboard): LinkAnalyticsOrchestrator
    {
        return new LinkAnalyticsOrchestrator(
            $dashboard,
            Mockery::mock(GeographicAnalyticsInterface::class),
            Mockery::mock(TemporalAnalyticsInterface::class),
            Mockery::mock(AudienceAnalyticsInterface::class),
            Mockery::mock(InsightsAnalyticsInterface::class),
        );
    }

    public function test_dashboard_delegate_called_once_for_repeated_requests(): void
    {
        $dashboard = Mockery::mock(DashboardAnalyticsInterface::class);
        $dashboard->shouldReceive('getLinkDashboardAnalytics')
            ->once()
            ->andReturn(['total_clicks' => 7]);

        $orchestrator = $this->makeOrchestrator($dashboard);

        $first = $orchestrator->getLinkDashboardAnalytics(1);
        $second = $orchestrator->getLinkDashboardAnalytics(1);

        $this->assertSame(['total_clicks' => 7], $first);
        $this->assertSame($first, $second);
    }

    public function test_distinct_filters_are_cached_separately(): void
    {
        $dashboard = Mockery::mock(DashboardAnalyticsInterface::class);
        $dashboard->shouldReceive('getLinkDashboardAnalytics')
            ->twice()
            ->andReturn(['total_clicks' => 1], ['total_clicks' => 2]);

        $orchestrator = $this->makeOrchestrator($dashboard);

        $all = $orchestrator->getLinkDashboardAnalytics(1);
        $botless = $orchestrator->getLinkDashboardAnalytics(1, new AnalyticsFilters(excludeBots: true));

        $this->assertNotSame($all, $botless);
    }
}
