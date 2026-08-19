<?php

namespace Tests\Feature\Subdomain;

use App\Models\Link;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Garante que liberar um subdomínio (DELETE /api/subdomains/{id}) migra os
 * links que apontavam para o hostname liberado de volta ao domínio padrão
 * (short_domain = null), em vez de deixá-los quebrados apontando para um
 * host que o redirect passa a bloquear com subdomain_not_found.
 *
 * Invariantes protegidas:
 *  - o contador denormalizado links.clicks NÃO muda (histórico preservado);
 *  - links de OUTROS subdomínios do mesmo usuário não são tocados;
 *  - links de outros usuários não são tocados;
 *  - o cache de slug (findActiveBySlugCached) é invalidado na migração,
 *    para o short_url refletir o domínio padrão imediatamente;
 *  - GET /api/subdomains expõe links_count para a UI avisar antes de liberar.
 *
 * @see \App\Http\Controllers\Subdomain\SubdomainController::destroy()
 */
class SubdomainReleaseMigratesLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.domain' => 'linkcharts.com.br']);
        Cache::flush();
    }

    /** Liberar o subdomínio zera o short_domain dos links dele e preserva os cliques. */
    public function test_release_reverts_links_to_default_domain_preserving_clicks(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $sub = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $link = Link::factory()->create([
            'user_id' => $user->id,
            'short_domain' => 'acme.linkcharts.com.br',
            'clicks' => 42,
        ]);

        $response = $this->actingAs($user, 'api')->deleteJson("/api/subdomains/{$sub->id}");

        $response->assertNoContent();
        $this->assertDatabaseHas('links', [
            'id' => $link->id,
            'short_domain' => null,
            'clicks' => 42,
        ]);
    }

    /** Links de outros subdomínios do usuário e de outros usuários não são tocados. */
    public function test_release_only_touches_links_of_released_hostname_and_owner(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $released = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'clientb']);

        $otherSubLink = Link::factory()->create([
            'user_id' => $user->id,
            'short_domain' => 'clientb.linkcharts.com.br',
        ]);

        $stranger = User::factory()->create();
        $strangerLink = Link::factory()->create([
            'user_id' => $stranger->id,
            'short_domain' => 'acme.linkcharts.com.br',
        ]);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/subdomains/{$released->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('links', [
            'id' => $otherSubLink->id,
            'short_domain' => 'clientb.linkcharts.com.br',
        ]);
        $this->assertDatabaseHas('links', [
            'id' => $strangerLink->id,
            'short_domain' => 'acme.linkcharts.com.br',
        ]);
    }

    /** A migração invalida o cache de slug — o modelo cacheado não fica servindo o host morto. */
    public function test_release_invalidates_slug_cache_of_migrated_links(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $sub = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);

        $link = Link::factory()->create([
            'user_id' => $user->id,
            'short_domain' => 'acme.linkcharts.com.br',
        ]);

        // Prime o cache com o estado pré-migração (host antigo).
        $cached = Link::findActiveBySlugCached($link->slug);
        $this->assertSame('acme.linkcharts.com.br', $cached->short_domain);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/subdomains/{$sub->id}")
            ->assertNoContent();

        $fresh = Link::findActiveBySlugCached($link->slug);
        $this->assertNull($fresh->short_domain);
    }

    /** GET /api/subdomains expõe quantos links usam cada subdomínio (aviso pré-liberação na UI). */
    public function test_index_exposes_links_count_per_subdomain(): void
    {
        $user = User::factory()->create(['email_verified' => true, 'email_verified_at' => now()]);
        $acme = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'acme']);
        $empty = UserSubdomain::factory()->create(['user_id' => $user->id, 'subdomain' => 'clientb']);

        Link::factory()->count(2)->create([
            'user_id' => $user->id,
            'short_domain' => 'acme.linkcharts.com.br',
        ]);
        Link::factory()->create(['user_id' => $user->id, 'short_domain' => null]);

        $response = $this->actingAs($user, 'api')->getJson('/api/subdomains');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $acme->id);
        $response->assertJsonPath('data.0.links_count', 2);
        $response->assertJsonPath('data.1.id', $empty->id);
        $response->assertJsonPath('data.1.links_count', 0);
    }
}
