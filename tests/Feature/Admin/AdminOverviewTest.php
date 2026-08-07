<?php

namespace Tests\Feature\Admin;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * getOverview: totais e séries SEM dados demo, comparação com a janela
 * anterior, zero-fill (um ponto por dia).
 */
class AdminOverviewTest extends TestCase
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

    /** Cria um link (helper — ajustar aos campos mínimos do fillable). */
    private function makeLink(User $owner, bool $demo = false): Link
    {
        return Link::factory()->create([
            'user_id' => $owner->id,
            'is_demo' => $demo,
            'is_active' => true,
        ]);
    }

    public function test_overview_excludes_demo_data(): void
    {
        $real = User::factory()->create();
        $demoAccount = User::factory()->create(['id' => User::DEMO_ACCOUNT_IDS[0]]);

        $realLink = $this->makeLink($real);
        $demoLink = $this->makeLink($real, demo: true);
        $demoAccountLink = $this->makeLink($demoAccount);

        Click::factory()->create(['link_id' => $realLink->id, 'created_at' => now()->subDay()]);
        Click::factory()->create(['link_id' => $demoLink->id, 'created_at' => now()->subDay()]);
        Click::factory()->create(['link_id' => $demoAccountLink->id, 'created_at' => now()->subDay()]);

        $json = $this->getJson('/api/admin/overview?range=7d', $this->auth())
            ->assertOk()
            ->json('data');

        // admin + real = 2 usuários reais (conta demo 40 excluída)
        $this->assertSame(2, $json['totals']['users']);
        $this->assertSame(1, $json['totals']['links']);
        $this->assertSame(1, $json['totals']['clicks']);
    }

    public function test_overview_series_are_zero_filled(): void
    {
        $json = $this->getJson('/api/admin/overview?range=7d', $this->auth())
            ->assertOk()
            ->json('data');

        // 7 dias = 7 pontos por série, mesmo sem nenhum dado.
        $this->assertCount(7, $json['series']['signups']);
        $this->assertCount(7, $json['series']['links']);
        $this->assertCount(7, $json['series']['clicks']);
        $this->assertSame(0, $json['series']['clicks'][0]['value']);
    }

    public function test_overview_compares_with_previous_window(): void
    {
        $real = User::factory()->create();
        $link = $this->makeLink($real);

        // 2 cliques na janela atual, 1 na anterior → +100%.
        Click::factory()->create(['link_id' => $link->id, 'created_at' => now()->subDays(2)]);
        Click::factory()->create(['link_id' => $link->id, 'created_at' => now()->subDays(3)]);
        Click::factory()->create(['link_id' => $link->id, 'created_at' => now()->subDays(10)]);

        $json = $this->getJson('/api/admin/overview?range=7d', $this->auth())->json('data');

        $this->assertSame(2, $json['period']['clicks']['current']);
        $this->assertSame(1, $json['period']['clicks']['previous']);
        // assertEquals (não assertSame): json_encode serializa float integral
        // (100.0) sem casas decimais, então json_decode devolve int — mesmo
        // padrão de ReportsLinkPerformanceTest::test_ranking_includes_variation_pct.
        $this->assertEquals(100.0, $json['period']['clicks']['variation_pct']);
    }

    public function test_invalid_range_is_rejected(): void
    {
        $this->getJson('/api/admin/overview?range=666d', $this->auth())
            ->assertStatus(422);
    }
}
