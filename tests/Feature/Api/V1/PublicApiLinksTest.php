<?php

namespace Tests\Feature\Api\V1;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cobre a API pública v1 (/api/v1/*, auth via Sanctum Bearer token).
 *
 * Contrato fixo (frontend construído em paralelo):
 *   POST /api/v1/links            → 201 LinkResource (via LinkService — Safe Browsing incluso)
 *   GET  /api/v1/links            → lista paginada (?page, ?per_page máx 50)
 *   GET  /api/v1/links/{id}       → 200/404 por ownership
 *   GET  /api/v1/links/{id}/stats → {data: {total_clicks, unique_visitors,
 *                                    top_countries, devices, clicks_last_30d}}
 *   Rate limit `public-api` 60/min por token.
 *
 * O JWT do painel NÃO deve autenticar aqui (isolamento de guards).
 */
class PublicApiLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->token = $this->user->createToken('test key')->plainTextToken;
    }

    /**
     * Authorization header carrying the Sanctum API token.
     *
     * @return array{Authorization: string}
     */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * Clears cached auth state (web/api/sanctum guards) between requests whose
     * Bearer token differs — see Tests\TestCase::flushAuthState() for why.
     */
    private function flushGuards(): void
    {
        $this->flushAuthState();
        $guard = $this->app['auth']->guard('sanctum');
        (fn () => $this->user = null)->call($guard);
    }

    // =========================================================
    // AUTENTICAÇÃO / ISOLAMENTO DE GUARDS
    // =========================================================

    public function test_v1_requires_token(): void
    {
        $this->getJson('/api/v1/links')->assertStatus(401);
    }

    /**
     * Consumidores de API (curl, SDKs) frequentemente NÃO enviam o header
     * Accept: application/json. Sem forçar render JSON em api/*, a
     * AuthenticationException tentaria redirecionar para route('login')
     * (inexistente) e o 401 viraria 500.
     */
    public function test_v1_returns_json_401_without_accept_header(): void
    {
        $response = $this->get('/api/v1/links');

        $response->assertStatus(401);
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_v1_rejects_revoked_token(): void
    {
        $this->user->tokens()->delete();

        $this->getJson('/api/v1/links', $this->auth())->assertStatus(401);
    }

    public function test_v1_rejects_panel_jwt(): void
    {
        $jwt = auth()->guard('api')->login($this->user);
        $this->flushGuards();

        $this->getJson('/api/v1/links', ['Authorization' => "Bearer {$jwt}"])
            ->assertStatus(401);
    }

    public function test_token_last_used_at_is_updated_on_use(): void
    {
        $accessToken = $this->user->tokens()->first();
        $this->assertNull($accessToken->last_used_at);

        $this->getJson('/api/v1/links', $this->auth())->assertOk();

        $this->assertNotNull($accessToken->fresh()->last_used_at);
    }

    // =========================================================
    // POST /api/v1/links
    // =========================================================

    public function test_create_link_returns_201_with_link_resource(): void
    {
        $response = $this->postJson('/api/v1/links', [
            'original_url' => 'https://example.com/landing',
            'title' => 'Docs link',
            'slug' => 'v1-docs-link',
            'utm_source' => 'api',
        ], $this->auth());

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => [
                'id', 'slug', 'original_url', 'title', 'short_url', 'has_password',
                'is_active', 'clicks', 'created_at', 'utm_source',
            ]]);

        $this->assertSame('v1-docs-link', $response->json('data.slug'));
        $this->assertDatabaseHas('links', [
            'slug' => 'v1-docs-link',
            'original_url' => 'https://example.com/landing',
            'user_id' => $this->user->id,
            'utm_source' => 'api',
        ]);
    }

    public function test_create_link_requires_original_url(): void
    {
        $this->postJson('/api/v1/links', ['title' => 'no url'], $this->auth())
            ->assertStatus(422);
    }

    public function test_create_link_goes_through_safe_browsing(): void
    {
        config(['services.google_safe_browsing.key' => 'chave-de-teste']);
        Http::fake(['safebrowsing.googleapis.com/*' => Http::response([
            'matches' => [['threatType' => 'SOCIAL_ENGINEERING']],
        ], 200)]);

        $this->postJson('/api/v1/links', [
            'original_url' => 'https://malicious.example.test/phish',
        ], $this->auth())->assertStatus(422);

        $this->assertDatabaseMissing('links', [
            'original_url' => 'https://malicious.example.test/phish',
        ]);
    }

    public function test_create_link_blocked_by_local_heuristic(): void
    {
        // Marca + keyword de login: bloqueada pela camada 1 (heurística local),
        // sem nenhuma chamada externa — o kill switch do GSB não a desativa.
        $this->postJson('/api/v1/links', [
            'original_url' => 'https://nubank-verificar.example.test/login',
        ], $this->auth())->assertStatus(422);
    }

    public function test_create_link_rejects_duplicate_slug(): void
    {
        Link::factory()->create(['slug' => 'taken-slug']);

        $this->postJson('/api/v1/links', [
            'original_url' => 'https://example.com',
            'slug' => 'taken-slug',
        ], $this->auth())->assertStatus(422);
    }

    // =========================================================
    // POST /api/v1/links — subdomain_id (mesma semântica do painel;
    // ver Tests\Feature\Subdomain\SubdomainLinkCreationTest)
    // =========================================================

    /**
     * Prepara o cenário de subdomínio: domínio raiz fixo e cache limpo
     * (Cache::remember guarda até resultado null do findByUserCached).
     */
    private function setUpSubdomains(): void
    {
        config(['app.domain' => 'linkcharts.com.br']);
        Cache::flush();
    }

    /** subdomain_id válido grava short_domain do subdomínio escolhido. */
    public function test_create_link_uses_selected_subdomain(): void
    {
        $this->setUpSubdomains();
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'acme']);
        $chosen = UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'shop']);

        $this->postJson('/api/v1/links', [
            'original_url' => 'https://example.com',
            'subdomain_id' => $chosen->id,
        ], $this->auth())->assertCreated();

        $this->assertDatabaseHas('links', [
            'user_id' => $this->user->id,
            'short_domain' => 'shop.linkcharts.com.br',
        ]);
    }

    /** subdomain_id de outro usuário → 422 e nada é criado. */
    public function test_create_link_rejects_foreign_subdomain(): void
    {
        $this->setUpSubdomains();
        $owner = User::factory()->create();
        $foreign = UserSubdomain::factory()->create(['user_id' => $owner->id, 'subdomain' => 'acme']);

        $this->postJson('/api/v1/links', [
            'original_url' => 'https://example.com',
            'subdomain_id' => $foreign->id,
        ], $this->auth())->assertStatus(422);

        $this->assertDatabaseMissing('links', ['user_id' => $this->user->id]);
    }

    /** subdomain_id null explícito força o domínio raiz mesmo havendo default. */
    public function test_create_link_null_subdomain_forces_root_domain(): void
    {
        $this->setUpSubdomains();
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'acme']);

        $this->postJson('/api/v1/links', [
            'original_url' => 'https://example.com',
            'subdomain_id' => null,
        ], $this->auth())->assertCreated();

        $this->assertDatabaseHas('links', [
            'user_id' => $this->user->id,
            'short_domain' => null,
        ]);
    }

    /** Campo ausente preserva o fallback: subdomínio ativo mais antigo. */
    public function test_create_link_defaults_to_oldest_active_subdomain(): void
    {
        $this->setUpSubdomains();
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'acme']);
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'shop']);

        $this->postJson('/api/v1/links', [
            'original_url' => 'https://example.com',
        ], $this->auth())->assertCreated();

        $this->assertDatabaseHas('links', [
            'user_id' => $this->user->id,
            'short_domain' => 'acme.linkcharts.com.br',
        ]);
    }

    // =========================================================
    // GET /api/v1/links (+ /{id})
    // =========================================================

    public function test_index_returns_only_token_owner_links_paginated(): void
    {
        $other = User::factory()->create();
        Link::factory()->count(3)->create(['user_id' => $other->id]);
        Link::factory()->count(2)->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/v1/links?page=1&per_page=1', $this->auth());

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'slug', 'original_url', 'short_url']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.per_page'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_caps_per_page_at_50(): void
    {
        $this->getJson('/api/v1/links?per_page=51', $this->auth())
            ->assertStatus(422);
    }

    public function test_show_returns_owned_link(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        $this->getJson("/api/v1/links/{$link->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.id', $link->id);
    }

    public function test_show_denies_other_users_link(): void
    {
        $other = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $other->id]);

        $this->getJson("/api/v1/links/{$link->id}", $this->auth())
            ->assertStatus(404);
    }

    // =========================================================
    // GET /api/v1/links/{id}/stats
    // =========================================================

    public function test_stats_returns_contract_shape(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        Click::factory()->create([
            'link_id' => $link->id,
            'ip' => '10.0.0.1',
            'country' => 'Brazil',
            'device' => 'mobile',
            'created_at' => now(),
        ]);
        Click::factory()->create([
            'link_id' => $link->id,
            'ip' => '10.0.0.1',
            'country' => 'Brazil',
            'device' => 'mobile',
            'created_at' => now(),
        ]);
        Click::factory()->create([
            'link_id' => $link->id,
            'ip' => '10.0.0.2',
            'country' => 'United States',
            'device' => 'desktop',
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->getJson("/api/v1/links/{$link->id}/stats", $this->auth());

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'total_clicks',
                'unique_visitors',
                'top_countries' => [['country', 'clicks']],
                'devices' => [['device', 'clicks']],
                'clicks_last_30d' => [['date', 'clicks']],
            ]]);

        $this->assertSame(3, $response->json('data.total_clicks'));
        $this->assertSame(2, $response->json('data.unique_visitors'));

        $countries = collect($response->json('data.top_countries'))->keyBy('country');
        $this->assertSame(2, $countries['Brazil']['clicks']);
        $this->assertSame(1, $countries['United States']['clicks']);

        $devices = collect($response->json('data.devices'))->keyBy('device');
        $this->assertSame(2, $devices['mobile']['clicks']);
        $this->assertSame(1, $devices['desktop']['clicks']);

        $series = collect($response->json('data.clicks_last_30d'))->keyBy('date');
        $this->assertSame(2, $series[now()->toDateString()]['clicks']);
        $this->assertSame(1, $series[now()->subDays(2)->toDateString()]['clicks']);
    }

    public function test_stats_top_countries_limited_to_five(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $country) {
            Click::factory()->create([
                'link_id' => $link->id,
                'country' => $country,
                'created_at' => now(),
            ]);
        }

        $response = $this->getJson("/api/v1/links/{$link->id}/stats", $this->auth());

        $response->assertOk();
        $this->assertCount(5, $response->json('data.top_countries'));
    }

    public function test_stats_denies_other_users_link(): void
    {
        $other = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $other->id]);

        $this->getJson("/api/v1/links/{$link->id}/stats", $this->auth())
            ->assertStatus(404);
    }

    // =========================================================
    // RATE LIMIT
    // =========================================================

    public function test_v1_is_rate_limited_60_per_minute_per_token(): void
    {
        foreach (range(1, 60) as $i) {
            $this->getJson('/api/v1/links', $this->auth())->assertOk();
        }

        $this->getJson('/api/v1/links', $this->auth())->assertStatus(429);
    }
}
