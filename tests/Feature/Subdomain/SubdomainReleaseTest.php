<?php

namespace Tests\Feature\Subdomain;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubdomainReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_release_their_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $response = $this->actingAs($user, 'api')->deleteJson('/api/subdomain');
        $response->assertNoContent();
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $user->id, 'subdomain' => 'acme', 'status' => 'inactive',
        ]);
    }

    public function test_release_returns_404_when_user_has_no_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $this->actingAs($user, 'api')->deleteJson('/api/subdomain')->assertNotFound();
    }

    public function test_released_subdomain_can_be_claimed_by_another_user(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $ownerA = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->inactive()->create(['user_id' => $ownerA->id, 'subdomain' => 'acme']);
        $ownerB = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($ownerB, 'api')
            ->postJson('/api/subdomain', ['subdomain' => 'acme']);
        $response->assertCreated()->assertJsonPath('data.subdomain', 'acme');
        $this->assertDatabaseHas('user_subdomains', [
            'user_id' => $ownerB->id, 'subdomain' => 'acme', 'status' => 'active',
        ]);
    }
}
