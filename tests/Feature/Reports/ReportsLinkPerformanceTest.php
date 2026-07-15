<?php

namespace Tests\Feature\Reports;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/link-performance — the portfolio
 * leaderboard: the user's own, non-demo links ranked by clicks in the
 * period, each with a trend vs. the previous period of equal length and
 * its share of the user's total clicks.
 */
class ReportsLinkPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function makeVerifiedUser(): User
    {
        return User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Ranking ordena por cliques desc e cada linha traz variation_pct
     * (vs. o período anterior de igual duração) e share_pct (participação
     * no total de cliques do usuário, não apenas entre as linhas retornadas).
     */
    public function test_ranks_links_with_trend_and_share(): void
    {
        $user = $this->makeVerifiedUser();

        $topLink = Link::factory()->create(['user_id' => $user->id, 'title' => 'Top Link']);
        $lowLink = Link::factory()->create(['user_id' => $user->id, 'title' => 'Low Link']);

        $from = now()->subDays(10);
        $to = now();

        // Período atual (dentro de [from, to]): topLink=8, lowLink=2 (total 10).
        Click::factory()->count(8)->create(['link_id' => $topLink->id, 'is_bot' => false, 'created_at' => now()->subDay()]);
        Click::factory()->count(2)->create(['link_id' => $lowLink->id, 'is_bot' => false, 'created_at' => now()->subDay()]);

        // Período anterior (janela [from - 10d, from]): topLink tinha 4 cliques -> +100%.
        // lowLink não tem cliques no período anterior -> variation_pct null.
        Click::factory()->count(4)->create(['link_id' => $topLink->id, 'is_bot' => false, 'created_at' => now()->subDays(15)]);

        $query = http_build_query([
            'date_from' => $from->toIso8601String(),
            'date_to' => $to->toIso8601String(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson("/api/reports/link-performance?{$query}");

        $response->assertOk()->assertJsonStructure([
            'data' => [
                ['link_id', 'title', 'slug', 'short_domain', 'clicks', 'variation_pct', 'share_pct'],
            ],
        ]);

        $data = $response->json('data');

        $this->assertSame($topLink->id, $data[0]['link_id']);
        $this->assertSame(8, $data[0]['clicks']);
        // assertEquals (not assertSame): json_encode drops the trailing ".0"
        // from a whole-number float, so this decodes as int 80, not float 80.0.
        $this->assertEquals(80.0, $data[0]['share_pct']);
        $this->assertEquals(100.0, $data[0]['variation_pct']);

        $this->assertSame($lowLink->id, $data[1]['link_id']);
        $this->assertSame(2, $data[1]['clicks']);
        $this->assertEquals(20.0, $data[1]['share_pct']);
        $this->assertNull($data[1]['variation_pct']);
    }

    /** Escopo: links de outro usuário e links demo do próprio usuário não entram no ranking. */
    public function test_scopes_to_own_non_demo_links(): void
    {
        $user = $this->makeVerifiedUser();
        $other = User::factory()->create();

        $ownLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $demoLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);
        $otherLink = Link::factory()->create(['user_id' => $other->id]);

        Click::factory()->count(3)->create(['link_id' => $ownLink->id, 'is_bot' => false]);
        Click::factory()->count(9)->create(['link_id' => $demoLink->id, 'is_bot' => false]);
        Click::factory()->count(9)->create(['link_id' => $otherLink->id, 'is_bot' => false]);

        $response = $this->actingAs($user, 'api')->getJson('/api/reports/link-performance');

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($ownLink->id, $data[0]['link_id']);
        $this->assertEquals(100.0, $data[0]['share_pct']);
    }

    /** Sem cliques no período: retorna array vazio (não uma lista de zeros). */
    public function test_returns_empty_array_when_no_clicks(): void
    {
        $user = $this->makeVerifiedUser();
        Link::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'api')->getJson('/api/reports/link-performance');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    /** Endpoint exige autenticação. */
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/reports/link-performance')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
