<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization tests for POST /api/auth/login.
 *
 * Rate limiting (5/min per email + 20/min per IP) is covered separately by
 * LoginRateLimitTest — each test here stays well under both limits, and the
 * array cache store gives every test a fresh limiter anyway.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Valid credentials return 200 with a JWT that authenticates a protected
     * route, plus the serialized user.
     */
    public function test_login_returns_usable_jwt_for_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'login@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'password', // UserFactory default
        ]);

        $response->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $token = $response->json('data.token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    /**
     * The serialized user in the login response must not expose the password
     * hash or remember_token (User::$hidden).
     */
    public function test_login_response_does_not_leak_password_hash(): void
    {
        User::factory()->create(['email' => 'hidden@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'hidden@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data.user'));
    }

    /**
     * A wrong password returns 401 with the normalized UNAUTHORIZED error and
     * no token anywhere in the payload.
     */
    public function test_login_rejects_wrong_password_with_401(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED')
            ->assertJsonPath('error.message', 'Credenciais inválidas');
        $this->assertArrayNotHasKey('data', $response->json());
    }

    /**
     * An email that does not exist returns the same 401 as a wrong password
     * (no user-enumeration signal in the status or message).
     */
    public function test_login_rejects_unknown_email_with_401(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'whatever-123',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED')
            ->assertJsonPath('error.message', 'Credenciais inválidas');
    }

    /**
     * Auth0-provisioned accounts have password = null and must never be able
     * to authenticate via the email/password endpoint.
     */
    public function test_auth0_user_without_password_cannot_login_with_password(): void
    {
        User::factory()->create([
            'email' => 'auth0user@example.com',
            'password' => null,
            'auth0_sub' => 'google-oauth2|12345',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'auth0user@example.com',
            'password' => 'any-guess-123',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    /**
     * email and password are both required — an empty payload returns 422
     * with a validation error per field.
     */
    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['email', 'password']]]]);
    }

    /**
     * Passwords shorter than 6 chars fail validation (422) before any
     * credential check happens — never a 401 for these.
     */
    public function test_login_rejects_short_password_as_validation_error(): void
    {
        User::factory()->create(['email' => 'shorty@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'shorty@example.com',
            'password' => 'abc',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['password']]]]);
    }
}
