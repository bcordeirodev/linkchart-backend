<?php

namespace Tests\Feature\Admin;

use App\Models\Link;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * getEngagement: ativação (% com ≥1 link não-demo), retorno na 1ª semana
 * (proxy por criação de link), distribuição de links e WAU/MAU por
 * last_login_at.
 */
class AdminEngagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $jwt;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->admin->forceFill(['is_admin' => true])->saveQuietly();
        $this->jwt = auth()->guard('api')->login($this->admin);
    }

    /** @return array{Authorization: string} */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->jwt}"];
    }

    public function test_activation_counts_users_with_at_least_one_non_demo_link(): void
    {
        $active = User::factory()->create();
        User::factory()->create(); // sem link
        Link::factory()->create(['user_id' => $active->id, 'is_demo' => false]);

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())
            ->assertOk()->json('data');

        // 3 usuários (admin incluso), 1 com link → 33.3
        $this->assertSame(33.3, $json['activation_pct']);
    }

    public function test_links_distribution_buckets(): void
    {
        $one = User::factory()->create();
        $many = User::factory()->create();
        Link::factory()->create(['user_id' => $one->id, 'is_demo' => false]);
        Link::factory()->count(7)->create(['user_id' => $many->id, 'is_demo' => false]);

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())->json('data');
        $buckets = collect($json['links_distribution'])->pluck('users', 'bucket');

        $this->assertSame(1, $buckets['0']);   // admin
        $this->assertSame(1, $buckets['1']);
        $this->assertSame(0, $buckets['2-5']);
        $this->assertSame(1, $buckets['6+']);
    }

    /**
     * Links anônimos contam nos totais globais (ver AdminOverviewTest), mas
     * NÃO podem virar um bucket fantasma "usuário null" aqui — engagement é
     * por usuário. Contrapartida do fix do scopeNonDemo.
     */
    public function test_anonymous_links_do_not_create_a_user_bucket(): void
    {
        $active = User::factory()->create();
        Link::factory()->create(['user_id' => $active->id, 'is_demo' => false]);
        Link::factory()->count(3)->create(['user_id' => null, 'is_demo' => false]);

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())
            ->assertOk()->json('data');
        $buckets = collect($json['links_distribution'])->pluck('users', 'bucket');

        // 2 usuários (admin + active), 1 com link → 50.0; sem os anônimos
        // inflando o numerador.
        $this->assertEquals(50.0, $json['activation_pct']);
        $this->assertSame(1, $buckets['0']);   // admin
        $this->assertSame(1, $buckets['1']);   // active
        $this->assertSame(0, $buckets['2-5']); // os 3 anônimos NÃO viram bucket
        $this->assertSame(0, $buckets['6+']);
    }

    public function test_wau_mau_from_last_login_at(): void
    {
        $recent = User::factory()->create();
        $recent->forceFill(['last_login_at' => now()->subDays(2)])->saveQuietly();
        $stale = User::factory()->create();
        $stale->forceFill(['last_login_at' => now()->subDays(20)])->saveQuietly();
        User::factory()->create(); // nunca logou

        // Conta demo com login bem mais antigo que qualquer usuário real —
        // não pode vazar para o disclaimer login_tracking_since.
        $demoAccount = User::factory()->create(['id' => User::DEMO_ACCOUNT_IDS[0]]);
        $demoAccount->forceFill(['last_login_at' => now()->subDays(365)])->saveQuietly();

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())->json('data');

        $this->assertSame(1, $json['wau']);
        $this->assertSame(2, $json['mau']);
        $this->assertNotNull($json['login_tracking_since']);
        // ISO-8601 com offset explícito: min() do query builder devolveria a
        // string crua 'Y-m-d H:i:s' (sem fuso), que o cliente parseia como
        // hora LOCAL e desloca o disclaimer em 3h.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $json['login_tracking_since']
        );
        // O mínimo deve ser o do usuário real mais antigo ($stale), nunca o
        // do demo (365 dias atrás) — pin do fix de exclusão de demo.
        $this->assertTrue(
            CarbonImmutable::parse($json['login_tracking_since'])->equalTo($stale->last_login_at)
        );
    }

    public function test_week1_return_pct_uses_first_link_within_seven_days(): void
    {
        // Move o admin para fora da janela de cohort (30d) para isolar o
        // cálculo aos dois usuários de teste abaixo.
        $this->admin->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();

        $returnedCreatedAt = now()->subDays(10);
        $returned = User::factory()->create();
        $returned->forceFill(['created_at' => $returnedCreatedAt])->saveQuietly();
        Link::factory()->create([
            'user_id' => $returned->id,
            'is_demo' => false,
            'created_at' => $returnedCreatedAt->copy()->addDays(2),
        ]);

        $notReturnedCreatedAt = now()->subDays(10);
        $notReturned = User::factory()->create();
        $notReturned->forceFill(['created_at' => $notReturnedCreatedAt])->saveQuietly();
        Link::factory()->create([
            'user_id' => $notReturned->id,
            'is_demo' => false,
            'created_at' => $notReturnedCreatedAt->copy()->addDays(20),
        ]);

        $json = $this->getJson('/api/admin/engagement?range=30d', $this->auth())->json('data');

        // cohort = 2 (admin fora da janela); 1 retornou em até 7 dias → 50.0
        $this->assertEquals(50.0, $json['week1_return_pct']);
    }
}
