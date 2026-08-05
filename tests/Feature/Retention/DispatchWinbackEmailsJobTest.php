<?php

namespace Tests\Feature\Retention;

use App\Jobs\DispatchWinbackEmailsJob;
use App\Jobs\SendWinbackEmailJob;
use App\Models\Link;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Elegibilidade do winback: links não-demo, com ZERO cliques, criados na janela
 * de 14–15 dias atrás, ainda sem winback_email_sent_at e de dono elegível.
 *
 * Relógio congelado em 2026-08-05 13:20 UTC (10:20 America/Sao_Paulo, o horário
 * do agendamento). Janela esperada: [2026-07-21 13:20Z, 2026-07-22 13:20Z).
 *
 * O job é por USUÁRIO (não por link): quem tem dois links órfãos recebe um
 * e-mail listando os dois.
 */
class DispatchWinbackEmailsJobTest extends TestCase
{
    use RefreshDatabase;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-05 13:20:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 13:20:00', 'UTC'));

        Queue::fake([SendWinbackEmailJob::class]);
    }

    /** {@inheritDoc} */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Atalho: link do usuário criado há $daysAgo dias (mais uma hora, para
     * ficar seguramente dentro do lado aberto da janela), sem cliques.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do link.
     */
    private function link(User $user, float $daysAgo = 14, array $attributes = []): Link
    {
        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_demo' => false,
            'clicks' => 0,
            'winback_email_sent_at' => null,
            'created_at' => Carbon::now()->subMinutes((int) round($daysAgo * 24 * 60))->addMinutes(-60),
        ], $attributes));
    }

    /** Link de 14 dias sem nenhum clique: enfileira o winback com o id do link. */
    public function test_dispatches_for_link_without_clicks_at_day_14(): void
    {
        $user = User::factory()->create();
        $link = $this->link($user);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertPushed(SendWinbackEmailJob::class, 1);
        Queue::assertPushed(SendWinbackEmailJob::class, function (SendWinbackEmailJob $job) use ($user, $link) {
            return $job->userId === $user->id && $job->linkIds === [$link->id];
        });
    }

    /** Um clique já basta para o link sair do winback. */
    public function test_skips_link_with_clicks(): void
    {
        $user = User::factory()->create();
        $this->link($user, 14, ['clicks' => 1]);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Link novo demais (10 dias) ainda não entrou na janela. */
    public function test_skips_link_created_ten_days_ago(): void
    {
        $user = User::factory()->create();
        $this->link($user, 10);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Link velho demais (16 dias) já passou da janela — o disparo é diário. */
    public function test_skips_link_created_sixteen_days_ago(): void
    {
        $user = User::factory()->create();
        $this->link($user, 16);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Link demo nunca entra em e-mail de retenção. */
    public function test_skips_demo_link(): void
    {
        $user = User::factory()->create();
        $this->link($user, 14, ['is_demo' => true]);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Opt-out do dono vale para todo e-mail de retenção. */
    public function test_skips_link_of_user_who_opted_out(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);
        $this->link($user);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Já enviado (re-run do scheduler): não redispara. */
    public function test_skips_link_already_sent(): void
    {
        $user = User::factory()->create();
        $this->link($user, 14, ['winback_email_sent_at' => Carbon::now()->subDay()]);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Dois links órfãos do mesmo dono viram UM job com os dois ids. */
    public function test_groups_links_of_the_same_user_into_one_job(): void
    {
        $user = User::factory()->create();
        $first = $this->link($user);
        $second = $this->link($user);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertPushed(SendWinbackEmailJob::class, 1);
        Queue::assertPushed(SendWinbackEmailJob::class, function (SendWinbackEmailJob $job) use ($user, $first, $second) {
            return $job->userId === $user->id
                && $job->linkIds === [$first->id, $second->id];
        });
    }

    /** Donos diferentes recebem jobs separados. */
    public function test_dispatches_one_job_per_user(): void
    {
        $this->link(User::factory()->create());
        $this->link(User::factory()->create());

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertPushed(SendWinbackEmailJob::class, 2);
    }
}
