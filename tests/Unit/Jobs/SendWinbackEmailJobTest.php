<?php

namespace Tests\Unit\Jobs;

use App\Jobs\DispatchWinbackEmailsJob;
use App\Jobs\SendWinbackEmailJob;
use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Comportamento do envio do winback re-segmentado (um e-mail por USUÁRIO
 * ausente, relatando os cliques que os links renderam na ausência): reavaliação
 * dos guards do dispatcher, claim com cooldown, conteúdo e falha do provedor
 * ⇒ job failed sem retry.
 */
class SendWinbackEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Atalho: usuário ausente há 20 dias, sem winback prévio.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do usuário.
     */
    private function absentUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'created_at' => Carbon::now()->subDays(90),
            'last_login_at' => Carbon::now()->subDays(20),
            'winback_email_sent_at' => null,
        ], $attributes));
    }

    /**
     * Atalho: link não-demo criado bem antes da janela de ausência.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do link.
     */
    private function oldLink(User $user, array $attributes = []): Link
    {
        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_demo' => false,
            'created_at' => Carbon::now()->subDays(40),
        ], $attributes));
    }

    /** Atalho: N cliques no link, todos dentro da janela de 14 dias. */
    private function clicksInWindow(Link $link, int $count): void
    {
        Click::factory()->count($count)->create([
            'link_id' => $link->id,
            'created_at' => Carbon::now()->subDays(3),
        ]);
    }

    /**
     * Caminho feliz: número da janela no assunto e no corpo, card do top link,
     * os dois CTAs com UTM, unsubscribe assinado — e o claim gravado.
     */
    public function test_sends_winback_with_window_clicks_and_top_link(): void
    {
        $user = $this->absentUser(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
        $top = $this->oldLink($user, ['title' => 'Meu Portfólio']);
        $other = $this->oldLink($user, ['title' => null, 'slug' => 'promo-verao']);

        $this->clicksInWindow($top, 7);
        $this->clicksInWindow($other, 2);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(function (string $to, string $subject, string $html, ?string $text, ?string $name, string $type) use ($top) {
                return $to === 'ana@example.com'
                    && $subject === 'Seus links receberam 9 cliques enquanto você esteve fora'
                    && str_contains($html, 'Ana Souza')
                    && str_contains($html, '>9<')
                    && str_contains($html, 'Meu Portfólio')
                    && str_contains($html, '7 cliques no período')
                    && str_contains($html, '/links/analytics/'.$top->id.'?utm_source=winback-email')
                    && str_contains($html, 'utm_medium=email')
                    && str_contains($html, 'Ver estatísticas')
                    && str_contains($html, 'Ver todos os links')
                    && str_contains($html, '/links?utm_source=winback-email')
                    && str_contains($html, '/email/digest/unsubscribe/')
                    && str_contains($html, 'signature=')
                    && is_string($text)
                    && str_contains($text, '9 cliques nos últimos 14 dias')
                    && str_contains($text, 'Meu Portfólio')
                    // Corpo texto NÃO pode escapar o & do UTM (viraria amp;utm_medium).
                    && str_contains($text, '?utm_source=winback-email&utm_medium=email')
                    && ! str_contains($text, '&amp;')
                    && $name === 'Ana Souza'
                    && $type === 'winback';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWinbackEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->winback_email_sent_at);
    }

    /** Usuário apagado entre dispatch e execução — no-op limpo. */
    public function test_missing_user_is_a_noop(): void
    {
        $user = $this->absentUser();
        $userId = $user->id;
        $user->delete();

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($userId))->handle($emailService);
    }

    /** Opt-out entre o disparo e a execução é respeitado. */
    public function test_skips_when_user_opted_out(): void
    {
        $user = $this->absentUser(['weekly_digest_enabled' => false]);
        $this->clicksInWindow($this->oldLink($user), 5);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->winback_email_sent_at);
    }

    /** Logou entre o disparo e a execução: voltou sozinho, o claim fica livre. */
    public function test_skips_when_user_logged_in_between_dispatch_and_send(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 5);
        // Via query builder: last_login_at fica fora do $fillable de propósito
        // (só o fluxo de login escreve nela), então update() no model é no-op.
        User::whereKey($user->id)->update(['last_login_at' => Carbon::now()->subHour()]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->winback_email_sent_at);
    }

    /** Criou link entre o disparo e a execução: também é presença. */
    public function test_skips_when_user_created_a_link_between_dispatch_and_send(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 5);
        $this->oldLink($user, ['created_at' => Carbon::now()->subHour()]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->winback_email_sent_at);
    }

    /** Cliques abaixo do piso na hora do envio: sai sem queimar o claim. */
    public function test_skips_when_clicks_fell_below_the_floor(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 3);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($user->id))->handle($emailService);

        $this->assertNull($user->fresh()->winback_email_sent_at);
    }

    /** O claim atômico impede reenvio dentro do cooldown (retry / dispatch duplicado). */
    public function test_sends_at_most_once_inside_the_cooldown(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 5);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWinbackEmailJob($user->id))->handle($emailService);
        (new SendWinbackEmailJob($user->id))->handle($emailService);

        $this->assertNotNull($user->fresh()->winback_email_sent_at);
    }

    /** Winback antigo (90 dias) não bloqueia: o claim condicional re-reivindica. */
    public function test_reclaims_after_the_cooldown_expires(): void
    {
        $user = $this->absentUser(['winback_email_sent_at' => Carbon::now()->subDays(90)]);
        $this->clicksInWindow($this->oldLink($user), 5);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWinbackEmailJob($user->id))->handle($emailService);

        $this->assertTrue(
            $user->fresh()->winback_email_sent_at->greaterThan(
                Carbon::now()->subDays(DispatchWinbackEmailsJob::COOLDOWN_DAYS)
            ),
            'O claim deveria ter sido renovado com o instante do novo envio.',
        );
    }

    /**
     * Corrida: outra execução reivindicou o claim primeiro (0 linhas afetadas)
     * ⇒ este job sai quieto, sem mandar um segundo e-mail.
     */
    public function test_losing_the_claim_race_skips_the_send(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 5);

        // Simula o vencedor da corrida carimbando o claim entre o guard e o UPDATE.
        User::whereKey($user->id)->update(['winback_email_sent_at' => Carbon::now()]);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($user->id))->handle($emailService);
    }

    /**
     * Provedor recusa (success => false sem exceção): o job se marca como falho e
     * NÃO reenfileira — o claim já foi feito (at-most-once dentro do cooldown).
     */
    public function test_marks_job_as_failed_without_retry_when_provider_rejects(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 5);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => false, 'error' => 'quota estourada']);
        $this->app->instance(EmailService::class, $emailService);

        $failedEvent = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        SendWinbackEmailJob::dispatch($user->id);

        $this->assertNotNull($failedEvent, 'O job deveria se marcar como falho quando o provedor recusa o envio.');
        $this->assertSame(SendWinbackEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('quota estourada', $failedEvent->exception->getMessage());

        // Claim feito antes do envio permanece — at-most-once deliberado.
        $this->assertNotNull($user->fresh()->winback_email_sent_at);
    }
}
