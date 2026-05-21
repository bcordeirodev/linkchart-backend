<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for InsightsAnalyticsService filter support.
 *
 * Verifies that the insights endpoint correctly applies date-range and
 * bot-exclusion filters, and that analytics_data.quality.total_clicks
 * reflects only the filtered subset.
 */
class InsightsFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Without any filters, the endpoint returns data and the quality block
     * reports the total count of seeded clicks.
     */
    public function test_without_filters_returns_data(): void
    {
        Click::factory()->count(5)->create(['link_id' => $this->link->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights");

        $response->assertOk();
        $response->assertJsonPath('data.insights', fn ($v) => is_array($v));
    }

    /**
     * Clicks before date_from must be excluded from the quality total.
     */
    public function test_date_from_scopes_analytics_data(): void
    {
        // 2 old clicks — should be excluded by the filter
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'created_at' => now()->subDays(30),
        ]);

        // 3 recent clicks — should be included
        Click::factory()->count(3)->create([
            'link_id' => $this->link->id,
            'created_at' => now()->subDays(1),
        ]);

        $dateFrom = now()->subDays(10)->toDateString();

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?date_from={$dateFrom}");

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.analytics_data.quality.total_clicks'));
    }

    /**
     * Bot clicks must be excluded when exclude_bots=true is passed.
     */
    public function test_exclude_bots_removes_bot_clicks(): void
    {
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'is_bot' => false,
            'quality_tier' => 'organic',
        ]);

        Click::factory()->count(3)->create([
            'link_id' => $this->link->id,
            'is_bot' => true,
            'quality_tier' => 'likely_fraud',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?exclude_bots=true");

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.analytics_data.quality.total_clicks'));
    }

    /**
     * When exclude_bots=false, both human and bot clicks must be included.
     */
    public function test_exclude_bots_false_includes_all_clicks(): void
    {
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'is_bot' => false,
        ]);

        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'is_bot' => true,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?exclude_bots=false");

        $response->assertOk();
        $this->assertEquals(4, $response->json('data.analytics_data.quality.total_clicks'));
    }
}
