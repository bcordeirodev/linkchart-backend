<?php

namespace Tests\Feature\Retention;

use App\Jobs\DispatchWinbackEmailsJob;
use App\Jobs\SendWinbackEmailJob;
use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Segmento do winback RE-SEGMENTADO (2026-08-16): usuário elegível, ausente há
 * 14+ dias (sem login E sem link novo), com >= 5 cliques em links não-demo nos
 * últimos 14 dias e fora do cooldown de 60 dias.
 *
 * Relógio congelado em 2026-08-05 13:20 UTC (10:20 America/Sao_Paulo, o horário
 * do agendamento). Fronteiras esperadas: ausência/cliques a partir de
 * 2026-07-22 13:20Z; cooldown a partir de 2026-06-06 13:20Z.
 *
 * O job é por USUÁRIO: o e-mail fala do que aconteceu com a conta inteira
 * enquanto o dono esteve fora.
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
     * Atalho: usuário ausente há 20 dias (login e cadastro bem antes da
     * fronteira), sem winback prévio.
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
     * Atalho: link do usuário criado bem antes da janela de ausência (criar
     * link é presença, então o link do cenário-base precisa ser velho).
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

    /** Segmento cheio: ausente, links rendendo acima do piso, sem cooldown. */
    public function test_dispatches_for_absent_user_whose_links_are_earning(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertPushed(SendWinbackEmailJob::class, 1);
        Queue::assertPushed(
            SendWinbackEmailJob::class,
            fn (SendWinbackEmailJob $job) => $job->userId === $user->id,
        );
    }

    /** Login recente é presença — não há de que fazer winback. */
    public function test_skips_user_who_logged_in_recently(): void
    {
        $user = $this->absentUser(['last_login_at' => Carbon::now()->subDays(2)]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Criar link também é presença, mesmo sem login registrado. */
    public function test_skips_user_who_created_a_link_inside_the_window(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 5);
        $this->oldLink($user, ['created_at' => Carbon::now()->subDays(3)]);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Piso de 5 cliques: com 4 não há história para contar. */
    public function test_skips_user_below_the_clicks_floor(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user), 4);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Cliques fora da janela de 14 dias não contam para o piso. */
    public function test_skips_when_the_clicks_are_older_than_the_window(): void
    {
        $user = $this->absentUser();
        $link = $this->oldLink($user);
        Click::factory()->count(10)->create([
            'link_id' => $link->id,
            'created_at' => Carbon::now()->subDays(30),
        ]);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Cliques em link demo nunca sustentam um e-mail de retenção. */
    public function test_skips_when_the_clicks_are_on_a_demo_link(): void
    {
        $user = $this->absentUser();
        $this->clicksInWindow($this->oldLink($user, ['is_demo' => true]), 10);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Cooldown ativo (winback há 10 dias): o e-mail não vira goteira. */
    public function test_skips_user_inside_the_cooldown(): void
    {
        $user = $this->absentUser(['winback_email_sent_at' => Carbon::now()->subDays(10)]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Cooldown vencido (90 dias): ausência é recorrente, o e-mail pode repetir. */
    public function test_dispatches_again_after_the_cooldown_expires(): void
    {
        $user = $this->absentUser(['winback_email_sent_at' => Carbon::now()->subDays(90)]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertPushed(SendWinbackEmailJob::class, 1);
    }

    /** last_login_at nulo cai no created_at: cadastro antigo = ausente. */
    public function test_coalesces_null_last_login_to_created_at(): void
    {
        $user = $this->absentUser(['last_login_at' => null]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertPushed(SendWinbackEmailJob::class, 1);
    }

    /**
     * O outro lado do coalesce: sem login registrado, um cadastro RECENTE conta
     * como presença. (O link antigo é artificial de propósito — isola o guard de
     * ausência dos demais.)
     */
    public function test_coalesce_keeps_recently_created_user_out(): void
    {
        $user = $this->absentUser([
            'last_login_at' => null,
            'created_at' => Carbon::now()->subDays(2),
        ]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Opt-out vale para todo e-mail de retenção. */
    public function test_skips_user_who_opted_out(): void
    {
        $user = $this->absentUser(['weekly_digest_enabled' => false]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Usuário não verificado (sem auth0_sub) não recebe. */
    public function test_skips_unverified_user(): void
    {
        $user = $this->absentUser([
            'email_verified' => false,
            'email_verified_at' => null,
            'auth0_sub' => null,
        ]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** As contas demo do produto (40/41/45) ficam fora. */
    public function test_skips_demo_account(): void
    {
        $user = $this->absentUser(['id' => 40]);
        $this->clicksInWindow($this->oldLink($user), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertNotPushed(SendWinbackEmailJob::class);
    }

    /** Donos diferentes recebem jobs separados, um por usuário. */
    public function test_dispatches_one_job_per_user(): void
    {
        $first = $this->absentUser();
        $this->clicksInWindow($this->oldLink($first), 5);
        $this->clicksInWindow($this->oldLink($first), 5);

        $second = $this->absentUser();
        $this->clicksInWindow($this->oldLink($second), 5);

        (new DispatchWinbackEmailsJob)->handle();

        Queue::assertPushed(SendWinbackEmailJob::class, 2);
    }
}
