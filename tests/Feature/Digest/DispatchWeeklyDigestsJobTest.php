<?php

namespace Tests\Feature\Digest;

use App\Jobs\DispatchWeeklyDigestsJob;
use App\Jobs\SendWeeklyDigestEmailJob;
use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Elegibilidade do disparo semanal: quem recebe (clique real na janela) e quem
 * NÃO recebe (demo, fora da janela, opt-out, não verificado, contas demo
 * 40/41/45, claim já feito nesta semana).
 *
 * Relógio congelado numa segunda-feira 09:00 America/Sao_Paulo (12:00 UTC).
 * Janela esperada: segunda anterior 00:00 SP → esta segunda 00:00 SP, ou seja
 * 2026-08-03T03:00Z → 2026-08-10T03:00Z.
 */
class DispatchWeeklyDigestsJobTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW_START_UTC = '2026-08-03T03:00:00+00:00';

    private const WINDOW_END_UTC = '2026-08-10T03:00:00+00:00';

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));

        Queue::fake([SendWeeklyDigestEmailJob::class]);
    }

    /** {@inheritDoc} */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Cria um usuário (verificado por default na factory) com um link não-demo
     * e $clicks cliques dentro da janela da semana passada.
     */
    private function userWithClicks(int $clicks = 1, array $userAttributes = []): User
    {
        $user = User::factory()->create($userAttributes);
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);

        Click::factory()->count($clicks)->create([
            'link_id' => $link->id,
            'created_at' => Carbon::parse('2026-08-05 15:00:00', 'UTC'),
        ]);

        return $user;
    }

    /** Usuário com clique real na janela recebe o job, com a janela correta no payload. */
    public function test_dispatches_for_user_with_clicks_in_window(): void
    {
        $user = $this->userWithClicks();

        (new DispatchWeeklyDigestsJob)->handle();

        Queue::assertPushed(SendWeeklyDigestEmailJob::class, 1);
        Queue::assertPushed(SendWeeklyDigestEmailJob::class, function (SendWeeklyDigestEmailJob $job) use ($user) {
            return $job->userId === $user->id
                && $job->windowStartIso === self::WINDOW_START_UTC
                && $job->windowEndIso === self::WINDOW_END_UTC;
        });
    }

    /** Cliques só em link demo não contam ([[NUNCA contar cliques demo]]). */
    public function test_ignores_clicks_from_demo_links(): void
    {
        $user = User::factory()->create();
        $demoLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);
        Click::factory()->create([
            'link_id' => $demoLink->id,
            'created_at' => Carbon::parse('2026-08-05 15:00:00', 'UTC'),
        ]);

        (new DispatchWeeklyDigestsJob)->handle();

        Queue::assertNotPushed(SendWeeklyDigestEmailJob::class);
    }

    /** Cliques antes do início ou depois do fim da janela não elegem. */
    public function test_ignores_clicks_outside_window(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);

        // 1 minuto antes do início da janela.
        Click::factory()->create([
            'link_id' => $link->id,
            'created_at' => Carbon::parse('2026-08-03 02:59:00', 'UTC'),
        ]);
        // Depois do fim da janela (hoje de manhã, antes do disparo das 09:00 SP).
        Click::factory()->create([
            'link_id' => $link->id,
            'created_at' => Carbon::parse('2026-08-10 11:00:00', 'UTC'),
        ]);

        (new DispatchWeeklyDigestsJob)->handle();

        Queue::assertNotPushed(SendWeeklyDigestEmailJob::class);
    }

    /** Opt-out (weekly_digest_enabled = false) é respeitado no disparo. */
    public function test_skips_user_with_digest_disabled(): void
    {
        $this->userWithClicks(1, ['weekly_digest_enabled' => false]);

        (new DispatchWeeklyDigestsJob)->handle();

        Queue::assertNotPushed(SendWeeklyDigestEmailJob::class);
    }

    /** Usuário não verificado (sem auth0_sub) não recebe. */
    public function test_skips_unverified_user(): void
    {
        $user = User::factory()->unverified()->create(['auth0_sub' => null]);
        $link = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        Click::factory()->create([
            'link_id' => $link->id,
            'created_at' => Carbon::parse('2026-08-05 15:00:00', 'UTC'),
        ]);

        (new DispatchWeeklyDigestsJob)->handle();

        Queue::assertNotPushed(SendWeeklyDigestEmailJob::class);
    }

    /** As contas demo (ids 40/41/45) nunca entram, mesmo com cliques reais. */
    public function test_skips_demo_account_ids(): void
    {
        $this->userWithClicks(1, ['id' => 40]);

        (new DispatchWeeklyDigestsJob)->handle();

        Queue::assertNotPushed(SendWeeklyDigestEmailJob::class);
    }

    /** Claim já feito nesta semana (re-run do scheduler) não redispara. */
    public function test_skips_user_already_claimed_this_week(): void
    {
        $this->userWithClicks(1, [
            'weekly_digest_sent_at' => Carbon::parse('2026-08-10 12:00:05', 'UTC'),
        ]);

        (new DispatchWeeklyDigestsJob)->handle();

        Queue::assertNotPushed(SendWeeklyDigestEmailJob::class);
    }
}
