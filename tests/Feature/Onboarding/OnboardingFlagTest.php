<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for POST /api/onboarding/seen and the User onboarding helpers.
 *
 * The flag lives on the user record (not localStorage) so a dismissed tour stays
 * dismissed across browsers and devices — that is the behaviour these lock in.
 */
class OnboardingFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // Prevent UserObserver from firing SeedDemoLinkJob when the factory creates users.
        Queue::fake();
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

    public function test_seen_persists_the_flag_on_the_user(): void
    {
        $this->assertFalse($this->user->hasSeenOnboarding('links.tour'));

        $response = $this->postJson('/api/onboarding/seen', [
            'key' => 'links.tour',
        ], $this->auth());

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['onboarding' => ['links.tour']]]);

        $this->assertTrue($this->user->fresh()->hasSeenOnboarding('links.tour'));
    }

    public function test_me_exposes_the_flag_so_a_fresh_browser_skips_the_tour(): void
    {
        $this->user->markOnboardingSeen('links.tour');

        // A brand-new browser has no localStorage; it must learn from /api/me alone.
        $response = $this->getJson('/api/me', $this->auth());

        $response->assertStatus(200);
        // NormalizeApiResponse wraps the controller payload, hence `data.user`.
        $this->assertArrayHasKey('links.tour', $response->json('data.user.onboarding'));
    }

    public function test_seen_is_idempotent_and_keeps_the_first_timestamp(): void
    {
        $this->postJson('/api/onboarding/seen', ['key' => 'links.tour'], $this->auth());
        $first = $this->user->fresh()->onboarding['links.tour'];

        $this->travel(2)->minutes();

        $this->postJson('/api/onboarding/seen', ['key' => 'links.tour'], $this->auth())
            ->assertStatus(200);

        $this->assertSame($first, $this->user->fresh()->onboarding['links.tour']);
    }

    public function test_unknown_key_is_rejected(): void
    {
        $this->postJson('/api/onboarding/seen', [
            'key' => 'links.not-a-real-flag',
        ], $this->auth())->assertStatus(422);

        $this->assertNull($this->user->fresh()->onboarding);
    }

    public function test_requires_authentication(): void
    {
        // setUp()'s guard login leaves the JWT guard resolved in-process, so an
        // unauthenticated request would still see a user. Drop it first.
        auth()->guard('api')->logout();

        $this->postJson('/api/onboarding/seen', ['key' => 'links.tour'])
            ->assertStatus(401);
    }
}
