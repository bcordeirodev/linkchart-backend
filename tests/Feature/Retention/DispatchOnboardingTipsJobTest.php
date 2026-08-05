<?php

namespace Tests\Feature\Retention;

use App\Jobs\DispatchOnboardingTipsJob;
use App\Jobs\SendOnboardingTipsEmailJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Elegibilidade das dicas de onboarding: usuários criados na janela de 3–4 dias
 * atrás, elegíveis a e-mails de retenção e ainda sem onboarding_tips_sent_at.
 *
 * Relógio congelado em 2026-08-05 13:40 UTC (10:40 America/Sao_Paulo, o horário
 * do agendamento). Janela esperada: [2026-08-01 13:40Z, 2026-08-02 13:40Z).
 */
class DispatchOnboardingTipsJobTest extends TestCase
{
    use RefreshDatabase;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-05 13:40:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 13:40:00', 'UTC'));

        Queue::fake([SendOnboardingTipsEmailJob::class]);
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
    private function user(float $daysAgo = 3, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'onboarding_tips_sent_at' => null,
            'created_at' => Carbon::now()->subMinutes((int) round($daysAgo * 24 * 60))->addMinutes(-60),
        ], $attributes));
    }

    /** Cadastro de 3 dias atrás entra na leva. */
    public function test_dispatches_for_user_created_three_days_ago(): void
    {
        $user = $this->user();

        (new DispatchOnboardingTipsJob)->handle();

        Queue::assertPushed(SendOnboardingTipsEmailJob::class, 1);
        Queue::assertPushed(
            SendOnboardingTipsEmailJob::class,
            fn (SendOnboardingTipsEmailJob $job) => $job->userId === $user->id,
        );
    }

    /** Cadastro de ontem/anteontem ainda não chegou na janela. */
    public function test_skips_user_created_two_days_ago(): void
    {
        $this->user(2);

        (new DispatchOnboardingTipsJob)->handle();

        Queue::assertNotPushed(SendOnboardingTipsEmailJob::class);
    }

    /** Cadastro antigo demais (5 dias) já passou da janela. */
    public function test_skips_user_created_five_days_ago(): void
    {
        $this->user(5);

        (new DispatchOnboardingTipsJob)->handle();

        Queue::assertNotPushed(SendOnboardingTipsEmailJob::class);
    }

    /** Já enviado (re-run do scheduler): não redispara. */
    public function test_skips_user_already_sent(): void
    {
        $this->user(3, ['onboarding_tips_sent_at' => Carbon::now()->subDay()]);

        (new DispatchOnboardingTipsJob)->handle();

        Queue::assertNotPushed(SendOnboardingTipsEmailJob::class);
    }

    /** Usuário não verificado (sem auth0_sub) não recebe. */
    public function test_skips_unverified_user(): void
    {
        $this->user(3, ['email_verified' => false, 'email_verified_at' => null, 'auth0_sub' => null]);

        (new DispatchOnboardingTipsJob)->handle();

        Queue::assertNotPushed(SendOnboardingTipsEmailJob::class);
    }

    /** Opt-out vale para todo e-mail de retenção, incluindo as dicas. */
    public function test_skips_user_who_opted_out(): void
    {
        $this->user(3, ['weekly_digest_enabled' => false]);

        (new DispatchOnboardingTipsJob)->handle();

        Queue::assertNotPushed(SendOnboardingTipsEmailJob::class);
    }

    /** As contas demo do produto (40/41/45) ficam fora. */
    public function test_skips_demo_account(): void
    {
        $this->user(3, ['id' => 40]);

        (new DispatchOnboardingTipsJob)->handle();

        Queue::assertNotPushed(SendOnboardingTipsEmailJob::class);
    }
}
