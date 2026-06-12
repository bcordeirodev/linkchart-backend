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

    /**
     * Repeated calls with the same parameters must hit the delegate only once;
     * subsequent calls are served from the cache.
     */
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

    /**
     * Different filter instances that produce different cache keys must each
     * invoke the delegate independently, resulting in separate cache entries.
     */
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

    /** When the cache backend fails on read, the producer runs uncached. */
    public function test_degrades_to_uncached_when_cache_read_fails(): void
    {
        $dashboard = Mockery::mock(DashboardAnalyticsInterface::class);
        $dashboard->shouldReceive('getLinkDashboardAnalytics')->once()->andReturn(['total_clicks' => 3]);

        // Swap to a fresh mock that throws on remember() so Cache::flush() in setUp
        // is not affected and the real cache driver is not invoked for this test.
        Cache::swap(Mockery::mock(\Illuminate\Contracts\Cache\Repository::class));
        Cache::shouldReceive('remember')->once()->andThrow(new \RuntimeException('redis down'));

        $orchestrator = $this->makeOrchestrator($dashboard);

        $this->assertSame(['total_clicks' => 3], $orchestrator->getLinkDashboardAnalytics(1));
    }

    /** When the producer itself throws, the exception propagates without a re-run. */
    public function test_producer_exception_propagates_without_rerun(): void
    {
        $dashboard = Mockery::mock(DashboardAnalyticsInterface::class);
        $dashboard->shouldReceive('getLinkDashboardAnalytics')
            ->once()
            ->andThrow(new \RuntimeException('db timeout'));

        $orchestrator = $this->makeOrchestrator($dashboard);

        $this->expectException(\RuntimeException::class);
        $orchestrator->getLinkDashboardAnalytics(1);
    }
}
