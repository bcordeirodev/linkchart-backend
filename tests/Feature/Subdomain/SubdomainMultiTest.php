<?php

namespace Tests\Feature\Subdomain;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers the plural /api/subdomains endpoints (list, claim with per-user
 * limit, release by id) introduced to support N subdomains per user.
 *
 * @see \App\Http\Controllers\Subdomain\SubdomainController
 */
class SubdomainMultiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cache::remember caches null/empty results too; a flush avoids state
        // leaking in from another test in the same process.
        Cache::flush();
    }

    /** Usuário reivindica 2º e 3º subdomínio; o 4º recebe 422 SUBDOMAIN_LIMIT_REACHED. */
    public function test_claim_respects_per_user_limit(): void
    {
        config(['app.domain' => 'linkcharts.com.br', 'app.max_subdomains_per_user' => 3]);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $second = $this->actingAs($user, 'api')->postJson('/api/subdomains', ['subdomain' => 'clientb']);
        $second->assertCreated()->assertJsonPath('data.subdomain', 'clientb');

        $third = $this->actingAs($user, 'api')->postJson('/api/subdomains', ['subdomain' => 'gamma']);
        $third->assertCreated()->assertJsonPath('data.subdomain', 'gamma');

        $fourth = $this->actingAs($user, 'api')->postJson('/api/subdomains', ['subdomain' => 'delta']);
        $fourth->assertStatus(422)->assertJsonPath('error.code', 'SUBDOMAIN_LIMIT_REACHED');

        $this->assertDatabaseCount('user_subdomains', 3);
    }

    /** GET /api/subdomains lista só os ativos do usuário, ordenados. */
    public function test_index_lists_active_subdomains(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $first = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $second = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'clientb']);
        UserSubdomain::factory()->inactive()->create(['user_id' => $user->id, 'subdomain' => 'gone']);

        $response = $this->actingAs($user, 'api')->getJson('/api/subdomains');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.0.subdomain', 'acme');
        $response->assertJsonPath('data.1.id', $second->id);
        $response->assertJsonPath('data.1.subdomain', 'clientb');
        $this->assertCount(2, $response->json('data'));
    }

    /** GET /api/subdomains só lista os do usuário autenticado, nunca de outro. */
    public function test_index_never_leaks_other_users_subdomains(): void
    {
        $owner = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'foreign']);

        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->getJson('/api/subdomains');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    /** DELETE /api/subdomains/{id} libera só o alvo; os outros continuam ativos. */
    public function test_release_by_id_only_affects_target(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $keep = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $release = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'clientb']);

        $response = $this->actingAs($user, 'api')->deleteJson("/api/subdomains/{$release->id}");

        $response->assertNoContent();
        $this->assertDatabaseHas('user_subdomains', ['id' => $release->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('user_subdomains', ['id' => $keep->id, 'status' => 'active']);
    }

    /** DELETE em id de outro usuário → 404. */
    public function test_release_by_id_denies_foreign_subdomain(): void
    {
        $owner = User::factory()->create();
        $foreign = UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);

        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $response = $this->actingAs($user, 'api')->deleteJson("/api/subdomains/{$foreign->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('user_subdomains', ['id' => $foreign->id, 'status' => 'active']);
    }

    /** DELETE em id inexistente ou já inativo → 404. */
    public function test_release_by_id_returns_404_when_already_inactive(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $sub = UserSubdomain::factory()->inactive()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $response = $this->actingAs($user, 'api')->deleteJson("/api/subdomains/{$sub->id}");

        $response->assertNotFound();
    }

    /** Claim sempre INSERE (não faz mais UPDATE de linha inactive). */
    public function test_claim_inserts_new_row_even_with_inactive_history(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $old = UserSubdomain::factory()->inactive()->create(['user_id' => $user->id, 'subdomain' => 'olddomain']);

        $response = $this->actingAs($user, 'api')->postJson('/api/subdomains', ['subdomain' => 'newdomain']);

        $response->assertCreated()->assertJsonPath('data.subdomain', 'newdomain');
        $this->assertDatabaseHas('user_subdomains', ['id' => $old->id, 'subdomain' => 'olddomain', 'status' => 'inactive']);
        $this->assertDatabaseHas('user_subdomains', ['user_id' => $user->id, 'subdomain' => 'newdomain', 'status' => 'active']);
        $this->assertDatabaseCount('user_subdomains', 2);
    }
}
