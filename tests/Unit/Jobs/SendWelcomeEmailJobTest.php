<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendWelcomeEmailJob;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SendWelcomeEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /** Usuário Auth0 é verificado por construção — o e-mail sai. */
    public function test_sends_email_to_verified_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
            'auth0_sub' => 'google-oauth2|123',
        ]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendEmailViaSendGridAPI')
            ->once()
            ->withArgs(function (string $to, string $subject, string $html, ?string $text, ?string $name) {
                return $to === 'ana@example.com'
                    && str_contains($subject, 'Bem-vindo')
                    && str_contains($html, 'Ana Souza')
                    && $name === 'Ana Souza';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWelcomeEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->welcome_email_sent_at);
    }

    /** Usuário não verificado (fluxo e-mail/senha antes de clicar no link) — nada sai. */
    public function test_does_not_send_to_unverified_user(): void
    {
        // ATENÇÃO: o UserFactory cria usuário JÁ VERIFICADO por padrão
        // (definition() seta email_verified = true). O state ->unverified()
        // é obrigatório aqui, senão o teste passa por engano.
        $user = User::factory()->unverified()->create(['auth0_sub' => null]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendEmailViaSendGridAPI');

        (new SendWelcomeEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->welcome_email_sent_at);
    }

    /** O claim atômico impede que um retry reenvie o e-mail. */
    public function test_sends_at_most_once_across_runs(): void
    {
        $user = User::factory()->create(['auth0_sub' => 'google-oauth2|456']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendEmailViaSendGridAPI')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWelcomeEmailJob($user->id))->handle($emailService);
        (new SendWelcomeEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->welcome_email_sent_at);
    }

    /** Usuário apagado entre o dispatch e a execução — o job sai limpo, sem enviar e sem escrever. */
    public function test_missing_user_is_a_noop(): void
    {
        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendEmailViaSendGridAPI');

        (new SendWelcomeEmailJob(999999))->handle($emailService);

        $this->assertSame(0, User::whereNotNull('welcome_email_sent_at')->count());
    }
}
