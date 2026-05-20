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

        $day1Date = now()->subDays(5)->format('Y-m-d');
        $day2Date = now()->subDays(4)->format('Y-m-d');
        $day3Date = now()->subDays(3)->format('Y-m-d');

        // Day 1: all cold
        Click::factory()->count(5)->create([
            'link_id'    => $link->id,
            'viral_rank' => 'cold',
            'created_at' => now()->subDays(5)->format('Y-m-d') . ' 10:00:00',
        ]);
        // Day 2: mix of warming and trending → peak = trending
        Click::factory()->count(3)->create([
            'link_id'    => $link->id,
            'viral_rank' => 'warming',
            'created_at' => now()->subDays(4)->format('Y-m-d') . ' 10:00:00',
        ]);
        Click::factory()->count(2)->create([
            'link_id'    => $link->id,
            'viral_rank' => 'trending',
            'created_at' => now()->subDays(4)->format('Y-m-d') . ' 14:00:00',
        ]);
        // Day 3: viral
        Click::factory()->count(8)->create([
            'link_id'    => $link->id,
            'viral_rank' => 'viral',
            'created_at' => now()->subDays(3)->format('Y-m-d') . ' 09:00:00',
        ]);
        // Click with null viral_rank — excluded by whereNotNull; date is outside 90-day window
        Click::factory()->create([
            'link_id'    => $link->id,
            'viral_rank' => null,
            'created_at' => now()->subDays(200)->startOfDay()->toDateTimeString(),
        ]);

        $service = app(TemporalAnalyticsService::class);
        $result = $service->getLinkTemporalAnalytics($link->id);
        $byDay = $result['viral_rank_by_day'];

        $this->assertCount(3, $byDay);

        $day1 = collect($byDay)->firstWhere('date', $day1Date);
        $this->assertSame('cold', $day1['peak_rank']);
        $this->assertSame(5, $day1['click_count']);

        $day2 = collect($byDay)->firstWhere('date', $day2Date);
        $this->assertSame('trending', $day2['peak_rank']); // trending > warming
        $this->assertSame(5, $day2['click_count']);        // 3 warming + 2 trending

        $day3 = collect($byDay)->firstWhere('date', $day3Date);
        $this->assertSame('viral', $day3['peak_rank']);
        $this->assertSame(8, $day3['click_count']);
    }
}
