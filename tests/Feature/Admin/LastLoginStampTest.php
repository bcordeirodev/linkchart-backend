<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * last_login_at deve ser estampado no login por email/senha (o fluxo Auth0
 * é coberto indiretamente: mesma linha forceFill/saveQuietly no exchange).
 * /api/me deve expor is_admin para o frontend montar o papel do usuário.
 */
class LastLoginStampTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_stamps_last_login_at(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->assertNull($user->last_login_at);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_me_exposes_is_admin(): void
    {
        $user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        // fresh(): a factory retorna o model só com os atributos passados na
        // criação — is_admin/last_login_at (defaults de coluna) nunca voltam
        // para a memória sem um refetch. guard('api')->login() cacheia esse
        // objeto no guard (reusado pela request getJson() abaixo dentro do
        // mesmo processo de teste), então sem o fresh() o JSON sai sem a
        // chave is_admin em vez de false.
        $jwt = auth()->guard('api')->login($user->fresh());

        $this->getJson('/api/me', ['Authorization' => "Bearer {$jwt}"])
            ->assertOk()
            ->assertJsonPath('data.user.is_admin', false);
    }
}
