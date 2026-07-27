<?php

namespace Tests\Feature\Analytics;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GET /api/links/{id}/analytics (legacy summary endpoint).
 *
 * Response is raw JSON (not wrapped by NormalizeApiResponse) — see
 * AnalyticsController::getLinkSummaryAnalytics.
 */
class LinkSummaryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->token = auth()->guard('api')->login($this->user);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * avg_daily_clicks divides total clicks by the days since the link was
     * created — a 10-day-old link with 20 clicks averages 2.0/day.
     *
     * Regression: Carbon 3 made now()->diffInDays($past) signed (negative),
     * so max(1, negative) pinned the divisor at 1 and avg_daily_clicks
     * always equalled total_clicks.
     */
    public function test_avg_daily_clicks_divides_by_days_since_creation(): void
    {
        $link = Link::factory()->create([
            'user_id' => $this->user->id,
            'clicks' => 20,
            'created_at' => now()->subDays(10),
        ]);

        $response = $this->getJson("/api/links/{$link->id}/analytics", $this->auth())
            ->assertOk();

        $this->assertSame(2.0, (float) $response->json('data.avg_daily_clicks'));
    }
}
