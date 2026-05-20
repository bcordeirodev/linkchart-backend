<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for TemporalAnalyticsService filter support.
 *
 * Verifies that date_from and segment query parameters correctly scope
 * the clicks_by_hour aggregation in the temporal analytics endpoint.
 */
class TemporalFilterTest extends TestCase
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
     * Verify that date_from filters out clicks before the specified date.
     */
    public function test_date_from_scopes_temporal_data(): void
    {
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-01-01 10:00:00']);
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-03-01 14:00:00']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/temporal?date_from=2026-03-01");

        $response->assertOk();
        $total = collect($response->json('data.clicks_by_hour'))->sum('clicks');
        $this->assertEquals(1, $total);
    }

    /**
     * Verify that segment=weekday excludes clicks where is_weekend=true.
     */
    public function test_segment_weekday_excludes_weekend_clicks(): void
    {
        // Weekday click (Monday 2026-01-05) — is_weekend=false
        Click::factory()->create([
            'link_id'    => $this->link->id,
            'created_at' => '2026-01-05 10:00:00',
            'is_weekend' => false,
        ]);
        // Weekend click (Saturday 2026-01-10) — is_weekend=true
        Click::factory()->create([
            'link_id'    => $this->link->id,
            'created_at' => '2026-01-10 10:00:00',
            'is_weekend' => true,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/temporal?segment=weekday");

        $response->assertOk();
        $total = collect($response->json('data.clicks_by_hour'))->sum('clicks');
        $this->assertEquals(1, $total);
    }

    /**
     * Verify that no filters returns all clicks unmodified.
     */
    public function test_no_filters_returns_all_clicks(): void
    {
        Click::factory()->count(5)->create(['link_id' => $this->link->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/temporal");

        $response->assertOk();
        $total = collect($response->json('data.clicks_by_hour'))->sum('clicks');
        $this->assertEquals(5, $total);
    }
}
