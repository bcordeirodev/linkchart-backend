<?php

namespace Tests\Feature\Retention;

use App\Jobs\DispatchActivationNudgesJob;
use App\Jobs\SendActivationNudgeEmailJob;
use App\Models\Link;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Elegibilidade do nudge de ativação: usuários criados na janela de 1–2 dias
 * atrás, elegíveis a e-mails de retenção, ainda sem activation_nudge_sent_at e
 * SEM nenhum link não-demo.
 *
 * Relógio congelado em 2026-08-05 13:30 UTC (10:30 America/Sao_Paulo, o horário
 * do agendamento). Janela esperada: [2026-08-03 13:30Z, 2026-08-04 13:30Z).
 */
class DispatchActivationNudgesJobTest extends TestCase
{
    use RefreshDatabase;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-05 13:30:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 13:30:00', 'UTC'));

        Queue::fake([SendActivationNudgeEmailJob::class]);
    }

    /** {@inheritDoc} */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Atalho: usuário criado há $daysAgo dias (mais uma hora, para ficar
     * seguramente dentro do lado aberto da janela).
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do usuário.
     */
    private function user(float $daysAgo = 1, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'activation_nudge_sent_at' => null,
            'created_at' => Carbon::now()->subMinutes((int) round($daysAgo * 24 * 60))->addMinutes(-60),
        ], $attributes));
    }

    /** Cadastro de ontem, ainda sem nenhum link: entra na leva. */
    public function test_dispatches_for_user_created_yesterday_without_links(): void
    {
        $user = $this->user();

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertPushed(SendActivationNudgeEmailJob::class, 1);
        Queue::assertPushed(
            SendActivationNudgeEmailJob::class,
            fn (SendActivationNudgeEmailJob $job) => $job->userId === $user->id,
        );
    }

    /** Cadastro de hoje ainda não chegou na janela — o nudge é do dia 1–2. */
    public function test_skips_user_created_today(): void
    {
        $this->user(0);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertNotPushed(SendActivationNudgeEmailJob::class);
    }

    /** Cadastro de 3 dias já passou da janela — quem cuida dele é o e-mail de dicas. */
    public function test_skips_user_created_three_days_ago(): void
    {
        $this->user(3);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertNotPushed(SendActivationNudgeEmailJob::class);
    }

    /** Quem já criou um link se ativou — não precisa de nudge. */
    public function test_skips_user_who_already_created_a_link(): void
    {
        $user = $this->user();
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertNotPushed(SendActivationNudgeEmailJob::class);
    }

    /** O link demo semeado no cadastro NÃO conta como ativação. */
    public function test_demo_link_does_not_count_as_activation(): void
    {
        $user = $this->user();
        Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertPushed(SendActivationNudgeEmailJob::class, 1);
        Queue::assertPushed(
            SendActivationNudgeEmailJob::class,
            fn (SendActivationNudgeEmailJob $job) => $job->userId === $user->id,
        );
    }

    /** Já notificado (re-run do scheduler): não redispara. */
    public function test_skips_user_already_notified(): void
    {
        $this->user(1, ['activation_nudge_sent_at' => Carbon::now()->subHours(2)]);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertNotPushed(SendActivationNudgeEmailJob::class);
    }

    /** Usuário não verificado (sem auth0_sub) não recebe. */
    public function test_skips_unverified_user(): void
    {
        $this->user(1, ['email_verified' => false, 'email_verified_at' => null, 'auth0_sub' => null]);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertNotPushed(SendActivationNudgeEmailJob::class);
    }

    /** Opt-out vale para todo e-mail de retenção, incluindo o nudge. */
    public function test_skips_user_who_opted_out(): void
    {
        $this->user(1, ['weekly_digest_enabled' => false]);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertNotPushed(SendActivationNudgeEmailJob::class);
    }

    /** As contas demo do produto (40/41/45) ficam fora. */
    public function test_skips_demo_account(): void
    {
        $this->user(1, ['id' => 40]);

        (new DispatchActivationNudgesJob)->handle();

        Queue::assertNotPushed(SendActivationNudgeEmailJob::class);
    }
}
