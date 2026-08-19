<?php

namespace Tests\Unit\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Services\Analytics\MetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the batched aggregation methods added to MetricsService for the
 * batch-meta endpoint: getLinkSparklineBatch() and getLinkTrendBatch() must
 * return, per link id, exactly the same payload the original single-link
 * getLinkSparkline()/getLinkTrend() produce — including zero-filled days,
 * percent_change edge cases and last_click_at — while computing every id in
 * a single grouped query.
 */
class MetricsServiceBatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Creates a user-owned link with the given click timestamps.
     *
     * @param  array<int, \Illuminate\Support\Carbon>  $clickDates  created_at of each click.
     */
    private function makeLinkWithClicks(array $clickDates): Link
    {
        $link = Link::factory()->create([
            'user_id' => User::factory()->create()->id,
            'is_active' => true,
        ]);

        foreach ($clickDates as $date) {
            Click::factory()->create(['link_id' => $link->id, 'created_at' => $date]);
        }

        return $link;
    }

    /**
     * The batch sparkline must match the single-link sparkline for every id,
     * including a link with zero clicks (fully zero-filled series).
     */
    public function test_sparkline_batch_matches_single_link_results(): void
    {
        $withClicks = $this->makeLinkWithClicks([now(), now(), now()->subDays(2)]);
        $noClicks = $this->makeLinkWithClicks([]);

        $service = new MetricsService;
        $batch = $service->getLinkSparklineBatch([$withClicks->id, $noClicks->id], 7);

        $this->assertSame(
            $service->getLinkSparkline($withClicks->id, 7),
            $batch[$withClicks->id]
        );
        $this->assertSame(
            $service->getLinkSparkline($noClicks->id, 7),
            $batch[$noClicks->id]
        );
        $this->assertSame(2, $batch[$withClicks->id][6]['clicks'] + $batch[$withClicks->id][5]['clicks'] - $batch[$withClicks->id][5]['clicks']);
        $this->assertSame(0, array_sum(array_column($batch[$noClicks->id], 'clicks')));
    }

    /**
     * The batch trend must match the single-link trend for every id: counts in
     * both windows, the +100%/0% percent_change edge cases and last_click_at.
     */
    public function test_trend_batch_matches_single_link_results(): void
    {
        // 2 clicks in the current window, 1 in the previous one.
        $active = $this->makeLinkWithClicks([now(), now()->subDays(2), now()->subDays(10)]);
        $silent = $this->makeLinkWithClicks([]);

        $service = new MetricsService;
        $batch = $service->getLinkTrendBatch([$active->id, $silent->id], 7);

        $this->assertEquals($service->getLinkTrend($active->id, 7), $batch[$active->id]);
        $this->assertEquals($service->getLinkTrend($silent->id, 7), $batch[$silent->id]);

        $this->assertSame(2, $batch[$active->id]['current']);
        $this->assertSame(1, $batch[$active->id]['previous']);
        $this->assertSame(100.0, $batch[$active->id]['percent_change']);
        $this->assertNotNull($batch[$active->id]['last_click_at']);

        $this->assertSame(0, $batch[$silent->id]['current']);
        $this->assertSame(0.0, $batch[$silent->id]['percent_change']);
        $this->assertNull($batch[$silent->id]['last_click_at']);
    }

    /**
     * Empty input short-circuits to an empty map without touching the DB.
     */
    public function test_batch_methods_return_empty_map_for_no_ids(): void
    {
        $service = new MetricsService;

        $this->assertSame([], $service->getLinkSparklineBatch([], 7));
        $this->assertSame([], $service->getLinkTrendBatch([], 7));
    }

    /**
     * getLinkQualityBatch classifica cada link pelo percentual de cliques
     * orgânicos na janela: >= 90% organic, >= 50% suspicious, < 50%
     * likely_fraud; link sem clique pontuado na janela → tier/pct nulos.
     * Cliques com quality_tier NULL (anteriores à Fase 3) não entram na conta.
     */
    public function test_quality_batch_classifies_links_by_organic_share(): void
    {
        $organic = $this->makeLinkWithClicks([]);
        foreach (range(1, 9) as $i) {
            Click::factory()->create(['link_id' => $organic->id, 'created_at' => now(), 'quality_tier' => 'organic']);
        }
        Click::factory()->create(['link_id' => $organic->id, 'created_at' => now(), 'quality_tier' => 'suspicious']);

        $fraud = $this->makeLinkWithClicks([]);
        Click::factory()->create(['link_id' => $fraud->id, 'created_at' => now(), 'quality_tier' => 'organic']);
        foreach (range(1, 3) as $i) {
            Click::factory()->create(['link_id' => $fraud->id, 'created_at' => now(), 'quality_tier' => 'likely_fraud']);
        }

        $unscored = $this->makeLinkWithClicks([now()]); // clique sem tier (Fase 3 ainda não rodou)
        $noClicks = $this->makeLinkWithClicks([]);

        $service = new MetricsService;
        $batch = $service->getLinkQualityBatch(
            [$organic->id, $fraud->id, $unscored->id, $noClicks->id],
            30
        );

        $this->assertSame('organic', $batch[$organic->id]['tier']);
        $this->assertSame(90.0, $batch[$organic->id]['organic_pct']);

        $this->assertSame('likely_fraud', $batch[$fraud->id]['tier']);
        $this->assertSame(25.0, $batch[$fraud->id]['organic_pct']);

        $this->assertNull($batch[$unscored->id]['tier']);
        $this->assertNull($batch[$unscored->id]['organic_pct']);
        $this->assertNull($batch[$noClicks->id]['tier']);
        $this->assertNull($batch[$noClicks->id]['organic_pct']);
    }
}
