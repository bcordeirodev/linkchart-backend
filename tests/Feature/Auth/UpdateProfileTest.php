<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUT /api/profile — foco no opt-in/opt-out do digest semanal
 * (weekly_digest_enabled), o campo que o toggle do perfil no frontend usa.
 */
class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    private function makeVerifiedUser(): User
    {
        return User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** O toggle desliga o digest e o estado volta na resposta. */
    public function test_disables_weekly_digest_via_profile_update(): void
    {
        $user = $this->makeVerifiedUser();

        $this->actingAs($user, 'api')
            ->putJson('/api/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'weekly_digest_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.user.weekly_digest_enabled', false);

        $this->assertFalse($user->fresh()->weekly_digest_enabled);
    }

    /** Religar depois de um opt-out (ex.: veio do link de unsubscribe). */
    public function test_reenables_weekly_digest_via_profile_update(): void
    {
        $user = $this->makeVerifiedUser();
        User::whereKey($user->id)->update(['weekly_digest_enabled' => false]);

        $this->actingAs($user, 'api')
            ->putJson('/api/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'weekly_digest_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.user.weekly_digest_enabled', true);

        $this->assertTrue($user->fresh()->weekly_digest_enabled);
    }

    /** O campo é opcional: update só de nome/email não mexe na preferência. */
    public function test_weekly_digest_flag_is_optional_and_untouched(): void
    {
        $user = $this->makeVerifiedUser();

        $this->actingAs($user, 'api')
            ->putJson('/api/profile', [
                'name' => 'Novo Nome',
                'email' => $user->email,
            ])
            ->assertOk();

        $this->assertTrue($user->fresh()->weekly_digest_enabled);
        $this->assertSame('Novo Nome', $user->fresh()->name);
    }

    /** Valor não-booleano é rejeitado com 422. */
    public function test_rejects_non_boolean_weekly_digest_value(): void
    {
        $user = $this->makeVerifiedUser();

        $this->actingAs($user, 'api')
            ->putJson('/api/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'weekly_digest_enabled' => 'talvez',
            ])
            ->assertStatus(422);

        $this->assertTrue($user->fresh()->weekly_digest_enabled);
    }
}
