<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LinkMetaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authAs(User $user): static
    {
        return $this->actingAs($user, 'api');
    }

    public function test_batch_meta_returns_data_for_owned_links(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $response = $this->authAs($user)->postJson(
            '/api/links/batch-meta',
            ['ids' => [(int) $link->id]]
        );

        $response->assertOk()
            ->assertJsonPath("data.{$link->id}.health.status", 'unknown')
            ->assertJsonStructure([
                'data' => [
                    (string) $link->id => [
                        'sparkline',
                        'trend' => ['current', 'previous', 'percent_change', 'last_click_at'],
                        'health' => ['status', 'last_checked_at', 'http_code'],
                    ],
                ],
            ]);
    }

    public function test_batch_meta_ignores_other_users_links(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $other = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $other->id]);

        $response = $this->authAs($user)->postJson(
            '/api/links/batch-meta',
            ['ids' => [(int) $link->id]]
        );

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_batch_meta_requires_auth(): void
    {
        $this->postJson('/api/links/batch-meta', ['ids' => [1]])->assertUnauthorized();
    }

    public function test_sparkline_returns_n_daily_points(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $user->id]);

        $response = $this->authAs($user)->getJson("/api/links/{$link->id}/sparkline?days=7");

        $response->assertOk();
        $this->assertCount(7, $response->json('data'));
    }

    public function test_trend_returns_correct_structure(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'email_verified' => true]);
        $link = Link::factory()->create(['user_id' => $user->id]);

        $response = $this->authAs($user)->getJson("/api/links/{$link->id}/trend");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['current', 'previous', 'percent_change', 'last_click_at']]);
    }
}
