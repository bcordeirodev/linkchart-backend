<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the two dedicated rate limiters introduced by the
 * "link-in-bio" module (registered in AppServiceProvider::boot):
 *
 *   - public-bio: 60/min per IP on GET /api/public/bio/{handle}.
 *   - bio-handle-check: 30/min per user on GET /api/bio/handle-available.
 *
 * Mirrors the threshold-testing pattern used by
 * {@see \Tests\Feature\PublicShortenTest::test_rate_limit_returns_429_after_threshold}.
 */
class BioPageRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_bio_returns_429_after_60_requests_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/public/bio/doesnotexist')->assertStatus(404);
        }

        $this->getJson('/api/public/bio/doesnotexist')->assertStatus(429);
    }

    public function test_handle_available_returns_429_after_30_requests_per_minute(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $token = auth()->guard('api')->login($user);
        $auth = ['Authorization' => "Bearer {$token}"];

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/bio/handle-available?handle=freehandle', $auth)->assertOk();
        }

        $this->getJson('/api/bio/handle-available?handle=freehandle', $auth)->assertStatus(429);
    }
}
