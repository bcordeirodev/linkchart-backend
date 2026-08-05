<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendWeeklyDigestEmailJob;
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
 * Comportamento do envio individual do digest: stats corretos no e-mail,
 * claim at-most-once, skips (usuário sumido / opt-out / sem cliques) e falha
 * do provedor ⇒ job failed sem retry — os mesmos contratos do
 * {@see \Tests\Unit\Jobs\SendWelcomeEmailJobTest}.
 *
 * Janela fixa: 2026-08-03T03:00Z → 2026-08-10T03:00Z (semana SP), relógio
 * congelado na segunda 2026-08-10 12:00 UTC (09:00 SP).
 */
class SendWeeklyDigestEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW_START = '2026-08-03T03:00:00+00:00';

    private const WINDOW_END = '2026-08-10T03:00:00+00:00';

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'UTC'));
    }

    /** {@inheritDoc} */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Atalho: n cliques num link, num instante UTC dado. */
    private function clicks(Link $link, int $count, string $instantUtc): void
    {
        Click::factory()->count($count)->create([
            'link_id' => $link->id,
            'created_at' => Carbon::parse($instantUtc, 'UTC'),
        ]);
    }

    private function newJob(int $userId): SendWeeklyDigestEmailJob
    {
        return new SendWeeklyDigestEmailJob($userId, self::WINDOW_START, self::WINDOW_END);
    }

    /**
     * Caminho feliz: total da janela, variação vs semana anterior, top link,
     * CTA com UTM e link assinado de unsubscribe — tudo no e-mail; claim gravado.
     */
    public function test_sends_digest_with_correct_stats(): void
    {
        $user = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@example.com']);
        $topLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false, 'title' => 'Meu Portfólio']);
        $otherLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);

        $this->clicks($topLink, 3, '2026-08-05 15:00:00');
        $this->clicks($otherLink, 1, '2026-08-06 10:00:00');
        // Semana anterior: 2 cliques → variação = (4-2)/2 = +100%.
        $this->clicks($topLink, 2, '2026-07-29 15:00:00');

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(function (string $to, string $subject, string $html, ?string $text, ?string $name) {
                return $to === 'ana@example.com'
                    && str_contains($subject, '4 cliques')
                    && str_contains($html, 'Ana Souza')
                    && str_contains($html, 'Meu Portfólio')
                    && str_contains($html, '+100%')
                    && str_contains($html, 'utm_source=weekly-digest')
                    && str_contains($html, '/email/digest/unsubscribe/')
                    && str_contains($html, 'signature=')
                    && is_string($text) && str_contains($text, '4 cliques')
                    && $name === 'Ana Souza';
            })
            ->andReturn(['success' => true, 'message' => 'ok']);

        $this->newJob($user->id)->handle($emailService);

        $this->assertNotNull($user->fresh()->weekly_digest_sent_at);
    }

    /** Sem cliques na semana anterior, o e-mail fala em primeira semana — não em %. */
    public function test_reports_first_week_when_previous_week_empty(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $this->clicks($link, 2, '2026-08-05 15:00:00');

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(fn (string $to, string $subject, string $html) => str_contains($html, 'Primeira semana com cliques'))
            ->andReturn(['success' => true, 'message' => 'ok']);

        $this->newJob($user->id)->handle($emailService);
    }

    /** Cliques demo não entram na conta ([[NUNCA contar cliques demo]]). */
    public function test_ignores_demo_clicks_in_stats(): void
    {
        $user = User::factory()->create();
        $real = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $demo = Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);
        $this->clicks($real, 1, '2026-08-05 15:00:00');
        $this->clicks($demo, 50, '2026-08-05 16:00:00');

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->withArgs(fn (string $to, string $subject) => str_contains($subject, '1 clique') && ! str_contains($subject, '51'))
            ->andReturn(['success' => true, 'message' => 'ok']);

        $this->newJob($user->id)->handle($emailService);
    }

    /** O claim atômico impede reenvio num segundo run (retry / dispatch duplicado). */
    public function test_sends_at_most_once_across_runs(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $this->clicks($link, 1, '2026-08-05 15:00:00');

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => true, 'message' => 'ok']);

        $this->newJob($user->id)->handle($emailService);
        $this->newJob($user->id)->handle($emailService);

        $this->assertNotNull($user->fresh()->weekly_digest_sent_at);
    }

    /** Opt-out entre o disparo e a execução é respeitado. */
    public function test_skips_user_with_digest_disabled(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $this->clicks($link, 1, '2026-08-05 15:00:00');

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        $this->newJob($user->id)->handle($emailService);

        $this->assertNull($user->fresh()->weekly_digest_sent_at);
    }

    /** Usuário apagado entre dispatch e execução — no-op limpo. */
    public function test_missing_user_is_a_noop(): void
    {
        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        $this->newJob(999999)->handle($emailService);

        $this->assertSame(0, User::whereNotNull('weekly_digest_sent_at')->count());
    }

    /** Sem cliques na janela: nada sai e o claim NÃO é queimado. */
    public function test_skips_without_claim_when_no_clicks_in_window(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        // Só cliques fora da janela.
        $this->clicks($link, 3, '2026-07-20 15:00:00');

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldNotReceive('sendTransactionalEmail');

        $this->newJob($user->id)->handle($emailService);

        $this->assertNull($user->fresh()->weekly_digest_sent_at);
    }

    /**
     * Provedor recusa (success => false sem exceção): o job se marca como falho e
     * NÃO reenfileira — o claim já foi feito (at-most-once). Mesmo racional e mesma
     * mecânica de teste do welcome: dispatch() real via fila sync para observar
     * o evento JobFailed emitido por Job::fail().
     */
    public function test_marks_job_as_failed_without_retry_when_provider_rejects(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $this->clicks($link, 1, '2026-08-05 15:00:00');

        $emailService = Mockery::mock(EmailService::class);
        $emailService->shouldReceive('sendTransactionalEmail')
            ->once()
            ->andReturn(['success' => false, 'error' => 'quota estourada']);
        $this->app->instance(EmailService::class, $emailService);

        $failedEvent = null;
        Event::listen(JobFailed::class, function (JobFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        SendWeeklyDigestEmailJob::dispatch($user->id, self::WINDOW_START, self::WINDOW_END);

        $this->assertNotNull($failedEvent, 'O job deveria se marcar como falho quando o provedor recusa o envio.');
        $this->assertSame(SendWeeklyDigestEmailJob::class, $failedEvent->job->resolveName());
        $this->assertStringContainsString('quota estourada', $failedEvent->exception->getMessage());

        // Claim feito antes do envio permanece — trade-off at-most-once deliberado:
        // este digest se perde, o da próxima semana chega normalmente.
        $this->assertNotNull($user->fresh()->weekly_digest_sent_at);
    }
}
