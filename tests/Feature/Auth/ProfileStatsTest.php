<?php

namespace Tests\Feature\Auth;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for GET /api/profile/stats.
 */
class ProfileStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent UserObserver from dispatching SeedDemoLinkJob,
        // which would create a demo link for every factory user.
        Queue::fake();
    }

    private function makeVerifiedUser(): User
    {
        return User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** @test */
    public function test_returns_zero_stats_for_user_with_no_links(): void
    {
        $user = $this->makeVerifiedUser();

        $this->actingAs($user, 'api')
            ->getJson('/api/profile/stats')
            ->assertOk()
            ->assertJsonPath('data.total_links', 0)
            ->assertJsonPath('data.total_clicks', 0)
            ->assertJsonPath('data.links_this_month', 0)
            ->assertJsonPath('data.clicks_this_month', 0);
    }

    /** @test */
    public function test_returns_correct_totals_for_user_with_links(): void
    {
        $user = $this->makeVerifiedUser();
        Link::factory()->create(['user_id' => $user->id, 'clicks' => 10]);
        Link::factory()->create(['user_id' => $user->id, 'clicks' => 25]);

        $this->actingAs($user, 'api')
            ->getJson('/api/profile/stats')
            ->assertOk()
            ->assertJsonPath('data.total_links', 2)
            ->assertJsonPath('data.total_clicks', 35);
    }

    /** @test */
    public function test_only_counts_links_belonging_to_authenticated_user(): void
    {
        $user = $this->makeVerifiedUser();
        $other = $this->makeVerifiedUser();
        Link::factory()->create(['user_id' => $user->id,  'clicks' => 5]);
        Link::factory()->create(['user_id' => $other->id, 'clicks' => 100]);

        $this->actingAs($user, 'api')
            ->getJson('/api/profile/stats')
            ->assertOk()
            ->assertJsonPath('data.total_links', 1)
            ->assertJsonPath('data.total_clicks', 5);
    }

    /** @test */
    public function test_excludes_demo_links_from_stats(): void
    {
        $user = $this->makeVerifiedUser();
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => true,  'clicks' => 99]);
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => false, 'clicks' => 7]);

        $this->actingAs($user, 'api')
            ->getJson('/api/profile/stats')
            ->assertOk()
            ->assertJsonPath('data.total_links', 1)
            ->assertJsonPath('data.total_clicks', 7);
    }

    /** @test */
    public function test_links_this_month_counts_only_current_month(): void
    {
        $user = $this->makeVerifiedUser();
        Link::factory()->create(['user_id' => $user->id, 'created_at' => now()]);
        Link::factory()->create(['user_id' => $user->id, 'created_at' => now()->subMonths(2)]);

        $this->actingAs($user, 'api')
            ->getJson('/api/profile/stats')
            ->assertOk()
            ->assertJsonPath('data.links_this_month', 1);
    }

    /** @test */
    public function test_clicks_this_month_counts_only_current_month(): void
    {
        $user = $this->makeVerifiedUser();
        $link = Link::factory()->create(['user_id' => $user->id]);
        Click::factory()->create(['link_id' => $link->id, 'created_at' => now()]);
        Click::factory()->create(['link_id' => $link->id, 'created_at' => now()->subMonths(2)]);

        $this->actingAs($user, 'api')
            ->getJson('/api/profile/stats')
            ->assertOk()
            ->assertJsonPath('data.clicks_this_month', 1);
    }

    /** @test */
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/profile/stats')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
