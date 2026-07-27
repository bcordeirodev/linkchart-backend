<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendWelcomeEmailJob;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Characterization tests for the email-verification flow:
 * POST /api/auth/verify-email (public, throttle:login) and
 * POST /api/resend-verification-email (JWT, throttle:resend-verification 5/min).
 *
 * Tokens are minted directly through EmailVerificationToken (the same code
 * path EmailVerificationService uses) — the token column holds the exact
 * string the user receives by email. EmailService is mocked for the resend
 * tests so nothing tries to reach SendGrid.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replaces EmailService in the container so the verification email
     * "delivery" always succeeds without leaving the process.
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
     * A valid token verifies the account: email_verified + timestamp are set,
     * the token is consumed, and the welcome email job gets its second
     * dispatch (the first happens on UserObserver::created).
     */
    public function test_verify_email_with_valid_token_marks_user_verified(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'verifyme@example.com']);
        $token = EmailVerificationToken::createEmailVerificationToken('verifyme@example.com');

        $this->postJson('/api/auth/verify-email', ['token' => $token->token])
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.type', 'verified');

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasVerifiedEmail());
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertTrue($token->fresh()->used);

        // Two dispatch points: UserObserver::created + successful verification.
        Queue::assertPushed(SendWelcomeEmailJob::class, 2);
    }

    /**
     * A token that never existed is rejected with 400/invalid_token and the
     * user stays unverified.
     */
    public function test_verify_email_rejects_unknown_token(): void
    {
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/auth/verify-email', ['token' => str_repeat('f', 64)])
            ->assertStatus(400)
            ->assertJsonPath('error.details.type', 'invalid_token');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * A token past its 24-hour TTL is rejected and the user stays unverified.
     */
    public function test_verify_email_rejects_expired_token(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'late@example.com']);
        $token = EmailVerificationToken::createEmailVerificationToken('late@example.com');
        $token->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/verify-email', ['token' => $token->token])
            ->assertStatus(400)
            ->assertJsonPath('error.details.type', 'invalid_token');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * A verification token is single-use: replaying it after a successful
     * verification fails with 400/invalid_token.
     */
    public function test_verify_email_token_is_single_use(): void
    {
        User::factory()->unverified()->create(['email' => 'once@example.com']);
        $token = EmailVerificationToken::createEmailVerificationToken('once@example.com');

        $this->postJson('/api/auth/verify-email', ['token' => $token->token])->assertOk();

        $this->postJson('/api/auth/verify-email', ['token' => $token->token])
            ->assertStatus(400)
            ->assertJsonPath('error.details.type', 'invalid_token');
    }

    /**
     * Verifying an already-verified account is idempotent: 200 with
     * type=already_verified, and the token is still consumed.
     */
    public function test_verify_email_is_idempotent_for_already_verified_user(): void
    {
        User::factory()->create(['email' => 'done@example.com']); // verified by default
        $token = EmailVerificationToken::createEmailVerificationToken('done@example.com');

        $this->postJson('/api/auth/verify-email', ['token' => $token->token])
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.type', 'already_verified');

        $this->assertTrue($token->fresh()->used);
    }

    /**
     * The token field is required and must be exactly 64 characters — 422
     * before any lookup happens.
     */
    public function test_verify_email_validates_token_format(): void
    {
        $this->postJson('/api/auth/verify-email', [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['token']]]]);

        $this->postJson('/api/auth/verify-email', ['token' => 'short'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['token']]]]);
    }

    /**
     * Resending the verification email works for an authenticated unverified
     * user: 200, cooldown timestamp recorded, fresh token persisted.
     */
    public function test_resend_verification_email_sends_and_records_cooldown(): void
    {
        $this->fakeEmailDelivery();
        $user = User::factory()->unverified()->create(['email' => 'resend@example.com']);

        $this->actingAs($user, 'api')
            ->postJson('/api/resend-verification-email')
            ->assertOk()
            ->assertJsonPath('data.success', true);

        $this->assertNotNull($user->fresh()->email_verification_sent_at);
        $this->assertDatabaseHas('email_verification_tokens', [
            'email' => 'resend@example.com',
            'type' => 'email_verification',
            'used' => false,
        ]);
    }

    /**
     * The model-level cooldown blocks a second resend within 2 minutes with
     * 400/rate_limit (this is the application cooldown, distinct from the
     * route throttle below).
     */
    public function test_resend_verification_email_enforces_two_minute_cooldown(): void
    {
        $this->fakeEmailDelivery();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/resend-verification-email')
            ->assertOk();

        $this->actingAs($user, 'api')
            ->postJson('/api/resend-verification-email')
            ->assertStatus(400)
            ->assertJsonPath('error.details.type', 'rate_limit');
    }

    /**
     * The route throttle (resend-verification, 5/min per user) returns 429 on
     * the sixth request within a minute, regardless of the cooldown responses.
     */
    public function test_resend_verification_email_throttles_after_five_requests(): void
    {
        $this->fakeEmailDelivery();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user, 'api');

        // 1st request sends (200); requests 2–5 hit the model cooldown (400).
        $this->postJson('/api/resend-verification-email')->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/resend-verification-email')->assertStatus(400);
        }

        // 6th request within the same minute trips the route throttle.
        $this->postJson('/api/resend-verification-email')->assertStatus(429);
    }

    /**
     * An already-verified user cannot trigger a resend: 400 with
     * type=already_verified and no new token created.
     */
    public function test_resend_verification_email_rejects_already_verified_user(): void
    {
        $user = User::factory()->create(['email' => 'green@example.com']); // verified

        $this->actingAs($user, 'api')
            ->postJson('/api/resend-verification-email')
            ->assertStatus(400)
            ->assertJsonPath('error.details.type', 'already_verified');

        $this->assertDatabaseMissing('email_verification_tokens', ['email' => 'green@example.com']);
    }

    /**
     * The resend endpoint requires a valid JWT — anonymous requests get 401.
     */
    public function test_resend_verification_email_requires_authentication(): void
    {
        $this->postJson('/api/resend-verification-email')->assertStatus(401);
    }
}
