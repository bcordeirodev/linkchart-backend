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
