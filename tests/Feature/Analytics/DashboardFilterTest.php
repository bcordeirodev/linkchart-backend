<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\LinkUtm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for DashboardAnalyticsService filter support.
 *
 * Verifies that the dashboard endpoint correctly applies date-range and
 * bot-exclusion filters when computing summary.total_clicks.
 */
class DashboardFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Clicks before date_from must be excluded from the summary count.
     */
    public function test_date_from_excludes_older_clicks(): void
    {
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-01-01 12:00:00']);
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-02-01 12:00:00']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?date_from=2026-02-01");

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.summary.total_clicks'));
    }

    /**
     * Bot clicks must be excluded when exclude_bots=true is passed.
     */
    public function test_exclude_bots_removes_bot_clicks(): void
    {
        Click::factory()->create(['link_id' => $this->link->id, 'is_bot' => false]);
        Click::factory()->create(['link_id' => $this->link->id, 'is_bot' => true]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?exclude_bots=true");

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.summary.total_clicks'));
    }

    /**
     * Without any filters, all clicks must be included in the summary count.
     */
    public function test_without_filters_returns_all_clicks(): void
    {
        Click::factory()->count(3)->create(['link_id' => $this->link->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard");

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.summary.total_clicks'));
    }

    /**
     * clicks_variation_pct compares the current window against the equally-sized
     * window immediately before it: 3 clicks in February vs 2 in the prior
     * 28-day window is a +50% change.
     */
    public function test_clicks_variation_pct_compares_to_previous_window(): void
    {
        // Previous window [2026-01-04, 2026-02-01): 2 clicks.
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-01-10 12:00:00']);
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-01-15 12:00:00']);
        // Current window [2026-02-01, 2026-03-01]: 3 clicks.
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-02-10 12:00:00']);
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-02-15 12:00:00']);
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-02-20 12:00:00']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?date_from=2026-02-01&date_to=2026-03-01");

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.summary.total_clicks'));
        $this->assertEquals(50.0, $response->json('data.summary.clicks_variation_pct'));
    }

    /**
     * clicks_variation_pct is null when the prior window has no clicks — there is
     * no baseline to compare against, so the frontend omits the variation pill.
     */
    public function test_clicks_variation_pct_is_null_without_prior_window(): void
    {
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-02-10 12:00:00']);
        Click::factory()->create(['link_id' => $this->link->id, 'created_at' => '2026-02-15 12:00:00']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?date_from=2026-02-01&date_to=2026-03-01");

        $response->assertOk();
        $this->assertNull($response->json('data.summary.clicks_variation_pct'));
    }

    /**
     * O painel de UTM usava whereDate(), que trunca para a data e ignora a hora.
     * Com um filtro intradiário (preset de 1h/24h) ele incluía cliques desde as
     * 00:00 daquele dia, divergindo de todos os painéis vizinhos.
     */
    public function test_utm_panel_honours_intraday_date_from(): void
    {
        $link = $this->link;

        // Clique às 02:00 de hoje — FORA de uma janela que começa às 12:00
        $early = Click::factory()->create([
            'link_id' => $link->id,
            'created_at' => today()->setHour(2),
        ]);
        LinkUtm::create([
            'click_id' => $early->id,
            'utm_source' => 'newsletter',
        ]);

        $dateFrom = today()->setHour(12)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/dashboard?date_from={$dateFrom}");

        $response->assertOk();
        $this->assertSame([], $response->json('data.summary.utm_top_sources') ?? []);
    }

    /**
     * A comparação com o período anterior (clicks_variation_pct) precisa honrar
     * o drill-down por dimensão. Sem aplicar `applyDimensions` na query de
     * `$previousClicks`, um filtro de país compararia o país filtrado contra o
     * mundo inteiro no período anterior, inflando artificialmente a variação.
     */
    public function test_clicks_variation_pct_honours_country_drilldown(): void
    {
        $link = $this->link;

        // Período anterior [2026-01-04, 2026-02-01): 1 clique BR (dentro do filtro), 5 US (fora do filtro).
        Click::factory()->create(['link_id' => $link->id, 'country' => 'BR', 'created_at' => '2026-01-15 12:00:00']);
        Click::factory()->count(5)->create(['link_id' => $link->id, 'country' => 'US', 'created_at' => '2026-01-15 12:00:00']);

        // Período atual [2026-02-01, 2026-03-01]: 2 cliques BR.
        Click::factory()->count(2)->create(['link_id' => $link->id, 'country' => 'BR', 'created_at' => '2026-02-10 12:00:00']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/dashboard?date_from=2026-02-01&date_to=2026-03-01&country=BR");

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.summary.total_clicks'));
        // 2 vs 1 (apenas BR no período anterior) = +100%. Sem o fix seria 2 vs 6 = -66,7%.
        $this->assertEquals(100.0, $response->json('data.summary.clicks_variation_pct'));
    }
}
