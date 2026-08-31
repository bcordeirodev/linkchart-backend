<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendActivationNudgeEmailJob;
use App\Models\Link;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Comportamento do envio do nudge de ativação: conteúdo, claim at-most-once,
 * skips (inclusive "criou o primeiro link entre o disparo e o envio") e falha do
 * provedor ⇒ job failed sem retry.
 */
class SendActivationNudgeEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /** Caminho feliz: convite curto, CTA com UTM, unsubscribe assinado, claim gravado. */
    public function test_sends_nudge_with_cta_and_claims_the_column(): void
    {
        $user = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(function (string $to, string $subject, string $html, ?string $text, ?string $name, string $type) {
                return $to === 'ana@example.com'
                    && $subject === 'Seu primeiro link encurtado leva 10 segundos'
                    && str_contains($html, 'Ana Souza')
                    && str_contains($html, 'Criar meu primeiro link')
                    && str_contains($html, '/links?utm_source=activation-nudge')
                    && str_contains($html, 'utm_medium=email')
                    && str_contains($html, '/email/digest/unsubscribe/')
                    && str_contains($html, 'signature=')
                    && is_string($text)
                    && str_contains($text, 'Criar meu primeiro link')
                    // Corpo texto NÃO pode escapar o & do UTM (viraria amp;utm_medium).
                    && str_contains($text, '?utm_source=activation-nudge&utm_medium=email')
                    && ! str_contains($text, '&amp;')
                    && $name === 'Ana Souza'
                    && $type === 'activation_nudge';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendActivationNudgeEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->activation_nudge_sent_at);
    }

    /** Criou o primeiro link entre o disparo e o envio: parabéns e silêncio. */
    public function test_skips_when_user_activated_between_dispatch_and_send(): void
    {
        $user = User::factory()->create();
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendActivationNudgeEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->activation_nudge_sent_at);
    }

    /** O link demo semeado no cadastro não bloqueia o envio. */
    public function test_demo_link_does_not_block_the_send(): void
    {
        $user = User::factory()->create();
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendActivationNudgeEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->activation_nudge_sent_at);
    }

    /** Usuário apagado entre dispatch e execução — no-op limpo. */
    public function test_missing_user_is_a_noop(): void
    {
        $user = User::factory()->create();
        $userId = $user->id;
        $user->delete();

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendActivationNudgeEmailJob($userId))->handle($emailService);
    }

    /** Opt-out entre o disparo e a execução é respeitado. */
    public function test_skips_when_user_opted_out(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendActivationNudgeEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->activation_nudge_sent_at);
    }

    /** O claim atômico impede reenvio num segundo run (retry / dispatch duplicado). */
    public function test_sends_at_most_once_across_runs(): void
    {
        $user = User::factory()->create();

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendActivationNudgeEmailJob($user->id))->handle($emailService);
        (new SendActivationNudgeEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->activation_nudge_sent_at);
    }

    /** Corrida: quem perde o claim (0 linhas) sai quieto, sem segundo e-mail. */
    public function test_losing_the_claim_race_skips_the_send(): void
    {
        $user = User::factory()->create(['activation_nudge_sent_at' => now()]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendActivationNudgeEmailJob($user->id))->handle($emailService);
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

        SendActivationNudgeEmailJob::dispatch($user->id);

        $this->assertNotNull($failedEvent, 'O job deveria se marcar como falho quando o provedor recusa o envio.');
        $this->assertSame(SendActivationNudgeEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('quota estourada', $failedEvent->exception->getMessage());

        // Claim feito antes do envio permanece — at-most-once deliberado.
        $this->assertNotNull($user->fresh()->activation_nudge_sent_at);
    }
}
