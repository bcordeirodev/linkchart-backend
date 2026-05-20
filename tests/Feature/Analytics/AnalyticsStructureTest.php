<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Services\Analytics\GeographicAnalyticsService;
use App\Services\Analytics\Insights\InsightGeneratorRegistry;
use App\Services\Analytics\LinkAnalyticsOrchestrator;
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

    public function test_geographic_service_returns_heatmap_data_inside_link_geographic_payload(): void
    {
        $link = $this->makeLink();
        Click::factory()->count(2)->create([
            'link_id' => $link->id,
            'latitude' => -23.5,
            'longitude' => -46.6,
            'country' => 'Brazil',
        ]);

        $payload = app(GeographicAnalyticsService::class)->getLinkGeographicAnalytics($link->id);

        $this->assertArrayHasKey('data', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('heatmap_data', $payload['data']);
        $this->assertIsArray($payload['data']['heatmap_data']);

        if (count($payload['data']['heatmap_data']) > 0) {
            $point = $payload['data']['heatmap_data'][0];
            $this->assertArrayHasKey('lat', $point);
            $this->assertArrayHasKey('lng', $point);
            $this->assertArrayHasKey('clicks', $point);
        }

        $this->assertArrayHasKey('unique_states', $payload['meta']);
        $this->assertArrayHasKey('link_info', $payload['meta']);
    }

    public function test_orchestrator_all_public_methods_return_correct_top_level_keys(): void
    {
        $link = $this->makeLink();
        $this->seedClicks($link->id);
        $orch = app(LinkAnalyticsOrchestrator::class);

        $this->assertArrayHasKey('has_data', $orch->getComprehensiveLinkAnalytics($link->id));
        $this->assertArrayHasKey('summary', $orch->getLinkDashboardAnalytics($link->id));
        $this->assertArrayHasKey('top_countries', $orch->getLinkGeographicAnalytics($link->id)['data']);
        $this->assertArrayHasKey('clicks_by_hour', $orch->getLinkTemporalAnalytics($link->id));
        $this->assertArrayHasKey('device_breakdown', $orch->getLinkAudienceAnalytics($link->id));
        $this->assertArrayHasKey('insights', $orch->getLinkInsightsAnalytics($link->id));
    }
}
