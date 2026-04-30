<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Services\Analytics\AudienceAnalyticsService;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Services\Analytics\GeographicAnalyticsService;
use App\Services\Analytics\Insights\InsightGeneratorRegistry;
use App\Services\Analytics\InsightsAnalyticsService;
use App\Services\Analytics\LinkAnalyticsService;
use App\Services\Analytics\Support\UserAgentParser;
use App\Services\Analytics\TemporalAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

class AnalyticsStructureTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    private function seedClicks(int $linkId, int $count = 3): void
    {
        Click::factory()->count($count)->create(['link_id' => $linkId]);
    }

    public function test_click_factory_produces_iso_day_of_week(): void
    {
        $link = $this->makeLink();
        $clicks = Click::factory()->count(30)->create(['link_id' => $link->id]);

        foreach ($clicks as $click) {
            $this->assertGreaterThanOrEqual(1, $click->day_of_week);
            $this->assertLessThanOrEqual(7, $click->day_of_week);
        }
    }

    public function test_comprehensive_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getComprehensiveLinkAnalytics($link->id);

        $this->assertTrue($result['has_data']);
        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('geographic', $result);
        $this->assertArrayHasKey('temporal', $result);
        $this->assertArrayHasKey('audience', $result);
        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('total_clicks', $result['overview']);
        $this->assertArrayHasKey('unique_visitors', $result['overview']);
    }

    public function test_dashboard_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('temporal_data', $result);
        $this->assertArrayHasKey('geographic_data', $result);
        $this->assertArrayHasKey('audience_data', $result);
        $this->assertArrayHasKey('total_clicks', $result['summary']);
    }

    public function test_dashboard_hours_filter_reduces_click_count(): void
    {
        $link = $this->makeLink();
        Click::factory()->count(2)->create(['link_id' => $link->id, 'created_at' => now()->subHours(2)]);
        Click::factory()->count(5)->create(['link_id' => $link->id, 'created_at' => now()->subDays(7)]);

        $allTime = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 0);
        $last24h = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 24);

        $this->assertSame(7, $allTime['summary']['total_clicks']);
        $this->assertSame(2, $last24h['summary']['total_clicks']);
    }

    public function test_geographic_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkGeographicAnalytics($link->id);

        $this->assertArrayHasKey('top_countries', $result);
        $this->assertArrayHasKey('top_states', $result);
        $this->assertArrayHasKey('top_cities', $result);
    }

    public function test_temporal_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkTemporalAnalytics($link->id);

        $this->assertArrayHasKey('clicks_by_hour', $result);
        $this->assertArrayHasKey('clicks_by_day_of_week', $result);
        $this->assertCount(24, $result['clicks_by_hour']);
        $this->assertCount(7, $result['clicks_by_day_of_week']);
    }

    public function test_audience_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkAudienceAnalytics($link->id);

        $this->assertArrayHasKey('device_breakdown', $result);
        $this->assertArrayHasKey('browser_breakdown', $result);
        $this->assertArrayHasKey('os_breakdown', $result);
    }

    public function test_insights_analytics_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(LinkAnalyticsService::class)->getLinkInsightsAnalytics($link->id);

        $this->assertArrayHasKey('insights', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('analytics_data', $result);
        $this->assertArrayHasKey('total_insights', $result['summary']);
        $this->assertArrayHasKey('retention', $result['analytics_data']);
        $this->assertArrayHasKey('session_depth', $result['analytics_data']);
        $this->assertArrayHasKey('traffic_sources', $result['analytics_data']);
    }

    public function test_dashboard_service_matches_monolith_structure(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $legacy = app(LinkAnalyticsService::class)->getLinkDashboardAnalytics($link->id);
        $service = app(DashboardAnalyticsService::class)->getLinkDashboardAnalytics($link->id);

        $this->assertSame(array_keys($legacy), array_keys($service));
        $this->assertSame(array_keys($legacy['summary']), array_keys($service['summary']));
    }

    public function test_dashboard_service_since_filter_works(): void
    {
        $link = $this->makeLink();
        Click::factory()->count(2)->create(['link_id' => $link->id, 'created_at' => now()->subHours(2)]);
        Click::factory()->count(5)->create(['link_id' => $link->id, 'created_at' => now()->subDays(7)]);

        $allTime = app(DashboardAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 0);
        $last24h = app(DashboardAnalyticsService::class)->getLinkDashboardAnalytics($link->id, 24);

        $this->assertSame(7, $allTime['summary']['total_clicks']);
        $this->assertSame(2, $last24h['summary']['total_clicks']);
    }

    public function test_ua_parser_identifies_chrome(): void
    {
        $parser = new UserAgentParser;
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';
        $this->assertSame('Chrome', $parser->extractBrowser($ua));
    }

    public function test_ua_parser_identifies_android(): void
    {
        $parser = new UserAgentParser;
        $ua = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36';
        $this->assertSame('Android', $parser->extractOS($ua));
    }

    public function test_ua_parser_extracts_primary_language(): void
    {
        $parser = new UserAgentParser;
        $this->assertSame('Português (Brasil)', $parser->extractPrimaryLanguage('pt-BR,pt;q=0.9,en;q=0.8'));
        $this->assertNull($parser->extractPrimaryLanguage(null));
    }

    public function test_geographic_service_matches_monolith_structure(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $legacy = app(LinkAnalyticsService::class)->getLinkGeographicAnalytics($link->id);
        $service = app(GeographicAnalyticsService::class)->getLinkGeographicAnalytics($link->id);

        $this->assertSame(array_keys($legacy), array_keys($service));
    }

    public function test_temporal_service_matches_monolith_structure(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $legacy = app(LinkAnalyticsService::class)->getLinkTemporalAnalytics($link->id);
        $service = app(TemporalAnalyticsService::class)->getLinkTemporalAnalytics($link->id);

        $this->assertSame(array_keys($legacy), array_keys($service));
        $this->assertCount(24, $service['clicks_by_hour']);
        $this->assertCount(7, $service['clicks_by_day_of_week']);
    }

    public function test_temporal_service_advanced_has_required_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $result = app(TemporalAnalyticsService::class)->getAdvancedTemporalAnalytics($link->id);

        $this->assertArrayHasKey('peak_analysis', $result);
        $this->assertArrayHasKey('timezone_analysis', $result);
        $this->assertArrayHasKey('weekly_trends', $result);
        $this->assertArrayHasKey('monthly_trends', $result);
    }

    public function test_audience_service_matches_monolith_structure(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $legacy = app(LinkAnalyticsService::class)->getLinkAudienceAnalytics($link->id);
        $service = app(AudienceAnalyticsService::class)->getLinkAudienceAnalytics($link->id);

        $this->assertSame(array_keys($legacy), array_keys($service));
    }

    public function test_insights_service_matches_monolith_structure(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);

        $legacy = app(LinkAnalyticsService::class)->getLinkInsightsAnalytics($link->id);
        $service = app(InsightsAnalyticsService::class)->getLinkInsightsAnalytics($link->id);

        $this->assertSame(array_keys($legacy), array_keys($service));
        $this->assertSame(array_keys($legacy['summary']), array_keys($service['summary']));
        $this->assertSame(array_keys($legacy['analytics_data']), array_keys($service['analytics_data']));
    }

    public function test_insight_registry_produces_valid_insight_shapes(): void
    {
        $link = $this->makeLink();
        Click::factory()->count(10)->create(['link_id' => $link->id, 'country' => 'Brazil']);

        $registry = app(InsightGeneratorRegistry::class);
        $insights = $registry->generate($link->id, 10);

        $this->assertIsArray($insights);
        foreach ($insights as $insight) {
            $this->assertArrayHasKey('type', $insight);
            $this->assertArrayHasKey('title', $insight);
            $this->assertArrayHasKey('priority', $insight);
            $this->assertArrayHasKey('confidence', $insight);
        }
    }

    public function test_geographic_service_heatmap_returns_valid_structure(): void
    {
        $link = $this->makeLink();
        Click::factory()->count(2)->create([
            'link_id' => $link->id,
            'latitude' => -23.5,
            'longitude' => -46.6,
            'country' => 'Brazil',
        ]);

        $result = app(GeographicAnalyticsService::class)->getHeatmapData($link->id);

        $this->assertIsArray($result);
        if (count($result) > 0) {
            $this->assertArrayHasKey('lat', $result[0]);
            $this->assertArrayHasKey('lng', $result[0]);
            $this->assertArrayHasKey('clicks', $result[0]);
        }
    }
}
