<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cobre GET /api/v1/subdomains (API pública, auth via Sanctum Bearer).
 *
 * Contrato: { data: [{ subdomain, host, is_default, created_at }] } — só os
 * endereços ATIVOS do dono do token, do mais antigo para o mais novo, com
 * `is_default` no mais antigo (o mesmo que POST /api/v1/links usa quando o
 * campo `subdomain` está ausente). O id interno NÃO aparece: o contrato da
 * API é por nome.
 */
class PublicApiSubdomainsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.domain' => 'linkcharts.com.br']);
        Cache::flush();
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

    public function test_index_requires_token(): void
    {
        $this->getJson('/api/v1/subdomains')->assertStatus(401);
    }

    /** Lista só os ativos do dono do token, mais antigo primeiro. */
    public function test_index_lists_only_owner_active_subdomains(): void
    {
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'acme']);
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'shop']);
        UserSubdomain::factory()->create([
            'user_id' => $this->user->id,
            'subdomain' => 'released',
            'status' => 'inactive',
        ]);
        $other = User::factory()->create();
        UserSubdomain::factory()->create(['user_id' => $other->id, 'subdomain' => 'foreign']);

        $response = $this->getJson('/api/v1/subdomains', $this->auth());

        $response->assertOk();
        $this->assertSame(
            ['acme', 'shop'],
            array_column($response->json('data'), 'subdomain'),
        );
    }

    /** Shape do item: subdomain, host completo, is_default, created_at — sem id interno. */
    public function test_index_item_shape_has_host_and_no_internal_id(): void
    {
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'shop']);

        $item = $this->getJson('/api/v1/subdomains', $this->auth())->json('data.0');

        $this->assertSame('shop', $item['subdomain']);
        $this->assertSame('shop.linkcharts.com.br', $item['host']);
        $this->assertIsBool($item['is_default']);
        $this->assertNotNull($item['created_at']);
        $this->assertArrayNotHasKey('id', $item);
    }

    /** is_default marca o ativo mais antigo — o mesmo default do POST /v1/links. */
    public function test_index_marks_oldest_active_as_default(): void
    {
        UserSubdomain::factory()->create([
            'user_id' => $this->user->id,
            'subdomain' => 'released',
            'status' => 'inactive',
        ]);
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'acme']);
        UserSubdomain::factory()->create(['user_id' => $this->user->id, 'subdomain' => 'shop']);

        $data = $this->getJson('/api/v1/subdomains', $this->auth())->json('data');

        $defaults = array_column($data, 'is_default', 'subdomain');
        $this->assertTrue($defaults['acme']);
        $this->assertFalse($defaults['shop']);
    }

    /** Conta sem endereço personalizado → lista vazia, não erro. */
    public function test_index_returns_empty_list_without_subdomains(): void
    {
        $this->getJson('/api/v1/subdomains', $this->auth())
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
