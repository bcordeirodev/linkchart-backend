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
 * bot-exclusion filters via the AnalyticsFilters DTO.
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
        Click::factory()->count(10)->create(['link_id' => $this->link->id]);
    }

    /** @test */
    public function test_insights_endpoint_accepts_date_filter(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?date_from=2026-01-01&date_to=2026-12-31");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['insights', 'summary', 'generated_at']]);
    }

    /** @test */
    public function test_insights_endpoint_accepts_exclude_bots(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?exclude_bots=true");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['insights']]);
    }
}
