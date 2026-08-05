<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendMilestoneEmailJob;
use App\Models\Link;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Comportamento do envio individual do marco de 100 cliques: conteúdo do
 * e-mail, claim at-most-once, skips (link sumido / abaixo do limiar / dono
 * inelegível) e falha do provedor ⇒ job failed sem retry — os mesmos contratos
 * do {@see \Tests\Unit\Jobs\SendWeeklyDigestEmailJobTest}.
 */
class SendMilestoneEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Atalho: link não-demo do usuário, com N cliques e sem notificação prévia.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do link.
     */
    private function link(User $user, int $clicks = 100, array $attributes = []): Link
    {
        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_demo' => false,
            'clicks' => $clicks,
            'milestone_100_notified_at' => null,
        ], $attributes));
    }

    /**
     * Caminho feliz: título do link, total de cliques, CTA com UTM de marco e
     * link assinado de unsubscribe — tudo no e-mail; claim gravado no link.
     */
    public function test_sends_milestone_email_with_link_details(): void
    {
        $user = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
        $link = $this->link($user, 137, ['title' => 'Meu Portfólio']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(function (string $to, string $subject, string $html, ?string $text, ?string $name, string $type) use ($link) {
                return $to === 'ana@example.com'
                    && str_contains($subject, '100 cliques')
                    && str_contains($html, 'Ana Souza')
                    && str_contains($html, 'Meu Portfólio')
                    && str_contains($html, '137')
                    // O `&` do querystring sai escapado como `&amp;` no HTML — asserção por parte.
                    && str_contains($html, '/links/analytics/'.$link->id.'?utm_source=milestone-email')
                    && str_contains($html, 'utm_medium=email')
                    && str_contains($html, '/email/digest/unsubscribe/')
                    && str_contains($html, 'signature=')
                    && is_string($text) && str_contains($text, 'Meu Portfólio')
                    && $name === 'Ana Souza'
                    && $type === 'milestone';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendMilestoneEmailJob($link->id))->handle($emailService);

        $this->assertNotNull($link->fresh()->milestone_100_notified_at);
    }

    /** Sem título, o e-mail identifica o link pelo slug. */
    public function test_falls_back_to_slug_when_link_has_no_title(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 100, ['title' => null, 'slug' => 'promo-verao']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(fn (string $to, string $subject, string $html) => str_contains($html, 'promo-verao'))
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendMilestoneEmailJob($link->id))->handle($emailService);
    }

    /** O claim atômico impede reenvio num segundo run (retry / dispatch duplicado). */
    public function test_sends_at_most_once_across_runs(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 100);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendMilestoneEmailJob($link->id))->handle($emailService);
        (new SendMilestoneEmailJob($link->id))->handle($emailService);

        $this->assertNotNull($link->fresh()->milestone_100_notified_at);
    }

    /** Link apagado entre dispatch e execução — no-op limpo. */
    public function test_missing_link_is_a_noop(): void
    {
        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob(999999))->handle($emailService);

        $this->assertSame(0, Link::whereNotNull('milestone_100_notified_at')->count());
    }

    /** Cliques abaixo do limiar na hora da execução: nada sai e o claim fica livre. */
    public function test_skips_without_claim_when_link_is_below_threshold(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 42);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob($link->id))->handle($emailService);

        $this->assertNull($link->fresh()->milestone_100_notified_at);
    }

    /** Opt-out entre o disparo e a execução é respeitado, sem queimar o claim. */
    public function test_skips_when_owner_opted_out(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);
        $link = $this->link($user, 100);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob($link->id))->handle($emailService);

        $this->assertNull($link->fresh()->milestone_100_notified_at);
    }

    /** Link anônimo (sem dono) não tem destinatário. */
    public function test_skips_when_link_has_no_owner(): void
    {
        $link = Link::factory()->create([
            'user_id' => null,
            'is_demo' => false,
            'clicks' => 150,
            'milestone_100_notified_at' => null,
        ]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob($link->id))->handle($emailService);

        $this->assertNull($link->fresh()->milestone_100_notified_at);
    }

    /**
     * Provedor recusa (success => false sem exceção): o job se marca como falho e
     * NÃO reenfileira — o claim já foi feito (at-most-once). dispatch() real via
     * fila sync para observar o evento JobFailed emitido por Job::fail().
     */
    public function test_marks_job_as_failed_without_retry_when_provider_rejects(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 100);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => false, 'error' => 'quota estourada']);
        $this->app->instance(EmailService::class, $emailService);

        $failedEvent = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        SendMilestoneEmailJob::dispatch($link->id);

        $this->assertNotNull($failedEvent, 'O job deveria se marcar como falho quando o provedor recusa o envio.');
        $this->assertSame(SendMilestoneEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('quota estourada', $failedEvent->exception->getMessage());

        // Claim feito antes do envio permanece — at-most-once deliberado.
        $this->assertNotNull($link->fresh()->milestone_100_notified_at);
    }
}
