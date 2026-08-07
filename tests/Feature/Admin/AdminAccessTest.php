<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato de acesso do /api/admin/*: só JWT de conta com is_admin = true
 * passa. 401 anônimo, 403 não-admin, 403 email não verificado, isolamento
 * do guard Sanctum (API key NUNCA autentica aqui).
 *
 * O middleware RETORNA o 403 (nunca abort()): o catch-all de
 * bootstrap/app.php transformaria a exceção em 500.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $jwt;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->admin->forceFill(['is_admin' => true])->saveQuietly();
        $this->jwt = auth()->guard('api')->login($this->admin);
    }

    /** @return array{Authorization: string} */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->jwt}"];
    }

    /** Limpa guards em memória entre requests com credenciais distintas. */
    private function flushGuards(): void
    {
        $this->flushAuthState();
    }

    public function test_anonymous_gets_401(): void
    {
        $this->flushGuards();
        $this->getJson('/api/admin/overview')->assertStatus(401);
    }

    public function test_non_admin_gets_403_forbidden(): void
    {
        $user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $jwt = auth()->guard('api')->login($user);
        $this->flushGuards();

        $this->getJson('/api/admin/overview', ['Authorization' => "Bearer {$jwt}"])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_unverified_admin_gets_403(): void
    {
        $unverified = User::factory()->create([
            'email_verified' => false,
            'email_verified_at' => null,
        ]);
        $unverified->forceFill(['is_admin' => true])->saveQuietly();
        $jwt = auth()->guard('api')->login($unverified);
        $this->flushGuards();

        $this->getJson('/api/admin/overview', ['Authorization' => "Bearer {$jwt}"])
            ->assertStatus(403);
    }

    public function test_admin_gets_200(): void
    {
        $this->getJson('/api/admin/overview', $this->auth())->assertOk();
    }

    public function test_sanctum_token_cannot_reach_admin(): void
    {
        $plainToken = $this->admin->createToken('api key')->plainTextToken;
        $this->flushGuards();

        $this->getJson('/api/admin/overview', ['Authorization' => "Bearer {$plainToken}"])
            ->assertStatus(401);
    }

    public function test_revoking_is_admin_takes_effect_immediately(): void
    {
        $this->getJson('/api/admin/overview', $this->auth())->assertOk();

        $this->admin->forceFill(['is_admin' => false])->saveQuietly();
        $this->flushGuards();

        $this->getJson('/api/admin/overview', $this->auth())->assertStatus(403);
    }
}
