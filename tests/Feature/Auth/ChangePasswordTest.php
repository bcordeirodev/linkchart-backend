<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Characterization tests for PUT /api/change-password.
 *
 * The route sits behind api.auth:api + verified, so it requires a JWT for a
 * user whose email is verified. UserFactory creates verified users with
 * password "password" by default.
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    /**
     * With the correct current password the new password is persisted for
     * real: Hash::check passes and a fresh login only works with the new one.
     */
    public function test_change_password_with_correct_current_password_succeeds(): void
    {
        $user = User::factory()->create(['email' => 'changer@example.com']);

        $this->actingAs($user, 'api')
            ->putJson('/api/change-password', [
                'current_password' => 'password',
                'new_password' => 'new-secret-123',
                'new_password_confirmation' => 'new-secret-123',
            ])
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));

        // End to end: the new password authenticates, the old one no longer does.
        $this->postJson('/api/auth/login', [
            'email' => 'changer@example.com',
            'password' => 'new-secret-123',
        ])->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'changer@example.com',
            'password' => 'password',
        ])->assertStatus(401);
    }

    /**
     * SECURITY (hardening 2): changing the password must kill every JWT issued
     * before the change, and — so the user doesn't get logged out mid-session —
     * the response must carry a NEW working JWT, also re-set as the httpOnly
     * auth_token cookie (same pattern as login/auth0-exchange).
     */
    public function test_change_password_invalidates_previous_jwt_and_returns_working_replacement(): void
    {
        $user = User::factory()->create(['email' => 'rotate@example.com']);

        $oldToken = $this->postJson('/api/auth/login', [
            'email' => 'rotate@example.com',
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->flushAuthState();
        $change = $this->putJson('/api/change-password', [
            'current_password' => 'password',
            'new_password' => 'new-secret-123',
            'new_password_confirmation' => 'new-secret-123',
        ], ['Authorization' => "Bearer {$oldToken}"]);

        $change->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonStructure(['data' => ['token']]);

        $newToken = $change->json('data.token');
        $this->assertNotSame($oldToken, $newToken);

        // The replacement JWT is re-set as the httpOnly auth cookie so browser
        // clients (which authenticate via the cookie) stay logged in.
        $change->assertCookie('auth_token');
        $cookie = $change->getCookie('auth_token', false); // raw JWT, not Laravel-encrypted
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame($newToken, $cookie->getValue());

        // The pre-change JWT is dead…
        $this->flushAuthState();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$oldToken}"])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        // …and the replacement authenticates normally.
        $this->flushAuthState();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$newToken}"])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    /**
     * A wrong current password is rejected with 422/INVALID_PASSWORD and the
     * stored password does not change.
     */
    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->putJson('/api/change-password', [
                'current_password' => 'not-my-password',
                'new_password' => 'new-secret-123',
                'new_password_confirmation' => 'new-secret-123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PASSWORD');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * The new password must be confirmed (new_password_confirmation) — a
     * mismatch fails validation with 422 and changes nothing.
     */
    public function test_change_password_requires_matching_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->putJson('/api/change-password', [
                'current_password' => 'password',
                'new_password' => 'new-secret-123',
                'new_password_confirmation' => 'other-secret-456',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['new_password']]]]);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * New passwords shorter than 6 characters fail validation with 422.
     */
    public function test_change_password_rejects_short_new_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->putJson('/api/change-password', [
                'current_password' => 'password',
                'new_password' => 'abc',
                'new_password_confirmation' => 'abc',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['new_password']]]]);
    }

    /**
     * Anonymous requests are rejected with 401 before reaching the controller.
     */
    public function test_change_password_requires_authentication(): void
    {
        $this->putJson('/api/change-password', [
            'current_password' => 'password',
            'new_password' => 'new-secret-123',
            'new_password_confirmation' => 'new-secret-123',
        ])->assertStatus(401);
    }

    /**
     * Users with an unverified email are blocked by the `verified` middleware
     * with 403/email_not_verified.
     */
    public function test_change_password_requires_verified_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'api')
            ->putJson('/api/change-password', [
                'current_password' => 'password',
                'new_password' => 'new-secret-123',
                'new_password_confirmation' => 'new-secret-123',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.details.type', 'email_not_verified');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
