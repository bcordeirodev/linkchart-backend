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
 * Elegibilidade do marco de 100 cliques: quem recebe (link não-demo com 100+
 * cliques, ainda não notificado, de dono elegível) e quem NÃO recebe (abaixo do
 * limiar, demo, já notificado, dono opt-out / conta demo / não verificado, e
 * link anônimo sem dono).
 *
 * O job por LINK é enfileirado — um usuário com dois links no marco recebe dois
 * e-mails, um por conquista.
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
     * Atalho: link do usuário com N cliques, não-demo e ainda não notificado.
     *
     * @param  array<string, mixed>  $attributes  Sobrescritas do link.
     */
    private function linkWithClicks(User $user, int $clicks, array $attributes = []): Link
    {
        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_demo' => false,
            'clicks' => $clicks,
            'milestone_100_notified_at' => null,
        ], $attributes));
    }

    /** Link no marco, dono elegível: enfileira um job carregando o id do link. */
    public function test_dispatches_for_link_that_reached_the_milestone(): void
    {
        $user = User::factory()->create();
        $link = $this->linkWithClicks($user, 100);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertPushed(SendMilestoneEmailJob::class, 1);
        Queue::assertPushed(
            SendMilestoneEmailJob::class,
            fn (SendMilestoneEmailJob $job) => $job->linkId === $link->id,
        );
    }

    /** 99 cliques ainda não é marco — o limiar é fechado em 100. */
    public function test_skips_link_below_threshold(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 99);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Link demo nunca gera marco ([[NUNCA contar cliques demo]]). */
    public function test_skips_demo_link(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 500, ['is_demo' => true]);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Já notificado: o marco é comemorado uma única vez por link. */
    public function test_skips_already_notified_link(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 300, ['milestone_100_notified_at' => now()->subDay()]);

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
            'milestone_100_notified_at' => null,
        ]);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertNotPushed(SendMilestoneEmailJob::class);
    }

    /** Dois links no marco do mesmo dono: um job por link (uma conquista cada). */
    public function test_dispatches_one_job_per_link(): void
    {
        $user = User::factory()->create();
        $this->linkWithClicks($user, 100);
        $this->linkWithClicks($user, 4200);

        (new DispatchMilestoneEmailsJob)->handle();

        Queue::assertPushed(SendMilestoneEmailJob::class, 2);
    }
}
