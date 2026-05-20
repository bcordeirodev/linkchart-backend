<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\LinkUtm;
use App\Models\User;
use App\Services\Analytics\DashboardAnalyticsService;
use App\Services\Analytics\TemporalAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardAnalyticsService $service;
    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $user->id]);
        $this->service = app(DashboardAnalyticsService::class);
    }

    public function test_utm_top_sources_returns_top_5_sorted_by_clicks(): void
    {
        $instagram = Click::factory()->count(40)->create(['link_id' => $this->link->id]);
        foreach ($instagram as $click) {
            LinkUtm::create(['click_id' => $click->id, 'utm_source' => 'instagram']);
        }
        $tiktok = Click::factory()->count(25)->create(['link_id' => $this->link->id]);
        foreach ($tiktok as $click) {
            LinkUtm::create(['click_id' => $click->id, 'utm_source' => 'tiktok']);
        }
        // Click without UTM — should not appear
        Click::factory()->create(['link_id' => $this->link->id]);

        $result = $this->service->getLinkDashboardAnalytics($this->link->id);
        $sources = $result['summary']['utm_top_sources'];

        $this->assertNotEmpty($sources);
        $this->assertSame('instagram', $sources[0]['source']);
        $this->assertSame(40, $sources[0]['clicks']);
        $this->assertSame('tiktok', $sources[1]['source']);
        $this->assertEqualsWithDelta(61.5, $sources[0]['percentage'], 0.2);
    }

    public function test_utm_top_sources_returns_empty_array_when_no_utm(): void
    {
        Click::factory()->count(5)->create(['link_id' => $this->link->id]);

        $result = $this->service->getLinkDashboardAnalytics($this->link->id);

        $this->assertSame([], $result['summary']['utm_top_sources']);
    }

    public function test_social_iab_counts_in_app_webview_mobile_clicks(): void
    {
        Click::factory()->count(60)->create([
            'link_id' => $this->link->id,
            'navigation_context' => 'in_app_webview',
            'is_mobile' => 1,
            'os' => 'iOS',
        ]);
        Click::factory()->count(40)->create([
            'link_id' => $this->link->id,
            'navigation_context' => 'in_app_webview',
            'is_mobile' => 1,
            'os' => 'Android',
        ]);
        // Desktop IAB — should NOT count
        Click::factory()->count(10)->create([
            'link_id' => $this->link->id,
            'navigation_context' => 'in_app_webview',
            'is_mobile' => 0,
        ]);

        $result = $this->service->getLinkDashboardAnalytics($this->link->id);
        $iab = $result['summary']['social_iab'];

        $this->assertSame(100, $iab['total']);
        $this->assertEqualsWithDelta(90.9, $iab['percentage'], 0.2); // 100/110
        $this->assertEqualsWithDelta(60.0, $iab['ios_pct'], 0.1);
        $this->assertEqualsWithDelta(40.0, $iab['android_pct'], 0.1);
    }

    public function test_social_iab_returns_zeros_when_no_iab_clicks(): void
    {
        Click::factory()->count(5)->create([
            'link_id' => $this->link->id,
            'navigation_context' => 'browser_direct',
        ]);

        $result = $this->service->getLinkDashboardAnalytics($this->link->id);
        $iab = $result['summary']['social_iab'];

        $this->assertSame(0, $iab['total']);
        $this->assertSame(0.0, $iab['percentage']);
    }

    public function test_viral_rank_by_day_returns_peak_rank_per_day(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $link = Link::factory()->create(['user_id' => $user->id]);

        // Day 1: all cold
        Click::factory()->count(5)->create([
            'link_id' => $link->id,
            'viral_rank' => 'cold',
            'created_at' => '2026-05-17 10:00:00',
        ]);
        // Day 2: mix of warming and trending → peak = trending
        Click::factory()->count(3)->create([
            'link_id' => $link->id,
            'viral_rank' => 'warming',
            'created_at' => '2026-05-18 10:00:00',
        ]);
        Click::factory()->count(2)->create([
            'link_id' => $link->id,
            'viral_rank' => 'trending',
            'created_at' => '2026-05-18 14:00:00',
        ]);
        // Day 3: viral
        Click::factory()->count(8)->create([
            'link_id' => $link->id,
            'viral_rank' => 'viral',
            'created_at' => '2026-05-19 09:00:00',
        ]);
        // Click with null viral_rank — should be excluded
        Click::factory()->create(['link_id' => $link->id, 'viral_rank' => null]);

        $service = app(TemporalAnalyticsService::class);
        $result = $service->getLinkTemporalAnalytics($link->id);
        $byDay = $result['viral_rank_by_day'];

        $this->assertCount(3, $byDay);

        $day1 = collect($byDay)->firstWhere('date', '2026-05-17');
        $this->assertSame('cold', $day1['peak_rank']);
        $this->assertSame(5, $day1['click_count']);

        $day2 = collect($byDay)->firstWhere('date', '2026-05-18');
        $this->assertSame('trending', $day2['peak_rank']); // trending > warming

        $day3 = collect($byDay)->firstWhere('date', '2026-05-19');
        $this->assertSame('viral', $day3['peak_rank']);
        $this->assertSame(8, $day3['click_count']);
    }
}
