<?php

namespace Tests\Feature\Subdomain;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubdomainClaimTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/subdomain ──────────────────────────────────────────────

    public function test_show_returns_null_when_user_has_no_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomain');
        $response->assertOk()->assertJsonPath('data', null);
    }

    public function test_show_returns_subdomain_when_user_has_one(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomain');
        $response->assertOk()
            ->assertJsonPath('data.subdomain', 'acme')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/subdomain')->assertUnauthorized();
    }

    // ── GET /api/subdomain/check ────────────────────────────────────────

    public function test_check_returns_available_when_subdomain_is_free(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomain/check?name=acme');
        $response->assertOk()->assertJsonPath('data.available', true);
    }

    public function test_check_returns_unavailable_when_subdomain_is_taken(): void
    {
        $owner = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomain/check?name=acme');
        $response->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_check_returns_available_when_subdomain_was_released(): void
    {
        $owner = User::factory()->create();
        UserSubdomain::factory()->inactive()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomain/check?name=acme');
        $response->assertOk()->assertJsonPath('data.available', true);
    }

    // ── POST /api/subdomain ─────────────────────────────────────────────

    public function test_user_can_claim_a_subdomain(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomain', ['subdomain' => 'acme']);
        $response->assertCreated()
            ->assertJsonPath('data.subdomain', 'acme')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.full_url', 'http://acme.linkcharts.com.br');
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $user->id, 'subdomain' => 'acme', 'status' => 'active',
        ]);
    }

    public function test_claim_returns_409_when_user_already_has_active_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomain', ['subdomain' => 'beta']);
        $response->assertStatus(409);
    }

    public function test_claim_returns_422_when_subdomain_is_already_taken(): void
    {
        $owner = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomain', ['subdomain' => 'acme']);
        $response->assertUnprocessable();
    }

    public function test_claim_returns_422_for_reserved_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomain', ['subdomain' => 'api']);
        $response->assertUnprocessable();
    }

    public function test_claim_returns_422_for_invalid_format(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        foreach (['-acme', 'ACME', 'ab', 'acme-'] as $invalid) {
            $this->actingAs($user, 'api')
                ->postJson('/api/subdomain', ['subdomain' => $invalid])
                ->assertUnprocessable("Failed for: {$invalid}");
        }
    }

    public function test_user_can_reclaim_after_releasing(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->inactive()->create(['user_id' => $user->id, 'subdomain' => 'olddomain']);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomain', ['subdomain' => 'newdomain']);
        $response->assertCreated()->assertJsonPath('data.subdomain', 'newdomain');
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $user->id, 'subdomain' => 'newdomain', 'status' => 'active',
        ]);
    }

    public function test_link_created_with_subdomain_gets_short_domain_set(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/links', [
                'original_url' => 'https://example.com',
                'title' => 'Test',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'short_domain' => 'acme.linkcharts.com.br',
        ]);
    }

    public function test_link_created_without_subdomain_has_null_short_domain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/links', [
                'original_url' => 'https://example.com',
                'title' => 'Test',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'short_domain' => null,
        ]);
    }
}
