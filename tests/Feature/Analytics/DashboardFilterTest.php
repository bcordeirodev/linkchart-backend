<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for DashboardAnalyticsService filter support.
 *
 * Verifies that the dashboard endpoint correctly applies date-range and
 * bot-exclusion filters when computing summary.total_clicks.
 */
class DashboardFilterTest extends TestCase
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
     * Clicks before date_from must be excluded from the summary count.
     */
    public function test_date_from_excludes_older_clicks(): void
    {
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-01-01 12:00:00']);
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-02-01 12:00:00']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?date_from=2026-02-01");

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.summary.total_clicks'));
    }

    /**
     * Bot clicks must be excluded when exclude_bots=true is passed.
     */
    public function test_exclude_bots_removes_bot_clicks(): void
    {
        Click::factory()->create(['link_id' => $this->link->id, 'is_bot' => false]);
        Click::factory()->create(['link_id' => $this->link->id, 'is_bot' => true]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?exclude_bots=true");

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.summary.total_clicks'));
    }

    /**
     * Without any filters, all clicks must be included in the summary count.
     */
    public function test_without_filters_returns_all_clicks(): void
    {
        Click::factory()->count(3)->create(['link_id' => $this->link->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard");

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.summary.total_clicks'));
    }
}
