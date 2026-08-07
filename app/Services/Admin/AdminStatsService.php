<?php

namespace App\Services\Admin;

use App\Contracts\Admin\AdminStatsServiceInterface;
use App\Models\Link;
use App\Models\User;
use App\Services\Analytics\Support\SqlDateExpr;
use Carbon\CarbonImmutable;

/**
 * Implementação dos agregados globais do painel admin.
 *
 * Molde: ReportsAnalyticsService (o agregador multi-link existente), com o
 * predicado de user_id removido e a exclusão de demo via Link::nonDemo().
 * Somente leitura — nenhum side effect.
 */
class AdminStatsService implements AdminStatsServiceInterface
{
    /** Fuso do produto para bucketing de dia (predicados SQL ficam em UTC). */
    private const TZ = 'America/Sao_Paulo';

    /** {@inheritDoc} */
    public function getOverview(int $days): array
    {
        // Janela atual: hoje (em SP) - (days-1) até agora; anterior: mesma
        // duração imediatamente antes. Predicados convertem para UTC.
        $now = CarbonImmutable::now(self::TZ);
        $from = $now->subDays($days - 1)->startOfDay();
        $prevFrom = $from->subDays($days);

        $fromUtc = $from->utc();
        $prevFromUtc = $prevFrom->utc();

        // ---- Totais all-time (fonte de cliques: COUNT(clicks) via nonDemo) ----
        $totals = [
            'users' => User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)->count(),
            'links' => Link::nonDemo()->count(),
            'clicks' => (int) Link::nonDemo()
                ->join('clicks', 'clicks.link_id', '=', 'links.id')
                ->count(),
        ];

        // ---- Contagens por janela (atual vs anterior) ----
        $signupsCurrent = User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)
            ->where('created_at', '>=', $fromUtc)->count();
        $signupsPrevious = User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)
            ->whereBetween('created_at', [$prevFromUtc, $fromUtc])->count();

        $linksCurrent = Link::nonDemo()->where('links.created_at', '>=', $fromUtc)->count();
        $linksPrevious = Link::nonDemo()->whereBetween('links.created_at', [$prevFromUtc, $fromUtc])->count();

        $clicksCurrent = (int) Link::nonDemo()
            ->join('clicks', 'clicks.link_id', '=', 'links.id')
            ->where('clicks.created_at', '>=', $fromUtc)->count();
        $clicksPrevious = (int) Link::nonDemo()
            ->join('clicks', 'clicks.link_id', '=', 'links.id')
            ->whereBetween('clicks.created_at', [$prevFromUtc, $fromUtc])->count();

        // ---- Séries diárias com zero-fill ----
        $dates = $this->windowDates($from, $now);

        $series = [
            'signups' => $this->dailySeries(
                User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)->where('created_at', '>=', $fromUtc)->toBase(),
                'created_at',
                $dates
            ),
            'links' => $this->dailySeries(
                Link::nonDemo()->where('links.created_at', '>=', $fromUtc)->toBase(),
                'links.created_at',
                $dates
            ),
            'clicks' => $this->dailySeries(
                Link::nonDemo()
                    ->join('clicks', 'clicks.link_id', '=', 'links.id')
                    ->where('clicks.created_at', '>=', $fromUtc)->toBase(),
                'clicks.created_at',
                $dates
            ),
        ];

        return [
            'totals' => $totals,
            'period' => [
                'signups' => $this->periodComparison($signupsCurrent, $signupsPrevious),
                'links' => $this->periodComparison($linksCurrent, $linksPrevious),
                'clicks' => $this->periodComparison($clicksCurrent, $clicksPrevious),
            ],
            'series' => $series,
        ];
    }

    /**
     * Contagem por dia (fuso SP) com zero-fill sobre a janela.
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query já filtrada pela janela (predicado na coluna crua).
     * @param  string  $timestampColumn  Coluna qualificada usada no bucket.
     * @param  array<int, string>  $dates  Datas Y-m-d da janela ({@see windowDates}).
     * @return array<int, array{date: string, value: int}>
     */
    private function dailySeries(\Illuminate\Database\Query\Builder $query, string $timestampColumn, array $dates): array
    {
        $dateExpr = SqlDateExpr::dateSaoPaulo($timestampColumn);

        $rows = $query
            ->selectRaw("{$dateExpr} as date, COUNT(*) as value")
            ->groupByRaw($dateExpr)
            ->pluck('value', 'date');

        return array_map(fn (string $date) => [
            'date' => $date,
            'value' => (int) ($rows[$date] ?? 0),
        ], $dates);
    }

    /**
     * Datas Y-m-d (fuso SP) da janela, inclusivas — mesma semântica do
     * windowDates do ReportsAnalyticsService (base do zero-fill).
     *
     * @param  CarbonImmutable  $from  Início da janela (já em SP, startOfDay).
     * @param  CarbonImmutable  $to  Fim da janela (agora, em SP).
     * @return array<int, string>
     */
    private function windowDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cursor = $from;
        $end = $to->startOfDay();
        $dates = [];

        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Par atual/anterior com variação percentual (null sem baseline — mesma
     * convenção do variationPct do ReportsAnalyticsService).
     *
     * @param  int  $current  Contagem da janela atual.
     * @param  int  $previous  Contagem da janela anterior de mesma duração.
     * @return array{current: int, previous: int, variation_pct: float|null}
     */
    private function periodComparison(int $current, int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'variation_pct' => $previous === 0
                ? null
                : round((($current - $previous) * 100) / $previous, 1),
        ];
    }

    /** {@inheritDoc} */
    public function getUsers(int $page, int $perPage, ?string $q, string $sort, string $order): array
    {
        return [
            'items' => [],
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1],
        ];
    }

    /** {@inheritDoc} */
    public function getEngagement(int $days): array
    {
        return [];
    }

    /** {@inheritDoc} */
    public function getHealth(): array
    {
        return [];
    }
}
