<?php

namespace Tests\Feature\Reports;

use App\Contracts\Analytics\ReportsAnalyticsServiceInterface;
use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the multi-link aggregation service backing the /reports module.
 *
 * Every aggregation must be scoped to the authenticated user's own, non-demo
 * links — these tests exercise that boundary directly against the service,
 * independent of the HTTP layer (covered separately in ReportsEndpointsTest).
 */
class ReportsAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportsAnalyticsServiceInterface $service;

    /** {@inheritDoc} */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReportsAnalyticsServiceInterface::class);
    }

    /** Aggregações escopam por usuário: cliques do user B não aparecem para o user A. */
    public function test_summary_only_counts_own_links(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $linkA = Link::factory()->create(['user_id' => $userA->id]);
        $linkB = Link::factory()->create(['user_id' => $userB->id]);

        \App\Models\Click::factory()->count(3)->create(['link_id' => $linkA->id, 'is_bot' => false]);
        \App\Models\Click::factory()->count(5)->create(['link_id' => $linkB->id, 'is_bot' => false]);

        $summary = $this->service->getSummary($userA->id, new AnalyticsFilters);

        $this->assertSame(3, $summary['total_clicks']);
        $this->assertSame(1, $summary['total_links']);
    }

    /** Links is_demo são excluídos de todas as agregações. */
    public function test_demo_links_are_excluded(): void
    {
        $user = User::factory()->create();

        $realLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => false]);
        $demoLink = Link::factory()->create(['user_id' => $user->id, 'is_demo' => true]);

        \App\Models\Click::factory()->count(2)->create(['link_id' => $realLink->id, 'is_bot' => false]);
        \App\Models\Click::factory()->count(10)->create(['link_id' => $demoLink->id, 'is_bot' => false]);

        $summary = $this->service->getSummary($user->id, new AnalyticsFilters);

        $this->assertSame(2, $summary['total_clicks']);
        $this->assertSame(1, $summary['total_links']);
    }

    /** Filtro de data limita a série temporal (clicks.created_at, coluna qualificada). */
    public function test_timeseries_respects_date_range(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id]);

        \App\Models\Click::factory()->create([
            'link_id' => $link->id,
            'is_bot' => false,
            'created_at' => now()->subDays(10),
        ]);
        \App\Models\Click::factory()->create([
            'link_id' => $link->id,
            'is_bot' => false,
            'created_at' => now()->subDay(),
        ]);

        $filters = new AnalyticsFilters(
            dateFrom: now()->subDays(3)->toIso8601String(),
            dateTo: now()->toIso8601String(),
        );

        $timeseries = $this->service->getTimeseries($user->id, $filters);

        $totalClicks = array_sum(array_column($timeseries['series'], 'clicks'));
        $this->assertSame(1, $totalClicks);
    }

    /** Timeseries retorna shape {series, previous} com zero-fill e visitantes únicos. */
    public function test_timeseries_returns_series_and_previous_with_zero_fill(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id]);

        // 3 cliques ontem (2 IPs distintos) — janela atual
        \App\Models\Click::factory()->count(2)->create([
            'link_id' => $link->id, 'is_bot' => false,
            'created_at' => now()->subDay(), 'ip' => '1.1.1.1',
        ]);
        \App\Models\Click::factory()->create([
            'link_id' => $link->id, 'is_bot' => false,
            'created_at' => now()->subDay(), 'ip' => '2.2.2.2',
        ]);
        // 1 clique há 35 dias — cai na janela ANTERIOR (default = últimos 30 dias)
        \App\Models\Click::factory()->create([
            'link_id' => $link->id, 'is_bot' => false,
            'created_at' => now()->subDays(35),
        ]);

        $result = $this->service->getTimeseries($user->id, new AnalyticsFilters);

        $this->assertArrayHasKey('series', $result);
        $this->assertArrayHasKey('previous', $result);

        // Mesma quantidade de pontos nas duas séries (alinhamento por índice no gráfico)
        $this->assertSame(count($result['series']), count($result['previous']));
        // Zero-fill: um ponto por dia calendário da janela (31 = hoje + 30 dias anteriores)
        $this->assertSame(31, count($result['series']));

        $this->assertSame(3, array_sum(array_column($result['series'], 'clicks')));
        $this->assertSame(1, array_sum(array_column($result['previous'], 'clicks')));

        $yesterday = collect($result['series'])
            ->firstWhere('date', now()->subDay()->format('Y-m-d'));
        $this->assertSame(3, $yesterday['clicks']);
        $this->assertSame(2, $yesterday['unique_visitors']);
    }

    /** Clique na primeira hora do primeiro dia da janela anterior é contado (fronteira alinhada por dia). */
    public function test_previous_timeseries_counts_first_day_early_clicks(): void
    {
        $user = User::factory()->create();
        $link = Link::factory()->create(['user_id' => $user->id]);

        // Janela default: series tem 31 dias; janela anterior = [startOfDay(from) - 31d, startOfDay(from))
        $firstPreviousDay = now()->subDays(30)->startOfDay()->subDays(31);

        \App\Models\Click::factory()->create([
            'link_id' => $link->id, 'is_bot' => false,
            'created_at' => $firstPreviousDay->copy()->addHour(),
        ]);

        $result = $this->service->getTimeseries($user->id, new AnalyticsFilters);

        $this->assertSame(1, array_sum(array_column($result['previous'], 'clicks')));
        $this->assertSame($firstPreviousDay->format('Y-m-d'), $result['previous'][0]['date']);
    }

    /** Top links ordena por cliques desc e traz slug/título. */
    public function test_top_links_orders_by_clicks(): void
    {
        $user = User::factory()->create();
        $topLink = Link::factory()->create(['user_id' => $user->id, 'title' => 'Top Link']);
        $lowLink = Link::factory()->create(['user_id' => $user->id, 'title' => 'Low Link']);

        \App\Models\Click::factory()->count(5)->create(['link_id' => $topLink->id, 'is_bot' => false]);
        \App\Models\Click::factory()->count(1)->create(['link_id' => $lowLink->id, 'is_bot' => false]);

        $topLinks = $this->service->getTopLinks($user->id, new AnalyticsFilters, 10);

        $this->assertCount(2, $topLinks);
        $this->assertSame($topLink->id, $topLinks[0]['link_id']);
        $this->assertSame(5, $topLinks[0]['clicks']);
        $this->assertArrayHasKey('slug', $topLinks[0]);
    }

    /** Dimensão fora da whitelist lança InvalidArgumentException. */
    public function test_breakdown_rejects_unknown_dimension(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->service->getBreakdown($user->id, 'not_a_real_dimension', new AnalyticsFilters);
    }
}
