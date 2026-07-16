<?php

namespace Tests\Feature\Reports;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for the /api/reports/* endpoints (ReportsController).
 *
 * Mirrors the auth/verified requirements and response envelope of the
 * per-link analytics endpoints (see AnalyticsController), but scoped to the
 * multi-link aggregations served by ReportsAnalyticsServiceInterface.
 */
class ReportsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function makeVerifiedUser(): User
    {
        return User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** Endpoints exigem auth + email verificado (401/403 sem token). */
    public function test_reports_require_authentication(): void
    {
        $this->getJson('/api/reports/summary')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /** Summary retorna as 6 chaves e só conta os links do usuário autenticado. */
    public function test_summary_returns_scoped_kpis(): void
    {
        $user = $this->makeVerifiedUser();
        $other = $this->makeVerifiedUser();

        $link = Link::factory()->create(['user_id' => $user->id]);
        $otherLink = Link::factory()->create(['user_id' => $other->id]);

        Click::factory()->count(4)->create(['link_id' => $link->id, 'is_bot' => false]);
        Click::factory()->count(9)->create(['link_id' => $otherLink->id, 'is_bot' => false]);

        $response = $this->actingAs($user, 'api')->getJson('/api/reports/summary');

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'total_clicks',
                'unique_visitors',
                'total_links',
                'active_links',
                'avg_clicks_per_day',
                'variation_pct',
            ],
        ]);
        $this->assertSame(4, $response->json('data.total_clicks'));
        $this->assertSame(1, $response->json('data.total_links'));
    }

    /** Breakdown com dimensão inválida retorna 422. */
    public function test_breakdown_validates_dimension(): void
    {
        $user = $this->makeVerifiedUser();

        $this->actingAs($user, 'api')
            ->getJson('/api/reports/breakdown?dimension=not_a_real_dimension')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    /** date_from/date_to filtram a timeseries. */
    public function test_timeseries_accepts_date_filters(): void
    {
        $user = $this->makeVerifiedUser();
        $link = Link::factory()->create(['user_id' => $user->id]);

        Click::factory()->create([
            'link_id' => $link->id,
            'is_bot' => false,
            'created_at' => now()->subDays(20),
        ]);
        Click::factory()->create([
            'link_id' => $link->id,
            'is_bot' => false,
            'created_at' => now()->subDay(),
        ]);

        $query = http_build_query([
            'date_from' => now()->subDays(3)->toIso8601String(),
            'date_to' => now()->toIso8601String(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson("/api/reports/timeseries?{$query}");

        $response->assertOk();
        $totalClicks = array_sum(array_column($response->json('data.series'), 'clicks'));
        $this->assertSame(1, $totalClicks);
    }
}
