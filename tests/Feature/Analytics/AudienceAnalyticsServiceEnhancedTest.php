<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Services\Analytics\AudienceAnalyticsService;
use App\Services\Analytics\Support\UserAgentParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudienceAnalyticsServiceEnhancedTest extends TestCase
{
    use RefreshDatabase;

    private AudienceAnalyticsService $service;
    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();

        $user       = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $user->id]);
        $this->service = new AudienceAnalyticsService(new UserAgentParser());
    }

    public function test_navigation_context_breakdown_returns_counts_and_percentages(): void
    {
        Click::factory()->count(45)->create(['link_id' => $this->link->id, 'navigation_context' => 'browser_direct']);
        Click::factory()->count(31)->create(['link_id' => $this->link->id, 'navigation_context' => 'browser_referral']);
        Click::factory()->count(18)->create(['link_id' => $this->link->id, 'navigation_context' => 'in_app_webview']);
        Click::factory()->count(6)->create(['link_id' => $this->link->id, 'navigation_context' => 'api_programmatic']);

        $result    = $this->service->getLinkAudienceAnalytics($this->link->id);
        $breakdown = $result['navigation_context_breakdown'];

        $this->assertNotEmpty($breakdown);

        $direct = collect($breakdown)->firstWhere('context', 'browser_direct');
        $this->assertNotNull($direct);
        $this->assertEquals(45, $direct['clicks']);
        $this->assertEquals(45.0, $direct['percentage']);
    }

    public function test_navigation_context_breakdown_omits_unknown_below_one_percent(): void
    {
        Click::factory()->count(99)->create(['link_id' => $this->link->id, 'navigation_context' => 'browser_direct']);
        // 1 clique com NULL → < 1% → não deve aparecer
        Click::factory()->create(['link_id' => $this->link->id, 'navigation_context' => null]);

        $result    = $this->service->getLinkAudienceAnalytics($this->link->id);
        $breakdown = $result['navigation_context_breakdown'];

        $unknown = collect($breakdown)->firstWhere('context', 'unknown');
        $this->assertNull($unknown);
    }

    public function test_navigation_context_breakdown_empty_when_no_clicks(): void
    {
        $result = $this->service->getLinkAudienceAnalytics($this->link->id);
        $this->assertSame([], $result['navigation_context_breakdown']);
    }

    public function test_return_visitor_stats_calculates_rate_correctly(): void
    {
        Click::factory()->count(34)->create(['link_id' => $this->link->id, 'is_return_visitor' => true,  'session_clicks' => 3]);
        Click::factory()->count(66)->create(['link_id' => $this->link->id, 'is_return_visitor' => false, 'session_clicks' => 1]);

        $result = $this->service->getLinkAudienceAnalytics($this->link->id);
        $stats  = $result['return_visitor_stats'];

        $this->assertEquals(34.0, $stats['return_rate']);
        $this->assertEquals(66.0, $stats['new_rate']);
        // avg: (34*3 + 66*1) / 100 = (102+66)/100 = 1.68
        $this->assertEquals(1.68, $stats['avg_session_clicks']);
    }

    public function test_return_visitor_stats_zeros_when_no_clicks(): void
    {
        $result = $this->service->getLinkAudienceAnalytics($this->link->id);
        $stats  = $result['return_visitor_stats'];

        $this->assertSame(0.0, $stats['return_rate']);
        $this->assertSame(0.0, $stats['new_rate']);
        $this->assertSame(0.0, $stats['avg_session_clicks']);
    }

    public function test_quality_breakdown_returns_tier_distribution_and_bot_rate(): void
    {
        Click::factory()->count(72)->create(['link_id' => $this->link->id, 'quality_tier' => 'organic',      'is_bot' => false, 'fingerprint_score' => 0]);
        Click::factory()->count(22)->create(['link_id' => $this->link->id, 'quality_tier' => 'suspicious',   'is_bot' => false, 'fingerprint_score' => 1]);
        Click::factory()->count(6)->create( ['link_id' => $this->link->id, 'quality_tier' => 'likely_fraud', 'is_bot' => true,  'fingerprint_score' => 2]);

        $result    = $this->service->getLinkAudienceAnalytics($this->link->id);
        $quality   = $result['quality_breakdown'];

        $this->assertCount(3, $quality['tiers']);

        $organic = collect($quality['tiers'])->firstWhere('tier', 'organic');
        $this->assertEquals(72, $organic['clicks']);
        $this->assertEquals(72.0, $organic['percentage']);

        $this->assertEquals(6, $quality['bot_clicks']);
        $this->assertEquals(6.0, $quality['bot_percentage']);

        // avg fingerprint: (72*0 + 22*1 + 6*2) / 100 = (0+22+12)/100 = 0.34
        $this->assertEquals(0.34, $quality['avg_fingerprint_score']);
    }

    public function test_quality_breakdown_excludes_null_tiers_from_donut(): void
    {
        // Cliques sem quality_tier (anteriores à Fase 3) não devem aparecer no donut
        Click::factory()->count(10)->create(['link_id' => $this->link->id, 'quality_tier' => null, 'is_bot' => false]);
        Click::factory()->count(5)->create(['link_id' => $this->link->id, 'quality_tier' => 'organic', 'is_bot' => false]);

        $result  = $this->service->getLinkAudienceAnalytics($this->link->id);
        $quality = $result['quality_breakdown'];

        $this->assertCount(1, $quality['tiers']);
        // bot_percentage uses total (15) as denominator
        $this->assertEquals(0.0, $quality['bot_percentage']);
    }

    public function test_quality_breakdown_zeros_when_no_clicks(): void
    {
        $result  = $this->service->getLinkAudienceAnalytics($this->link->id);
        $quality = $result['quality_breakdown'];

        $this->assertSame([], $quality['tiers']);
        $this->assertSame(0, $quality['bot_clicks']);
        $this->assertSame(0.0, $quality['bot_percentage']);
        $this->assertSame(0.0, $quality['avg_fingerprint_score']);
    }
}
