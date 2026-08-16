<?php

namespace Tests\Feature\Retention;

use App\Jobs\DispatchMilestoneEmailsJob;
use App\Jobs\SendMilestoneEmailJob;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Escada de marcos: qual degrau é comemorado (o MAIOR já cruzado e ainda não
 * comemorado), quem recebe (link não-demo de dono elegível) e quem NÃO recebe
 * (abaixo do primeiro degrau, entre degraus, demo, degrau já comemorado, dono
 * opt-out / conta demo / não verificado, e link anônimo sem dono).
 *
 * O job por LINK continua sendo o enfileirado, mas com teto de UM e-mail de
 * marco por usuário por varredura — o link preterido fica para a próxima.
 */
class DispatchMilestoneEmailsJobTest extends TestCase
{
    use RefreshDatabase;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendMilestoneEmailJob::class]);
    }

    /**
     * Atalho: link do usuário com N cliques, não-demo e sem degrau comemorado.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do link.
     */
    private function linkWithClicks(User $user, int $clicks, array $attributes = []): Link
    {
        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_demo' => false,
            'clicks' => $clicks,
            'milestone_last_threshold' => 0,
        ], $attributes));
    }

    /** Link no marco, dono elegível: enfileira um job com o link e o degrau. */
    public function test_dispatches_for_link_that_reached_the_milestone(): void
    {
        $user = User::factory()->create();
        $link = $this->linkWithClicks($user, 100);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertPushed(SendMilestoneEmailJob::class, 1);
        Queue::assertPushed(
            SendMilestoneEmailJob::class,
            fn (SendMilestoneEmailJob $job) => $job->linkId === $link->id && $job->threshold === 100,
        );
    }

    /** 9 cliques ainda não é marco — o primeiro degrau da escada é 10. */
    public function test_skips_link_below_threshold(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 9);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** O degrau comemorado é o MAIOR cruzado — salto de 0 a 60 rende UM e-mail, o de 50. */
    public function test_link_that_jumped_levels_gets_only_the_highest(): void
    {
        $user = User::factory()->create();
        $link = $this->linkWithClicks($user, 60);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertPushed(SendMilestoneEmailJob::class, 1);
        Queue::assertPushed(
            SendMilestoneEmailJob::class,
            fn (SendMilestoneEmailJob $job) => $job->linkId === $link->id && $job->threshold === 50,
        );
    }

    /** 12 cliques com degrau 10 já comemorado: nenhum degrau novo cruzado. */
    public function test_skips_link_between_levels(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 12, ['milestone_last_threshold' => 10]);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Teto diário: dois links no degrau no mesmo dia ⇒ só o de maior degrau sai. */
    public function test_caps_at_one_email_per_user_per_run(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 15);
        $bigger = $this->linkWithClicks($user, 70);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertPushed(SendMilestoneEmailJob::class, 1);
        Queue::assertPushed(
            SendMilestoneEmailJob::class,
            fn (SendMilestoneEmailJob $job) => $job->linkId === $bigger->id && $job->threshold === 50,
        );
    }

    /** Empate de degrau desempata por cliques; o preterido fica para a próxima varredura. */
    public function test_tie_on_threshold_prefers_more_clicks(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 26);
        $winner = $this->linkWithClicks($user, 40);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertPushed(SendMilestoneEmailJob::class, 1);
        Queue::assertPushed(
            SendMilestoneEmailJob::class,
            fn (SendMilestoneEmailJob $job) => $job->linkId === $winner->id && $job->threshold === 25,
        );
    }

    /** Usuários diferentes não competem entre si pelo teto. */
    public function test_cap_is_per_user_not_global(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->linkWithClicks($userA, 30);
        $this->linkWithClicks($userB, 12);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertPushed(SendMilestoneEmailJob::class, 2);
    }

    /** Link demo nunca gera marco ([[NUNCA contar cliques demo]]). */
    public function test_skips_demo_link(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 500, ['is_demo' => true]);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Degrau já comemorado: cada marco sai uma única vez por link. */
    public function test_skips_already_notified_link(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 300, ['milestone_last_threshold' => 250]);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Opt-out do dono vale para TODO e-mail de retenção, não só o digest. */
    public function test_skips_link_of_user_who_opted_out(): void
    {
        $user = User::factory()->create(['weekly_digest_enabled' => false]);
        $this->linkWithClicks($user, 120);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** As contas demo do produto (40/41/45) ficam fora mesmo com links reais. */
    public function test_skips_link_of_demo_account(): void
    {
        $user = User::factory()->create(['id' => 40]);
        $this->linkWithClicks($user, 120);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Dono não verificado (sem auth0_sub) não recebe nada. */
    public function test_skips_link_of_unverified_user(): void
    {
        $user = User::factory()->unverified()->create(['auth0_sub' => null]);
        $this->linkWithClicks($user, 120);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Link público anônimo (user_id null) não tem para quem enviar. */
    public function test_skips_link_without_owner(): void
    {
        Link::factory()->create([
            'user_id' => null,
            'is_demo' => false,
            'clicks' => 150,
            'milestone_last_threshold' => 0,
        ]);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }
}
