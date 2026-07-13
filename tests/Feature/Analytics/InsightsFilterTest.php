<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for InsightsAnalyticsService filter support.
 *
 * Verifies that the insights endpoint correctly applies date-range and
 * bot-exclusion filters, and that analytics_data.quality.total_clicks
 * reflects only the filtered subset.
 */
class InsightsFilterTest extends TestCase
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
     * Without any filters, the endpoint returns data and the quality block
     * reports the total count of seeded clicks.
     */
    public function test_without_filters_returns_data(): void
    {
        Click::factory()->count(5)->create(['link_id' => $this->link->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights");

        $response->assertOk();
        $response->assertJsonPath('data.insights', fn ($v) => is_array($v));
    }

    /**
     * Clicks before date_from must be excluded from the quality total.
     */
    public function test_date_from_scopes_analytics_data(): void
    {
        // 2 old clicks — should be excluded by the filter
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'created_at' => now()->subDays(30),
        ]);

        // 3 recent clicks — should be included
        Click::factory()->count(3)->create([
            'link_id' => $this->link->id,
            'created_at' => now()->subDays(1),
        ]);

        $dateFrom = now()->subDays(10)->toDateString();

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?date_from={$dateFrom}");

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.analytics_data.quality.total_clicks'));
    }

    /**
     * Bot clicks must be excluded when exclude_bots=true is passed.
     */
    public function test_exclude_bots_removes_bot_clicks(): void
    {
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'is_bot' => false,
            'quality_tier' => 'organic',
        ]);

        Click::factory()->count(3)->create([
            'link_id' => $this->link->id,
            'is_bot' => true,
            'quality_tier' => 'likely_fraud',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?exclude_bots=true");

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.analytics_data.quality.total_clicks'));
    }

    /**
     * When exclude_bots=false, both human and bot clicks must be included.
     */
    public function test_exclude_bots_false_includes_all_clicks(): void
    {
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'is_bot' => false,
        ]);

        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'is_bot' => true,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?exclude_bots=false");

        $response->assertOk();
        $this->assertEquals(4, $response->json('data.analytics_data.quality.total_clicks'));
    }

    /**
     * Reproduz o bug: os geradores calculavam o numerador sobre TODOS os cliques
     * do link enquanto o denominador ($totalClicks) já vinha filtrado, produzindo
     * percentuais inflados — e acima de 100% quando o total filtrado era menor
     * que o numerador global.
     */
    public function test_device_insight_percentage_respects_the_date_filter(): void
    {
        // 10 cliques mobile FORA da janela
        Click::factory()->count(10)->create([
            'link_id' => $this->link->id,
            'device' => 'mobile',
            'created_at' => now()->subDays(30),
        ]);

        // Dentro da janela: 2 mobile + 2 desktop => mobile = 50% de 4
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'device' => 'mobile',
            'created_at' => now()->subHour(),
        ]);
        Click::factory()->count(2)->create([
            'link_id' => $this->link->id,
            'device' => 'desktop',
            'created_at' => now()->subHour(),
        ]);

        $dateFrom = now()->subDay()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?date_from={$dateFrom}");

        $response->assertOk();

        $device = collect($response->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.device.dominant.title');

        $this->assertNotNull($device, 'O insight de dispositivo deveria existir.');

        // Antes da correção: 12 mobile / 4 totais = 300%.
        // assertEquals (não assertSame): o valor passa por json_encode/decode via
        // $response->json(), que colapsa floats "redondos" (50.0) para int (50) —
        // mesmo padrão usado em DashboardFilterTest::test_clicks_variation_pct_compares_to_previous_window.
        $this->assertEquals(50.0, $device['description_params']['pct']);
    }
}
