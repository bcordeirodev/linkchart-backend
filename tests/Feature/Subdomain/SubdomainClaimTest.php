<?php

namespace Tests\Feature\Subdomain;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the plural /api/subdomains endpoints for claim (store) and
 * availability check (check). List (index) and release-by-id (destroy) have
 * their own dedicated coverage in {@see SubdomainMultiTest}.
 *
 * @see \App\Http\Controllers\Subdomain\SubdomainController
 */
class SubdomainClaimTest extends TestCase
{
    use RefreshDatabase;

    // ── auth ─────────────────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/subdomains')->assertUnauthorized();
    }

    // ── GET /api/subdomains/check ───────────────────────────────────────

    public function test_check_returns_available_when_subdomain_is_free(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomains/check?name=acme');
        $response->assertOk()->assertJsonPath('data.available', true);
    }

    public function test_check_returns_unavailable_when_subdomain_is_taken(): void
    {
        $owner = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomains/check?name=acme');
        $response->assertOk()->assertJsonPath('data.available', false);
    }

    public function test_check_returns_available_when_subdomain_was_released(): void
    {
        $owner = User::factory()->create();
        UserSubdomain::factory()->inactive()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomains/check?name=acme');
        $response->assertOk()->assertJsonPath('data.available', true);
    }

    // ── POST /api/subdomains ────────────────────────────────────────────

    public function test_user_can_claim_a_subdomain(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomains', ['subdomain' => 'acme']);
        $response->assertCreated()
            ->assertJsonPath('data.subdomain', 'acme')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.full_url', 'http://acme.linkcharts.com.br');
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $user->id, 'subdomain' => 'acme', 'status' => 'active',
        ]);
    }

    // NOTE: as of the multi-subdomain support (Task 3), a user having one active
    // subdomain no longer blocks claiming another — it's allowed up to
    // config('app.max_subdomains_per_user'). The former 409 SUBDOMAIN_ALREADY_ACTIVE
    // behavior is superseded by SubdomainMultiTest::test_claim_respects_per_user_limit.

    public function test_claim_allows_second_active_subdomain_up_to_limit(): void
    {
        config(['app.domain' => 'linkcharts.com.br', 'app.max_subdomains_per_user' => 3]);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomains', ['subdomain' => 'clientb']);
        $response->assertCreated()->assertJsonPath('data.subdomain', 'clientb');
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $user->id, 'subdomain' => 'acme', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $user->id, 'subdomain' => 'clientb', 'status' => 'active',
        ]);
    }

    public function test_claim_returns_422_when_subdomain_is_already_taken(): void
    {
        $owner = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomains', ['subdomain' => 'acme']);
        $response->assertUnprocessable();
    }

    public function test_claim_returns_422_for_reserved_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomains', ['subdomain' => 'api']);
        $response->assertUnprocessable();
    }

    public function test_claim_returns_422_for_invalid_format(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        foreach (['-acme', 'ACME', 'ab', 'acme-'] as $invalid) {
            $this->actingAs($user, 'api')
                ->postJson('/api/subdomains', ['subdomain' => $invalid])
                ->assertUnprocessable("Failed for: {$invalid}");
        }
    }

    public function test_user_can_reclaim_after_releasing(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $old = UserSubdomain::factory()->inactive()->create(['user_id' => $user->id, 'subdomain' => 'olddomain']);
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/subdomains', ['subdomain' => 'newdomain']);
        $response->assertCreated()->assertJsonPath('data.subdomain', 'newdomain');
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $user->id, 'subdomain' => 'newdomain', 'status' => 'active',
        ]);
        // Claiming always INSERTs a new row (never reuses/updates an inactive
        // one) since a user may hold several rows now. The old released row
        // must still exist untouched, proving it wasn't the one updated.
        $this->assertDatabaseHas('user_subdomains', [
            'id' => $old->id, 'subdomain' => 'olddomain', 'status' => 'inactive',
        ]);
        $this->assertDatabaseCount('user_subdomains', 2);
    }

    public function test_released_subdomain_can_be_claimed_by_another_user(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $ownerA = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->inactive()->create(['user_id' => $ownerA->id, 'subdomain' => 'acme']);
        $ownerB = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($ownerB, 'api')
            ->postJson('/api/subdomains', ['subdomain' => 'acme']);
        $response->assertCreated()->assertJsonPath('data.subdomain', 'acme');
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $ownerB->id, 'subdomain' => 'acme', 'status' => 'active',
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
