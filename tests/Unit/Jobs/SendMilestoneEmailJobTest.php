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
 * Comportamento do envio individual de um degrau da escada de marcos: conteúdo
 * do e-mail, copy por degrau, claim at-most-once por degrau, skips (link sumido
 * / abaixo do limiar / dono inelegível / degrau já comemorado) e falha do
 * provedor ⇒ job failed sem retry — os mesmos contratos do
 * {@see \Tests\Unit\Jobs\SendWeeklyDigestEmailJobTest}.
 */
class SendMilestoneEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Atalho: link não-demo do usuário, com N cliques e sem degrau comemorado.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do link.
     */
    private function link(User $user, int $clicks = 100, array $attributes = []): Link
    {
        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_demo' => false,
            'clicks' => $clicks,
            'milestone_last_threshold' => 0,
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

        (new SendMilestoneEmailJob($link->id, 100))->handle($emailService);

        $this->assertSame(100, $link->fresh()->milestone_last_threshold);
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

        (new SendMilestoneEmailJob($link->id, 100))->handle($emailService);
    }

    /** O claim atômico impede reenvio do MESMO degrau num segundo run (retry / dispatch duplicado). */
    public function test_sends_at_most_once_across_runs(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 100);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendMilestoneEmailJob($link->id, 100))->handle($emailService);
        (new SendMilestoneEmailJob($link->id, 100))->handle($emailService);

        $this->assertSame(100, $link->fresh()->milestone_last_threshold);
    }

    /** Degrau maior que o já comemorado gera novo e-mail; igual ou menor, não. */
    public function test_higher_threshold_can_be_claimed_after_lower_one(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 60, ['milestone_last_threshold' => 25]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendMilestoneEmailJob($link->id, 50))->handle($emailService);

        $this->assertSame(50, $link->fresh()->milestone_last_threshold);
    }

    /** Degrau já comemorado (ou menor) sai quieto sem tocar o provedor. */
    public function test_skips_when_threshold_already_claimed(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 200, ['milestone_last_threshold' => 100]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob($link->id, 100))->handle($emailService);

        $this->assertSame(100, $link->fresh()->milestone_last_threshold);
    }

    /** Degrau 10 tem copy de estreia — "10 primeiros cliques". */
    public function test_first_milestone_uses_debut_copy(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 12);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(fn (string $to, string $subject, string $html) => str_contains($subject, '10 primeiros cliques')
                && str_contains($html, '10 primeiros cliques'))
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendMilestoneEmailJob($link->id, 10))->handle($emailService);
    }

    /** Degraus seguintes celebram "passou de N cliques" com o N do degrau. */
    public function test_later_milestones_mention_the_threshold(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 260);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(fn (string $to, string $subject, string $html) => str_contains($subject, 'passou de 250 cliques')
                && str_contains($html, 'passou de 250 cliques'))
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendMilestoneEmailJob($link->id, 250))->handle($emailService);
    }

    /** Link apagado entre dispatch e execução — no-op limpo. */
    public function test_missing_link_is_a_noop(): void
    {
        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob(999999, 100))->handle($emailService);

        $this->assertSame(0, Link::where('milestone_last_threshold', '>', 0)->count());
    }

    /** Cliques abaixo do degrau na hora da execução: nada sai e o claim fica livre. */
    public function test_skips_without_claim_when_link_is_below_threshold(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user, 8);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob($link->id, 10))->handle($emailService);

        $this->assertSame(0, $link->fresh()->milestone_last_threshold);
    }

    /** Opt-out entre o disparo e a execução é respeitado, sem queimar o claim. */
    public function test_skips_when_owner_opted_out(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);
        $link = $this->link($user, 100);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob($link->id, 100))->handle($emailService);

        $this->assertSame(0, $link->fresh()->milestone_last_threshold);
    }

    /** Link anônimo (sem dono) não tem destinatário. */
    public function test_skips_when_link_has_no_owner(): void
    {
        $link = Link::factory()->create([
            'user_id' => null,
            'is_demo' => false,
            'clicks' => 150,
            'milestone_last_threshold' => 0,
        ]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendMilestoneEmailJob($link->id, 100))->handle($emailService);

        $this->assertSame(0, $link->fresh()->milestone_last_threshold);
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

        SendMilestoneEmailJob::dispatch($link->id, 100);

        $this->assertNotNull($failedEvent, 'O job deveria se marcar como falho quando o provedor recusa o envio.');
        $this->assertSame(SendMilestoneEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('quota estourada', $failedEvent->exception->getMessage());

        // Claim feito antes do envio permanece — at-most-once deliberado.
        $this->assertSame(100, $link->fresh()->milestone_last_threshold);
    }
}
