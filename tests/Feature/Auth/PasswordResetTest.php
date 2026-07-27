<?php

namespace Tests\Feature\Auth;

use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Characterization tests for the password-reset flow:
 * POST /api/auth/forgot-password and POST /api/auth/reset-password.
 *
 * The token stored in email_verification_tokens.token is the exact string
 * emailed to the user (findValidToken matches it verbatim), so the tests read
 * it straight from the database to exercise the flow end to end. EmailService
 * is mocked so no real SendGrid delivery is attempted.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replaces EmailService in the container so the reset email "delivery"
     * always succeeds without leaving the process.
     */
    private function fakeEmailDelivery(): void
    {
        $this->mock(EmailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendEmailViaSendGridAPI')->andReturn([
                'success' => true,
                'message' => 'Email enviado (fake)',
            ]);
        });
    }

    /**
     * Fetches the newest unused password-reset token for an email straight
     * from the database (stands in for reading the reset link in the email).
     */
    private function latestResetToken(string $email): EmailVerificationToken
    {
        return EmailVerificationToken::where('email', $email)
            ->where('type', EmailVerificationToken::TYPE_PASSWORD_RESET)
            ->where('used', false)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * Requesting a reset for an existing account returns 200 and persists a
     * single-use password_reset token for that email.
     */
    public function test_forgot_password_creates_reset_token_for_known_email(): void
    {
        $this->fakeEmailDelivery();
        User::factory()->create(['email' => 'forgot@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'forgot@example.com'])
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.type', 'email_sent');

        $this->assertDatabaseHas('email_verification_tokens', [
            'email' => 'forgot@example.com',
            'type' => 'password_reset',
            'used' => false,
        ]);
    }

    /**
     * An unknown email gets the exact same 200 response as a known one (no
     * user enumeration) and no token is created.
     */
    public function test_forgot_password_does_not_reveal_whether_email_exists(): void
    {
        $this->fakeEmailDelivery();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'ghost@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json(), $unknown->json());

        $this->assertDatabaseMissing('email_verification_tokens', ['email' => 'ghost@example.com']);
    }

    /**
     * A malformed email fails validation with 422.
     */
    public function test_forgot_password_rejects_invalid_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['email']]]]);
    }

    /**
     * Full happy path: request reset, consume the token with a new password,
     * then prove the password really changed — login succeeds with the new
     * password and fails with the old one.
     */
    public function test_reset_password_with_valid_token_actually_changes_password(): void
    {
        $this->fakeEmailDelivery();
        User::factory()->create(['email' => 'reset@example.com']); // password: "password"

        $this->postJson('/api/auth/forgot-password', ['email' => 'reset@example.com'])->assertOk();
        $token = $this->latestResetToken('reset@example.com');

        $this->postJson('/api/auth/reset-password', [
            'token' => $token->token,
            'password' => 'brand-new-secret',
            'password_confirmation' => 'brand-new-secret',
        ])->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.type', 'password_reset');

        // Token was consumed.
        $this->assertTrue($token->fresh()->used);

        // The new password authenticates…
        $this->postJson('/api/auth/login', [
            'email' => 'reset@example.com',
            'password' => 'brand-new-secret',
        ])->assertOk();

        // …and the old one no longer does.
        $this->postJson('/api/auth/login', [
            'email' => 'reset@example.com',
            'password' => 'password',
        ])->assertStatus(401);
    }

    /**
     * A consumed token cannot be replayed: the second reset attempt fails with
     * 400/invalid_token and the password set by the first reset stays in place.
     */
    public function test_reset_token_cannot_be_reused(): void
    {
        $this->fakeEmailDelivery();
        $user = User::factory()->create(['email' => 'replay@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'replay@example.com'])->assertOk();
        $token = $this->latestResetToken('replay@example.com');

        $this->postJson('/api/auth/reset-password', [
            'token' => $token->token,
            'password' => 'first-new-secret',
            'password_confirmation' => 'first-new-secret',
        ])->assertOk();

        $this->postJson('/api/auth/reset-password', [
            'token' => $token->token,
            'password' => 'attacker-secret',
            'password_confirmation' => 'attacker-secret',
        ])->assertStatus(400)
            ->assertJsonPath('error.details.type', 'invalid_token');

        $this->assertTrue(Hash::check('first-new-secret', $user->fresh()->password));
        $this->assertFalse(Hash::check('attacker-secret', $user->fresh()->password));
    }

    /**
     * A token that never existed is rejected with 400/invalid_token.
     */
    public function test_reset_password_rejects_unknown_token(): void
    {
        User::factory()->create(['email' => 'nobody@example.com']);

        $this->postJson('/api/auth/reset-password', [
            'token' => str_repeat('a', 64),
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertStatus(400)
            ->assertJsonPath('error.details.type', 'invalid_token');
    }

    /**
     * An expired token (past its 1-hour TTL) is rejected and the password is
     * left untouched.
     */
    public function test_reset_password_rejects_expired_token(): void
    {
        $user = User::factory()->create(['email' => 'expired@example.com']);
        $token = EmailVerificationToken::createPasswordResetToken('expired@example.com');
        $token->update(['expires_at' => now()->subMinutes(5)]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token->token,
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertStatus(400)
            ->assertJsonPath('error.details.type', 'invalid_token');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * Requesting a new reset invalidates any previous unused token for the
     * same email — only the newest token works.
     */
    public function test_new_forgot_password_request_invalidates_previous_token(): void
    {
        $this->fakeEmailDelivery();
        User::factory()->create(['email' => 'twice@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'twice@example.com'])->assertOk();
        $firstToken = $this->latestResetToken('twice@example.com');

        $this->postJson('/api/auth/forgot-password', ['email' => 'twice@example.com'])->assertOk();

        // The first token was soft-invalidated by the second request.
        $this->assertTrue($firstToken->fresh()->used);
        $this->postJson('/api/auth/reset-password', [
            'token' => $firstToken->token,
            'password' => 'stale-secret-123',
            'password_confirmation' => 'stale-secret-123',
        ])->assertStatus(400);

        // The newest token still works.
        $secondToken = $this->latestResetToken('twice@example.com');
        $this->postJson('/api/auth/reset-password', [
            'token' => $secondToken->token,
            'password' => 'fresh-secret-123',
            'password_confirmation' => 'fresh-secret-123',
        ])->assertOk();
    }

    /**
     * The token must be exactly 64 characters and the new password must be
     * confirmed and at least 6 characters — otherwise 422.
     */
    public function test_reset_password_validates_token_size_and_password_rules(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token' => 'too-short',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['token']]]]);

        $this->postJson('/api/auth/reset-password', [
            'token' => str_repeat('b', 64),
            'password' => 'unconfirmed-123',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['password']]]]);

        $this->postJson('/api/auth/reset-password', [
            'token' => str_repeat('c', 64),
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['password']]]]);
    }
}
