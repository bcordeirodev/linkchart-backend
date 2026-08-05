<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendOnboardingTipsEmailJob;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Comportamento do envio das dicas de terceiro dia: as 3 dicas no conteúdo,
 * claim at-most-once, skips e falha do provedor ⇒ job failed sem retry.
 */
class SendOnboardingTipsEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /** Caminho feliz: as 3 dicas, o CTA com UTM, o unsubscribe e o claim gravado. */
    public function test_sends_the_three_tips(): void
    {
        $user = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(function (string $to, string $subject, string $html, ?string $text, ?string $name, string $type) {
                return $to === 'ana@example.com'
                    && str_contains($subject, 'quase ninguém usa')
                    && str_contains($html, 'Ana Souza')
                    && str_contains($html, 'Endereço personalizado')
                    && str_contains($html, '3 endereços')
                    && str_contains($html, 'bio')
                    && str_contains($html, 'gerador de UTM')
                    && str_contains($html, '/ferramentas/gerador-utm')
                    && str_contains($html, '/links?utm_source=onboarding-email')
                    && str_contains($html, 'utm_medium=email')
                    && str_contains($html, '/email/digest/unsubscribe/')
                    && str_contains($html, 'signature=')
                    && is_string($text) && str_contains($text, 'Gerador de UTM')
                    && $name === 'Ana Souza'
                    && $type === 'onboarding_tips';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendOnboardingTipsEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->onboarding_tips_sent_at);
    }

    /** O claim atômico impede reenvio num segundo run (retry / dispatch duplicado). */
    public function test_sends_at_most_once_across_runs(): void
    {
        $user = User::factory()->create();

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendOnboardingTipsEmailJob($user->id))->handle($emailService);
        (new SendOnboardingTipsEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->onboarding_tips_sent_at);
    }

    /** Usuário apagado entre dispatch e execução — no-op limpo. */
    public function test_missing_user_is_a_noop(): void
    {
        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendOnboardingTipsEmailJob(999999))->handle($emailService);

        $this->assertSame(0, User::whereNotNull('onboarding_tips_sent_at')->count());
    }

    /** Opt-out entre o disparo e a execução é respeitado, sem queimar o claim. */
    public function test_skips_when_user_opted_out(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendOnboardingTipsEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->onboarding_tips_sent_at);
    }

    /** Usuário que ainda não verificou o e-mail não recebe dicas. */
    public function test_skips_unverified_user(): void
    {
        $user = User::factory()->unverified()->create(['auth0_sub' => null]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendOnboardingTipsEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->onboarding_tips_sent_at);
    }

    /**
     * Provedor recusa (success => false sem exceção): o job se marca como falho e
     * NÃO reenfileira — o claim já foi feito (at-most-once).
     */
    public function test_marks_job_as_failed_without_retry_when_provider_rejects(): void
    {
        $user = User::factory()->create();

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => false, 'error' => 'quota estourada']);
        $this->app->instance(EmailService::class, $emailService);

        $failedEvent = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        SendOnboardingTipsEmailJob::dispatch($user->id);

        $this->assertNotNull($failedEvent, 'O job deveria se marcar como falho quando o provedor recusa o envio.');
        $this->assertSame(SendOnboardingTipsEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('quota estourada', $failedEvent->exception->getMessage());

        // Claim feito antes do envio permanece — at-most-once deliberado.
        $this->assertNotNull($user->fresh()->onboarding_tips_sent_at);
    }

    /** O claim é interno: nunca sai serializado numa resposta da API. */
    public function test_claim_column_is_hidden_from_serialization(): void
    {
        $user = User::factory()->create(['onboarding_tips_sent_at' => now()]);

        $this->assertArrayNotHasKey('onboarding_tips_sent_at', $user->fresh()->toArray());
    }
}
