<?php

namespace Tests\Feature\Onboarding;

use App\Jobs\SeedDemoLinkJob;
use App\Jobs\SendWelcomeEmailJob;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WelcomeEmailDispatchTest extends TestCase
{
    use RefreshDatabase;

    /** Criar um usuário enfileira o job (o job é quem decide se envia). */
    public function test_creating_a_user_dispatches_the_welcome_job(): void
    {
        Queue::fake();

        $user = User::factory()->create(['auth0_sub' => 'google-oauth2|789']);

        Queue::assertPushed(
            SendWelcomeEmailJob::class,
            fn (SendWelcomeEmailJob $job) => $job->userId === $user->id
        );

        Queue::assertPushed(
            SeedDemoLinkJob::class,
            fn (SeedDemoLinkJob $job) => $job->userId === $user->id
        );
    }

    /** Verificar o e-mail enfileira o job de novo — é assim que o fluxo e-mail/senha recebe. */
    public function test_verifying_the_email_dispatches_the_welcome_job(): void
    {
        Queue::fake();

        $user = User::factory()->unverified()->create([
            'email' => 'joao@example.com',
            'auth0_sub' => null,
        ]);

        $token = EmailVerificationToken::createEmailVerificationToken($user->email);

        // O banco guarda só o sha256; o token cru vive em $plainTextToken.
        $result = app(EmailVerificationService::class)->verifyEmail($token->plainTextToken);

        $this->assertTrue($result['success']);
        $this->assertSame('verified', $result['type']);

        Queue::assertPushed(
            SendWelcomeEmailJob::class,
            fn (SendWelcomeEmailJob $job) => $job->userId === $user->id
        );
    }
}
