<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Characterization tests for POST /api/auth/register.
 *
 * EmailService is mocked in the container so no real SendGrid call happens
 * (the SDK bypasses Laravel's Http facade, so Http::fake() would not catch it;
 * in the testing env the missing API key would fail the send and pollute
 * errors.log). All responses are wrapped by NormalizeApiResponse:
 * success => { data: {...} }, error => { error: { code, message, details? } }.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replaces EmailService in the container so the verification email
     * "delivery" is deterministic and never leaves the process.
     *
     * @param  bool  $success  Whether the fake delivery reports success.
     */
    private function fakeEmailDelivery(bool $success = true): void
    {
        $this->mock(EmailService::class, function (MockInterface $mock) use ($success) {
            $mock->shouldReceive('sendEmailViaSendGridAPI')->andReturn([
                'success' => $success,
                'message' => $success ? 'Email enviado (fake)' : 'Erro de entrega (fake)',
                'error' => $success ? null : 'delivery-failed',
            ]);
        });
    }

    /**
     * A valid registration creates the user with a bcrypt-hashed password,
     * flags the email as unverified, and returns a JWT that authenticates
     * a protected route immediately.
     */
    public function test_register_creates_user_with_hashed_password_and_returns_usable_jwt(): void
    {
        $this->fakeEmailDelivery();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'secret-123',
            'password_confirmation' => 'secret-123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.user.email', 'newuser@example.com')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->email_verified);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-123', $user->password));

        // The issued JWT must authenticate a protected route right away.
        $token = $response->json('data.token');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    /**
     * The serialized user in the response must never expose the password hash
     * or the remember_token (User::$hidden).
     */
    public function test_register_response_does_not_leak_password_hash(): void
    {
        $this->fakeEmailDelivery();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Leak Check',
            'email' => 'leak@example.com',
            'password' => 'secret-123',
            'password_confirmation' => 'secret-123',
        ]);

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data.user'));
    }

    /**
     * Registration reports the verification email as sent and persists the
     * single-use verification token (24h TTL) plus the sent-at cooldown mark.
     */
    public function test_register_sends_verification_email_and_creates_token(): void
    {
        $this->fakeEmailDelivery();

        $this->postJson('/api/auth/register', [
            'name' => 'Token User',
            'email' => 'token@example.com',
            'password' => 'secret-123',
            'password_confirmation' => 'secret-123',
        ])->assertStatus(201)
            ->assertJsonPath('data.email_verification.sent', true)
            ->assertJsonPath('data.email_verification.email', 'token@example.com');

        $this->assertDatabaseHas('email_verification_tokens', [
            'email' => 'token@example.com',
            'type' => 'email_verification',
            'used' => false,
        ]);
        $this->assertNotNull(
            User::where('email', 'token@example.com')->first()->email_verification_sent_at
        );
    }

    /**
     * EmailService never throws — a failed delivery must not abort the
     * registration: the account is still created (201) and the response
     * reports email_verification.sent = false.
     */
    public function test_register_succeeds_even_when_verification_email_delivery_fails(): void
    {
        $this->fakeEmailDelivery(success: false);

        $this->postJson('/api/auth/register', [
            'name' => 'No Email',
            'email' => 'noemail@example.com',
            'password' => 'secret-123',
            'password_confirmation' => 'secret-123',
        ])->assertStatus(201)
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.email_verification.sent', false);

        $this->assertDatabaseHas('users', ['email' => 'noemail@example.com']);
    }

    /**
     * Registering an email that already exists fails with 422 and does not
     * create a second account.
     */
    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Impostor',
            'email' => 'taken@example.com',
            'password' => 'secret-123',
            'password_confirmation' => 'secret-123',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['email']]]]);

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * name, email and password are all required — an empty payload returns 422
     * with one validation error per field and creates nothing.
     */
    public function test_register_requires_name_email_and_password(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonStructure([
                'error' => ['details' => ['errors' => ['name', 'email', 'password']]],
            ]);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * The password must be confirmed (password_confirmation) — a mismatch or
     * missing confirmation returns 422.
     */
    public function test_register_requires_matching_password_confirmation(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'No Confirm',
            'email' => 'noconfirm@example.com',
            'password' => 'secret-123',
            'password_confirmation' => 'different-456',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['password']]]]);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Passwords shorter than 6 characters are rejected with 422.
     */
    public function test_register_rejects_password_shorter_than_six_chars(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Short Pwd',
            'email' => 'short@example.com',
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['password']]]]);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * A malformed email address is rejected with 422.
     */
    public function test_register_rejects_invalid_email_format(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Bad Email',
            'email' => 'not-an-email',
            'password' => 'secret-123',
            'password_confirmation' => 'secret-123',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['email']]]]);

        $this->assertDatabaseCount('users', 0);
    }
}
