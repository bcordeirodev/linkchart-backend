<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendWinbackEmailJob;
use App\Models\Link;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Comportamento do envio do winback (um e-mail por usuário, listando todos os
 * links órfãos da leva): conteúdo, claim at-most-once nos links do payload,
 * skips e falha do provedor ⇒ job failed sem retry.
 */
class SendWinbackEmailJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Atalho: link não-demo sem cliques e sem winback prévio.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do link.
     */
    private function link(User $user, array $attributes = []): Link
    {
        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_demo' => false,
            'clicks' => 0,
            'winback_email_sent_at' => null,
        ], $attributes));
    }

    /** Caminho feliz: os dois links aparecem, as 3 dicas também, e o claim é gravado. */
    public function test_sends_winback_listing_every_link_and_the_three_tips(): void
    {
        $user = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
        $first = $this->link($user, ['title' => 'Meu Portfólio']);
        $second = $this->link($user, ['title' => null, 'slug' => 'promo-verao']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(function (string $to, string $subject, string $html, ?string $text, ?string $name, string $type) {
                return $to === 'ana@example.com'
                    && str_contains($subject, 'ainda não teve cliques')
                    && str_contains($html, 'Ana Souza')
                    && str_contains($html, 'Meu Portfólio')
                    && str_contains($html, 'promo-verao')
                    && str_contains($html, 'WhatsApp')
                    && str_contains($html, 'Instagram')
                    && str_contains($html, 'comunidades')
                    && str_contains($html, '/links?utm_source=winback-email')
                    && str_contains($html, 'utm_medium=email')
                    && str_contains($html, '/email/digest/unsubscribe/')
                    && str_contains($html, 'signature=')
                    && is_string($text) && str_contains($text, 'promo-verao')
                    && $name === 'Ana Souza'
                    && $type === 'winback';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWinbackEmailJob($user->id, [$first->id, $second->id]))->handle($emailService);

        $this->assertNotNull($first->fresh()->winback_email_sent_at);
        $this->assertNotNull($second->fresh()->winback_email_sent_at);
    }

    /** Link já reivindicado por outra leva sai do e-mail; o restante segue. */
    public function test_only_freshly_claimed_links_reach_the_email(): void
    {
        $user = User::factory()->create();
        $alreadySent = $this->link($user, ['title' => 'Link Antigo', 'winback_email_sent_at' => now()->subMonth()]);
        $fresh = $this->link($user, ['title' => 'Link Novo']);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(fn (string $to, string $subject, string $html) => str_contains($html, 'Link Novo')
                && ! str_contains($html, 'Link Antigo'))
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWinbackEmailJob($user->id, [$alreadySent->id, $fresh->id]))->handle($emailService);
    }

    /** O claim atômico impede reenvio num segundo run (retry / dispatch duplicado). */
    public function test_sends_at_most_once_across_runs(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        (new SendWinbackEmailJob($user->id, [$link->id]))->handle($emailService);
        (new SendWinbackEmailJob($user->id, [$link->id]))->handle($emailService);

        $this->assertNotNull($link->fresh()->winback_email_sent_at);
    }

    /** Usuário apagado entre dispatch e execução — no-op limpo, sem queimar claim. */
    public function test_missing_user_is_a_noop(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user);
        $userId = $user->id;
        Link::whereKey($link->id)->update(['user_id' => null]);
        $user->delete();

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($userId, [$link->id]))->handle($emailService);

        $this->assertNull($link->fresh()->winback_email_sent_at);
    }

    /** Opt-out entre o disparo e a execução é respeitado. */
    public function test_skips_when_user_opted_out(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);
        $link = $this->link($user);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        (new SendWinbackEmailJob($user->id, [$link->id]))->handle($emailService);

        $this->assertNull($link->fresh()->winback_email_sent_at);
    }

    /**
     * Provedor recusa (success => false sem exceção): o job se marca como falho e
     * NÃO reenfileira — o claim já foi feito (at-most-once).
     */
    public function test_marks_job_as_failed_without_retry_when_provider_rejects(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user);

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => false, 'error' => 'quota estourada']);
        $this->app->instance(EmailService::class, $emailService);

        $failedEvent = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        SendWinbackEmailJob::dispatch($user->id, [$link->id]);

        $this->assertNotNull($failedEvent, 'O job deveria se marcar como falho quando o provedor recusa o envio.');
        $this->assertSame(SendWinbackEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('quota estourada', $failedEvent->exception->getMessage());

        // Claim feito antes do envio permanece — at-most-once deliberado.
        $this->assertNotNull($link->fresh()->winback_email_sent_at);
    }
}
