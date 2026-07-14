<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendWelcomeEmailJob;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class SendWelcomeEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /** Usuário Auth0 é verificado por construção — o e-mail sai. */
    public function test_sends_email_to_verified_user(): void
    {
        // Cria não-verificado e só depois liga o auth0_sub: como o UserObserver
        // agora também despacha SendWelcomeEmailJob em `created` (Task 3), criar
        // já-verificado faria esse dispatch automático (síncrono, QUEUE_CONNECTION=sync)
        // reivindicar welcome_email_sent_at antes deste teste rodar o job manualmente —
        // o handle() abaixo veria o claim já feito e nunca chamaria o mock.
        // update() dispara `updated`, não `created`, então o observer não reage de novo.
        $user = User::factory()->unverified()->create([
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
        ]);
        $user->update(['auth0_sub' => 'google-oauth2|123']);

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
        // Ver comentário em test_sends_email_to_verified_user: criar já-verificado
        // deixaria o dispatch automático do UserObserver reivindicar
        // welcome_email_sent_at antes deste teste chamar handle() manualmente.
        $user = User::factory()->unverified()->create();
        $user->update(['auth0_sub' => 'google-oauth2|456']);

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

    /**
     * Quando o SendGrid recusa o envio (`sendEmailViaSendGridAPI` retorna
     * `success => false` em vez de lançar), o job não pode reportar sucesso: ele precisa
     * se marcar como falho e NÃO ser reenfileirado (at-most-once — a claim já foi feita).
     *
     * `$this->fail()` só tem efeito real quando o job carrega uma instância de queue
     * `Job` (via `setJob()`); chamar `->handle()` diretamente deixa `$this->fail()` em
     * no-op silencioso. Por isso este teste dispara via `dispatch()`: o
     * `QUEUE_CONNECTION=sync` forçado em `phpunit.xml` executa a fila de forma síncrona,
     * mas ainda popula um `SyncJob` real — o mesmo caminho de um worker de produção —
     * permitindo observar o evento `JobFailed` real disparado por `Job::fail()`.
     */
    public function test_marks_job_as_failed_without_retry_when_sendgrid_rejects(): void
    {
        // Ver comentário em test_sends_email_to_verified_user: criar já-verificado
        // deixaria o dispatch automático do UserObserver reivindicar
        // welcome_email_sent_at (e disparar seu próprio JobFailed, fora da janela do
        // listener abaixo) antes do dispatch() manual deste teste.
        $user = User::factory()->unverified()->create();
        $user->update(['auth0_sub' => 'google-oauth2|789']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendEmailViaSendGridAPI')
            ->once()
            ->andReturn(['success' => false, 'error' => 'chave de API inválida']);
        $this->app->instance(EmailService::class, $emailService);

        $failedEvent = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        SendWelcomeEmailJob::dispatch($user->id);

        $this->assertNotNull(
            $failedEvent,
            'O job deveria se marcar como falho (evento JobFailed) quando o SendGrid recusa o envio.'
        );
        $this->assertSame(SendWelcomeEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('chave de API inválida', $failedEvent->exception->getMessage());

        // O claim (welcome_email_sent_at) já tinha sido feito antes do envio — a falha
        // não o libera nem dispara um retry, então a linha permanece marcada como enviada
        // mesmo o e-mail nunca tendo saído. Esse é o trade-off at-most-once deliberado.
        $this->assertNotNull($user->fresh()->welcome_email_sent_at);
    }
}
