<?php

namespace Tests\Feature\Reports;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for GET /api/reports/insights — portfolio-level (account-wide)
 * computed insights: best performing link, fastest growing link, top-3
 * traffic concentration and overall account growth vs. the previous period.
 *
 * These are distinct from the per-link insights served by
 * InsightsAnalyticsService — every value here only makes sense aggregated
 * across the user's whole portfolio of links.
 */
class ReportsInsightsTest extends TestCase
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

    /** Extracts the `value` for a given insight `key` from the response payload. */
    private function valueFor(array $data, string $key): mixed
    {
        foreach ($data as $insight) {
            if ($insight['key'] === $key) {
                return $insight['value'];
            }
        }

        $this->fail("insight key not found: {$key}");
    }

    /** Extracts the `meta` for a given insight `key` from the response payload. */
    private function metaFor(array $data, string $key): ?array
    {
        foreach ($data as $insight) {
            if ($insight['key'] === $key) {
                return $insight['meta'];
            }
        }

        $this->fail("insight key not found: {$key}");
    }

    /**
     * Cenário com 3 links: A é o de melhor desempenho (mais cliques), B tem a
     * maior variação (cresceu mais, apesar de menos cliques que A), C não tem
     * baseline no período anterior (é ignorado no "fastest growing").
     * Concentração top-3 = 100% (só existem 3 links). Crescimento da conta =
     * (10-4)/4*100 = 150%.
     */
    public function test_computes_portfolio_insights(): void
    {
        $user = $this->makeVerifiedUser();

        $linkA = Link::factory()->create(['user_id' => $user->id, 'title' => 'Link A']);
        $linkB = Link::factory()->create(['user_id' => $user->id, 'title' => 'Link B']);
        $linkC = Link::factory()->create(['user_id' => $user->id, 'title' => 'Link C']);

        $from = now()->subDays(10);
        $to = now();

        // Período atual: A=6, B=3, C=1 (total 10).
        Click::factory()->count(6)->create(['link_id' => $linkA->id, 'is_bot' => false, 'created_at' => now()->subDay()]);
        Click::factory()->count(3)->create(['link_id' => $linkB->id, 'is_bot' => false, 'created_at' => now()->subDay()]);
        Click::factory()->count(1)->create(['link_id' => $linkC->id, 'is_bot' => false, 'created_at' => now()->subDay()]);

        // Período anterior: A=3 (+100%), B=1 (+200% -> fastest growing), C=0 (sem baseline).
        Click::factory()->count(3)->create(['link_id' => $linkA->id, 'is_bot' => false, 'created_at' => now()->subDays(15)]);
        Click::factory()->count(1)->create(['link_id' => $linkB->id, 'is_bot' => false, 'created_at' => now()->subDays(15)]);

        $query = http_build_query([
            'date_from' => $from->toIso8601String(),
            'date_to' => $to->toIso8601String(),
        ]);

        $response = $this->actingAs($user, 'api')->getJson("/api/reports/insights?{$query}");

        $response->assertOk()->assertJsonStructure([
            'data' => [
                ['key', 'value', 'unit', 'meta'],
            ],
        ]);

        $data = $response->json('data');
        $this->assertCount(4, $data);

        $this->assertSame('Link A', $this->valueFor($data, 'best_performing_link'));
        $this->assertSame(6, $this->metaFor($data, 'best_performing_link')['clicks']);

        $this->assertSame('Link B', $this->valueFor($data, 'fastest_growing_link'));
        // assertEquals (not assertSame): json_encode drops the trailing ".0"
        // from a whole-number float, so these decode as ints, not floats.
        $this->assertEquals(200.0, $this->metaFor($data, 'fastest_growing_link')['variation_pct']);

        $this->assertEquals(100.0, $this->valueFor($data, 'top3_concentration'));
        $this->assertEquals(150.0, $this->valueFor($data, 'account_growth'));
    }

    /** Sem cliques no período: todas as 4 chaves retornam value=null (não uma exceção ou lista vazia). */
    public function test_returns_null_values_when_no_clicks(): void
    {
        $user = $this->makeVerifiedUser();
        Link::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'api')->getJson('/api/reports/insights');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(4, $data);
        foreach ($data as $insight) {
            $this->assertNull($insight['value']);
        }
    }

    /** Escopo: links de outro usuário e links demo não entram nos insights. */
    public function test_scopes_to_own_non_demo_links(): void
    {
        $user = $this->makeVerifiedUser();
        $other = User::factory()->create();

        $ownLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false, 'title' => 'Own Link']);
        $demoLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);
        $otherLink = Link::factory()->create(['user_id' => $other->id]);

        Click::factory()->count(2)->create(['link_id' => $ownLink->id, 'is_bot' => false]);
        Click::factory()->count(9)->create(['link_id' => $demoLink->id, 'is_bot' => false]);
        Click::factory()->count(9)->create(['link_id' => $otherLink->id, 'is_bot' => false]);

        $response = $this->actingAs($user, 'api')->getJson('/api/reports/insights');

        $data = $response->json('data');
        $this->assertSame('Own Link', $this->valueFor($data, 'best_performing_link'));
        $this->assertEquals(100.0, $this->valueFor($data, 'top3_concentration'));
    }

    /** Endpoint exige autenticação. */
    public function test_requires_authentication(): void
    {
        $this->getJson('/api/reports/insights')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
