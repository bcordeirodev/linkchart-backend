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
}
