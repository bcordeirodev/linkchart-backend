<?php

namespace Tests\Feature\Subdomain;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Covers `subdomain_id` selection on POST /api/links, introduced so a user
 * with several active subdomains can pick which one a new link uses.
 *
 * @see \App\Services\Links\LinkService::createLink()
 */
class SubdomainLinkCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.domain' => 'linkcharts.com.br']);
        // Cache::remember caches null results too; a flush avoids state
        // leaking in from another test in the same process.
        Cache::flush();
    }

    /** subdomain_id válido grava short_domain do subdomínio escolhido. */
    public function test_link_uses_selected_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $chosen = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'shop']);

        $response = $this->actingAs($user, 'api')->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'title' => 'Test',
            'subdomain_id' => $chosen->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'short_domain' => 'shop.linkcharts.com.br',
        ]);
    }

    /** subdomain_id de outro usuário → 422. */
    public function test_link_rejects_foreign_subdomain(): void
    {
        $owner = User::factory()->create();
        $foreign = UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);

        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);

        $response = $this->actingAs($user, 'api')->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'title' => 'Test',
            'subdomain_id' => $foreign->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('links', ['user_id' => $user->id]);
    }

    /** Sem subdomain_id, usa o ativo mais antigo (comportamento atual). */
    public function test_link_defaults_to_oldest_active_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $oldest = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'shop']);

        $response = $this->actingAs($user, 'api')->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'title' => 'Test',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'short_domain' => $oldest->subdomain.'.linkcharts.com.br',
        ]);
    }

    /** subdomain_id null explícito força domínio padrão. */
    public function test_link_null_subdomain_uses_default_domain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $response = $this->actingAs($user, 'api')->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'title' => 'Test',
            'subdomain_id' => null,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('links', [
            'user_id' => $user->id,
            'short_domain' => null,
        ]);
    }
}
