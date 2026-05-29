<?php

namespace Tests\Feature\Onboarding;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Services\Onboarding\OnboardingDemoDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for OnboardingDemoDataService::run().
 */
class OnboardingDemoDataServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent UserObserver from firing SeedDemoLinkJob when factory creates users.
        Queue::fake();
    }

    /** @test */
    public function test_creates_demo_link_with_correct_title_and_click_count(): void
    {
        $user = User::factory()->create();

        (new OnboardingDemoDataService)->run($user);

        $link = Link::where('user_id', $user->id)->where('is_demo', true)->first();
        $this->assertNotNull($link);
        $this->assertSame('📊 Demo Link — See Analytics in Action', $link->title);
        $this->assertSame(1247, (int) $link->clicks);
        $this->assertDatabaseCount('clicks', 1247);
    }

    /** @test */
    public function test_clicks_have_browser_and_os_fields_populated(): void
    {
        $user = User::factory()->create();
        (new OnboardingDemoDataService)->run($user);

        $click = Click::first();
        $this->assertNotNull($click->browser);
        $this->assertNotNull($click->browser_version);
        $this->assertNotNull($click->os);
        $this->assertNotNull($click->rendering_engine);
    }

    /** @test */
    public function test_clicks_have_temporal_fields_populated(): void
    {
        $user = User::factory()->create();
        (new OnboardingDemoDataService)->run($user);

        $click = Click::first();
        $this->assertNotNull($click->hour_of_day);
        $this->assertNotNull($click->day_of_week);
        $this->assertGreaterThanOrEqual(1, (int) $click->day_of_week);
        $this->assertLessThanOrEqual(7, (int) $click->day_of_week);
        $this->assertNotNull($click->season);
        $this->assertContains($click->season, ['spring', 'summer', 'fall', 'winter']);
        $this->assertNotNull($click->local_time);
    }

    /** @test */
    public function test_clicks_have_quality_fields_populated(): void
    {
        $user = User::factory()->create();
        (new OnboardingDemoDataService)->run($user);

        $click = Click::first();
        $this->assertNotNull($click->quality_tier);
        $this->assertContains($click->quality_tier, ['organic', 'suspicious', 'likely_fraud']);
        $this->assertNotNull($click->quality_score);
        $this->assertNotNull($click->connection_type);
        $this->assertNotNull($click->viral_rank);
    }

    /** @test */
    public function test_clicks_have_traffic_source_fields_populated(): void
    {
        $user = User::factory()->create();
        (new OnboardingDemoDataService)->run($user);

        $click = Click::whereNotNull('referer')->first();
        $this->assertNotNull($click);
        $this->assertNotNull($click->click_source);
        $this->assertContains($click->click_source, ['direct', 'social', 'search', 'referral']);
    }

    /** @test */
    public function test_social_clicks_have_social_platform_set(): void
    {
        $user = User::factory()->create();
        (new OnboardingDemoDataService)->run($user);

        $socialClick = Click::where('click_source', 'social')->first();
        $this->assertNotNull($socialClick, 'Expected at least one social click in 1247');
        $this->assertNotNull($socialClick->social_platform);
        $this->assertContains($socialClick->social_platform, ['facebook', 'twitter', 'instagram', 'linkedin', 'tiktok']);
    }

    /** @test */
    public function test_clicks_have_language_fields_populated(): void
    {
        $user = User::factory()->create();
        (new OnboardingDemoDataService)->run($user);

        $click = Click::first();
        $this->assertNotNull($click->primary_language);
        $this->assertNotNull($click->language_region);
        $this->assertNotNull($click->accept_language);
    }

    /** @test */
    public function test_run_is_idempotent(): void
    {
        $user = User::factory()->create();
        $service = new OnboardingDemoDataService;

        $service->run($user);
        $service->run($user); // second call must be a no-op

        $this->assertSame(1, Link::where('user_id', $user->id)->where('is_demo', true)->count());
        $this->assertDatabaseCount('clicks', 1247);
    }
}
