<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Cobre a gestão de API keys do painel (/api/api-keys).
 *
 * Contrato fixo (frontend construído em paralelo):
 *   GET    /api/api-keys        → {data: [{id, name, token_preview, last_used_at, created_at}]}
 *   POST   /api/api-keys        → 201 {data: {id, name, token}} — token completo SÓ aqui
 *   DELETE /api/api-keys/{id}   → {data: {deleted: true}}; 404 se de outro usuário
 *   Máximo 5 keys por usuário (422); rate limit `api-keys` 10/min por usuário.
 *
 * Rotas protegidas pela auth do painel (api.auth:api + verified) — um token
 * Sanctum NÃO deve autenticar aqui (isolamento de guards).
 */
class ApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $jwt;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->jwt = auth()->guard('api')->login($this->user);
    }

    /**
     * Authorization header for the panel JWT session.
     *
     * @return array{Authorization: string}
     */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->jwt}"];
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

    public function test_store_returns_201_with_full_token_only_once(): void
    {
        $response = $this->postJson('/api/api-keys', ['name' => 'CI deploy key'], $this->auth());

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name', 'token']]);

        $this->assertSame('CI deploy key', $response->json('data.name'));
        // Sanctum plainTextToken format: "{id}|{random}".
        $this->assertStringContainsString('|', $response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'CI deploy key',
        ]);
    }

    public function test_full_flow_create_list_revoke(): void
    {
        $create = $this->postJson('/api/api-keys', ['name' => 'Zapier'], $this->auth());
        $create->assertStatus(201);
        $plainToken = $create->json('data.token');
        $keyId = $create->json('data.id');

        // List shows the preview (last 4 chars) and never the full token.
        $list = $this->getJson('/api/api-keys', $this->auth());
        $list->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'token_preview', 'last_used_at', 'created_at']]]);
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('…'.substr($plainToken, -4), $list->json('data.0.token_preview'));
        $this->assertStringNotContainsString($plainToken, $list->getContent());
        $this->assertNull($list->json('data.0.last_used_at'));

        // Revoke.
        $this->deleteJson("/api/api-keys/{$keyId}", [], $this->auth())
            ->assertOk()
            ->assertJson(['data' => ['deleted' => true]]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $keyId]);

        // Revoking again → 404.
        $this->deleteJson("/api/api-keys/{$keyId}", [], $this->auth())
            ->assertStatus(404);

        // The revoked token no longer authenticates on the public API.
        $this->flushGuards();
        $this->getJson('/api/v1/links', ['Authorization' => "Bearer {$plainToken}"])
            ->assertStatus(401);
    }

    public function test_store_caps_at_five_keys_per_user(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/api-keys', ['name' => "key {$i}"], $this->auth())
                ->assertStatus(201);
        }

        $this->postJson('/api/api-keys', ['name' => 'one too many'], $this->auth())
            ->assertStatus(422);

        $this->assertSame(5, PersonalAccessToken::where('tokenable_id', $this->user->id)->count());
    }

    public function test_store_validates_name(): void
    {
        $this->postJson('/api/api-keys', [], $this->auth())->assertStatus(422);
        $this->postJson('/api/api-keys', ['name' => str_repeat('a', 61)], $this->auth())
            ->assertStatus(422);
    }

    public function test_destroy_denies_other_users_key(): void
    {
        $other = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $foreign = $other->createToken('foreign key');

        $this->deleteJson('/api/api-keys/'.$foreign->accessToken->id, [], $this->auth())
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->accessToken->id]);
    }

    public function test_index_lists_only_own_keys(): void
    {
        $other = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $other->createToken('foreign key');
        $this->user->createToken('mine');

        $list = $this->getJson('/api/api-keys', $this->auth());

        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame('mine', $list->json('data.0.name'));
    }

    public function test_management_routes_reject_sanctum_token(): void
    {
        $plainToken = $this->user->createToken('api key')->plainTextToken;
        $this->flushGuards();

        $this->getJson('/api/api-keys', ['Authorization' => "Bearer {$plainToken}"])
            ->assertStatus(401);
    }

    public function test_management_routes_require_auth(): void
    {
        // setUp() logou o usuário no guard JWT em memória; limpa o estado para
        // simular uma request realmente anônima (sem header Authorization).
        $this->flushGuards();

        $this->getJson('/api/api-keys')->assertStatus(401);
    }

    public function test_management_routes_are_rate_limited_10_per_minute(): void
    {
        foreach (range(1, 10) as $i) {
            $this->getJson('/api/api-keys', $this->auth())->assertOk();
        }

        $this->getJson('/api/api-keys', $this->auth())->assertStatus(429);
    }
}
