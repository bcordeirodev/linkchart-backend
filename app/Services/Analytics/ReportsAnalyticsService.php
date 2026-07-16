<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\ReportsAnalyticsServiceInterface;
use App\DTOs\Analytics\AnalyticsFilters;
use App\Services\Analytics\Support\SqlDateExpr;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates click analytics across ALL of a user's own, non-demo links.
 *
 * @see \App\Contracts\Analytics\ReportsAnalyticsServiceInterface
 *
 * Contraparte agregada dos services por-link (DashboardAnalyticsService etc.):
 * em vez de escopar por `link_id`, faz `clicks JOIN links` e escopa por
 * `links.user_id`, sempre excluindo `links.is_demo`. Reaproveita
 * {@see AnalyticsFilters} (date range, bot exclusion e demais dimensões) e
 * {@see SqlDateExpr} para a expressão de data cross-driver (SQLite nos
 * testes, PostgreSQL em produção).
 *
 * Side effects: nenhum — apenas leitura.
 */
class ReportsAnalyticsService implements ReportsAnalyticsServiceInterface
{
    /** Dimensões permitidas no breakdown => coluna SQL qualificada. */
    private const DIMENSIONS = [
        'country' => 'clicks.country',
        'device' => 'clicks.device',
        'browser' => 'clicks.browser',
        'navigation_context' => 'clicks.navigation_context',
        'quality_tier' => 'clicks.quality_tier',
    ];

    /**
     * Query base: cliques de todos os links não-demo do usuário, com os
     * filtros de {@see AnalyticsFilters} aplicados (colunas qualificadas com
     * o prefixo `clicks.` por causa do JOIN).
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filter state to apply.
     * @return Builder Query builder for `clicks JOIN links`, scoped and filtered.
     */
    private function baseQuery(int $userId, AnalyticsFilters $filters): Builder
    {
        $query = DB::table('clicks')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->where('links.user_id', $userId)
            ->where('links.is_demo', false);

        return $filters->applyToQuery($query, 'clicks.');
    }

    /** {@inheritDoc} */
    public function getSummary(int $userId, AnalyticsFilters $filters): array
    {
        $totals = (clone $this->baseQuery($userId, $filters))
            ->selectRaw('COUNT(*) as total_clicks, COUNT(DISTINCT clicks.ip) as unique_visitors')
            ->first();

        $links = DB::table('links')
            ->where('user_id', $userId)
            ->where('is_demo', false)
            ->selectRaw('COUNT(*) as total_links, SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active_links')
            ->first();

        $totalClicks = (int) $totals->total_clicks;

        // Período efetivo: default últimos 30 dias quando não filtrado.
        [$from, $days] = $this->currentWindow($filters);

        return [
            'total_clicks' => $totalClicks,
            'unique_visitors' => (int) $totals->unique_visitors,
            'total_links' => (int) $links->total_links,
            'active_links' => (int) $links->active_links,
            'avg_clicks_per_day' => round($totalClicks / $days, 1),
            'variation_pct' => $this->variationPct($userId, $filters, $totalClicks, $from, $days),
        ];
    }

    /**
     * Percentage change vs. the immediately preceding period of the same
     * duration (same concept as `DashboardAnalyticsService::clicksVariationPct`,
     * generalized to the multi-link scope).
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Active filter constraints (dimensions reapplied, date range replaced by the previous window).
     * @param  int  $currentClicks  Pre-computed click count for the current window.
     * @param  Carbon  $from  Start of the current window.
     * @param  int  $days  Length of the current window, in days.
     * @return float|null Signed percentage change (one decimal), or null when the previous window has zero clicks.
     */
    private function variationPct(int $userId, AnalyticsFilters $filters, int $currentClicks, Carbon $from, int $days): ?float
    {
        $previousClicks = $this->previousPeriodClicksByLink($userId, $filters, $from, $days)->sum();

        if ($previousClicks === 0) {
            return null;
        }

        return round((($currentClicks - $previousClicks) * 100) / $previousClicks, 1);
    }

    /**
     * Resolves the active window's start instant and length in days.
     *
     * Default window (when the filter has no explicit date range) is the
     * last 30 days — same default as {@see getSummary()}. Factored out so
     * every method that needs a "previous period of equal length" comparison
     * (`getSummary()`, `getLinkPerformance()`, `getInsights()`) computes it
     * identically.
     *
     * @param  AnalyticsFilters  $filters  Active filter constraints.
     * @return array{0: Carbon, 1: int} Tuple of [window start, window length in days].
     */
    private function currentWindow(AnalyticsFilters $filters): array
    {
        $from = $filters->dateFrom ?? Carbon::now()->subDays(30);
        $to = $filters->dateTo ?? Carbon::now();
        $days = max(1, (int) ceil($from->diffInHours($to) / 24));

        return [$from, $days];
    }

    /**
     * Returns per-link click counts for the period immediately preceding the
     * active window, keyed by link id — the trend baseline shared by
     * `variationPct()` (account-wide), `getLinkPerformance()` and
     * `getInsights()`'s fastest-growing-link calculation.
     *
     * The comparison window has the same length as `[$from, $from + $days]`:
     * `[$from - $days, $from]`. Honours every dimension filter (bot
     * exclusion, country, device, channel, continent) via
     * `applyDimensions()`, but NOT the date range — this query defines its
     * own shifted window.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Active filter constraints (dimensions reapplied).
     * @param  Carbon  $from  Start of the CURRENT window (the previous window ends here).
     * @param  int  $days  Length of the current (and previous) window, in days.
     * @param  array<int, int>  $linkIds  Restrict the comparison to these link IDs; empty = all.
     * @return Collection<int, int> Map of link_id => click count in the previous window.
     */
    private function previousPeriodClicksByLink(int $userId, AnalyticsFilters $filters, Carbon $from, int $days, array $linkIds = []): Collection
    {
        $previousStart = $from->copy()->subDays($days);

        $query = DB::table('clicks')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->where('links.user_id', $userId)
            ->where('links.is_demo', false)
            ->whereBetween('clicks.created_at', [$previousStart, $from])
            ->when($linkIds !== [], fn ($q) => $q->whereIn('links.id', $linkIds));

        $query = $filters->applyDimensions($query, 'clicks.');

        return $query
            ->selectRaw('links.id as link_id, COUNT(*) as clicks')
            ->groupBy('links.id')
            ->pluck('clicks', 'link_id')
            ->map(fn ($c) => (int) $c);
    }

    /** {@inheritDoc} */
    public function getTimeseries(int $userId, AnalyticsFilters $filters): array
    {
        $from = $filters->dateFrom ?? Carbon::now()->subDays(30);
        $to = $filters->dateTo ?? Carbon::now();
        $dates = $this->windowDates($from, $to);

        $dateExpr = SqlDateExpr::date('clicks.created_at');

        $rows = $this->baseQuery($userId, $filters)
            ->selectRaw("{$dateExpr} as date, COUNT(*) as clicks, COUNT(DISTINCT clicks.ip) as unique_visitors")
            ->groupByRaw($dateExpr)
            ->get()
            ->keyBy('date');

        $series = array_map(fn (string $date) => [
            'date' => $date,
            'clicks' => (int) ($rows[$date]->clicks ?? 0),
            'unique_visitors' => (int) ($rows[$date]->unique_visitors ?? 0),
        ], $dates);

        return [
            'series' => $series,
            'previous' => $this->previousTimeseries($userId, $filters, $from, count($dates)),
        ];
    }

    /**
     * Lista de datas (`Y-m-d`) cobertas pela janela, inclusiva nas duas pontas.
     *
     * Base do zero-fill do timeseries e do `spark` do link-performance: garante
     * que toda série diária tenha exatamente um ponto por dia calendário, com
     * dias sem clique preenchidos com zero — sem isso o gráfico de comparação
     * (série atual × anterior, alinhadas por índice) ficaria distorcido.
     *
     * @param  Carbon  $from  Início da janela.
     * @param  Carbon  $to  Fim da janela.
     * @return array<int, string> Datas `Y-m-d` em ordem crescente.
     */
    private function windowDates(Carbon $from, Carbon $to): array
    {
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $dates = [];

        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Série diária de cliques da janela imediatamente anterior, com o MESMO
     * número de pontos da janela atual (zero-fill), para o overlay tracejado
     * do gráfico do `/reports` — alinhada por índice com a série atual.
     *
     * Janela anterior: `[startOfDay($from) - $days, startOfDay($from))` —
     * alinhada por dia calendário (não pelo instante de `$from`) e meio-aberta
     * para não contar duas vezes um clique exatamente na fronteira. Alinhar
     * por dia é essencial: os buckets de label (`Y-m-d`) são por dia
     * calendário, então usar o instante de `$from` como fronteira SQL faria
     * os limites de dia e os limites de query divergirem sempre que `$from`
     * não for meia-noite (ex.: o default sem filtro, `now()->subDays(30)`) —
     * o primeiro dia da janela anterior ficaria subcontado e cliques no dia
     * calendário de `$from`, antes do horário exato de `$from`, seriam
     * buscados pelo SQL mas descartados por não bater com nenhum label.
     * Reaplica os filtros de dimensão (bots etc.) mas NUNCA o date range
     * (define a própria janela), mesmo contrato de
     * {@see previousPeriodClicksByLink()}.
     *
     * @param  int  $userId  Owner's user ID.
     * @param  AnalyticsFilters  $filters  Filtros ativos (dimensões reaplicadas).
     * @param  Carbon  $from  Início da janela ATUAL.
     * @param  int  $days  Número de pontos da série atual.
     * @return array<int, array{date: string, clicks: int}>
     */
    private function previousTimeseries(int $userId, AnalyticsFilters $filters, Carbon $from, int $days): array
    {
        $fromDay = $from->copy()->startOfDay();
        $previousStart = $fromDay->copy()->subDays($days);
        $dateExpr = SqlDateExpr::date('clicks.created_at');

        $query = DB::table('clicks')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->where('links.user_id', $userId)
            ->where('links.is_demo', false)
            ->where('clicks.created_at', '>=', $previousStart)
            ->where('clicks.created_at', '<', $fromDay);

        $query = $filters->applyDimensions($query, 'clicks.');

        $rows = $query
            ->selectRaw("{$dateExpr} as date, COUNT(*) as clicks")
            ->groupByRaw($dateExpr)
            ->get()
            ->keyBy('date');

        $result = [];
        for ($d = 0; $d < $days; $d++) {
            $date = $previousStart->copy()->addDays($d)->format('Y-m-d');
            $result[] = ['date' => $date, 'clicks' => (int) ($rows[$date]->clicks ?? 0)];
        }

        return $result;
    }

    /** {@inheritDoc} */
    public function getTopLinks(int $userId, AnalyticsFilters $filters, int $limit = 10): array
    {
        return $this->baseQuery($userId, $filters)
            ->selectRaw('links.id as link_id, links.title, links.slug, links.short_domain,
                COUNT(*) as clicks, COUNT(DISTINCT clicks.ip) as unique_visitors')
            ->groupBy('links.id', 'links.title', 'links.slug', 'links.short_domain')
            ->orderByDesc('clicks')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** {@inheritDoc} */
    public function getBreakdown(int $userId, string $dimension, AnalyticsFilters $filters, int $limit = 10): array
    {
        $column = self::DIMENSIONS[$dimension]
            ?? throw new \InvalidArgumentException("Dimensão inválida: {$dimension}");

        $rows = $this->baseQuery($userId, $filters)
            ->selectRaw("{$column} as label, COUNT(*) as clicks")
            ->whereNotNull($column)
            ->groupBy('label')
            ->orderByDesc('clicks')
            ->limit($limit)
            ->get();

        $total = max(1, $rows->sum('clicks'));

        return $rows->map(fn ($r) => [
            'label' => $r->label,
            'clicks' => (int) $r->clicks,
            'pct' => round($r->clicks * 100 / $total, 1),
        ])->all();
    }

    /** {@inheritDoc} */
    public function exportClicksQuery(int $userId, AnalyticsFilters $filters): Builder
    {
        return $this->baseQuery($userId, $filters)
            ->select([
                'clicks.created_at', 'links.title', 'links.slug', 'clicks.country', 'clicks.city',
                'clicks.device', 'clicks.browser', 'clicks.os', 'clicks.referer',
                'clicks.navigation_context', 'clicks.quality_tier',
            ])
            ->orderBy('clicks.id');
    }

    /** {@inheritDoc} */
    public function getLinkPerformance(int $userId, AnalyticsFilters $filters, int $limit = 10): array
    {
        $totalClicks = (clone $this->baseQuery($userId, $filters))->count();

        if ($totalClicks === 0) {
            return [];
        }

        $current = $this->baseQuery($userId, $filters)
            ->selectRaw('links.id as link_id, links.title, links.slug, links.short_domain, COUNT(*) as clicks')
            ->groupBy('links.id', 'links.title', 'links.slug', 'links.short_domain')
            ->orderByDesc('clicks')
            ->limit($limit)
            ->get();

        [$from, $days] = $this->currentWindow($filters);
        $previousByLink = $this->previousPeriodClicksByLink($userId, $filters, $from, $days, $current->pluck('link_id')->all());

        return $current->map(function ($r) use ($previousByLink, $totalClicks) {
            $clicks = (int) $r->clicks;
            $previous = $previousByLink[$r->link_id] ?? 0;

            return [
                'link_id' => $r->link_id,
                'title' => $r->title,
                'slug' => $r->slug,
                'short_domain' => $r->short_domain,
                'clicks' => $clicks,
                'variation_pct' => $previous === 0 ? null : round((($clicks - $previous) * 100) / $previous, 1),
                'share_pct' => round($clicks * 100 / $totalClicks, 1),
            ];
        })->all();
    }

    /** {@inheritDoc} */
    public function getInsights(int $userId, AnalyticsFilters $filters): array
    {
        $totalClicks = (clone $this->baseQuery($userId, $filters))->count();

        if ($totalClicks === 0) {
            return [
                ['key' => 'best_performing_link', 'value' => null, 'unit' => null, 'meta' => null],
                ['key' => 'fastest_growing_link', 'value' => null, 'unit' => null, 'meta' => null],
                ['key' => 'top3_concentration', 'value' => null, 'unit' => '%', 'meta' => null],
                ['key' => 'account_growth', 'value' => null, 'unit' => '%', 'meta' => null],
            ];
        }

        $perLink = $this->baseQuery($userId, $filters)
            ->selectRaw('links.id as link_id, links.title, links.slug, COUNT(*) as clicks')
            ->groupBy('links.id', 'links.title', 'links.slug')
            ->orderByDesc('clicks')
            ->get();

        $best = $perLink->first();
        $top3Clicks = (int) $perLink->take(3)->sum('clicks');

        [$from, $days] = $this->currentWindow($filters);
        $previousByLink = $this->previousPeriodClicksByLink($userId, $filters, $from, $days, $perLink->pluck('link_id')->all());

        [$fastest, $fastestPct] = $this->fastestGrowingLink($perLink, $previousByLink);

        $accountGrowth = $this->variationPct($userId, $filters, $totalClicks, $from, $days);

        return [
            [
                'key' => 'best_performing_link',
                'value' => $best->title ?: $best->slug,
                'unit' => null,
                'meta' => ['link_id' => $best->link_id, 'slug' => $best->slug, 'clicks' => (int) $best->clicks],
            ],
            [
                'key' => 'fastest_growing_link',
                'value' => $fastest ? ($fastest->title ?: $fastest->slug) : null,
                'unit' => null,
                'meta' => $fastest ? ['link_id' => $fastest->link_id, 'slug' => $fastest->slug, 'variation_pct' => $fastestPct] : null,
            ],
            [
                'key' => 'top3_concentration',
                'value' => round($top3Clicks * 100 / $totalClicks, 1),
                'unit' => '%',
                'meta' => null,
            ],
            [
                'key' => 'account_growth',
                'value' => $accountGrowth,
                'unit' => '%',
                'meta' => null,
            ],
        ];
    }

    /**
     * Finds the link with the highest period-over-period growth among those
     * that have a non-zero previous-period baseline.
     *
     * Links with zero previous-period clicks are skipped — with no
     * baseline, a percentage growth figure would be either undefined (0 → 0)
     * or misleadingly infinite (0 → N), so they are excluded from
     * "fastest growing" rather than reported with a fabricated number.
     *
     * @param  Collection  $perLink  Rows from the current-window per-link query (each with `link_id`, `title`, `slug`, `clicks`).
     * @param  Collection<int, int>  $previousByLink  Map of link_id => previous-window click count, from {@see previousPeriodClicksByLink()}.
     * @return array{0: object|null, 1: float|null} Tuple of [winning row or null, its variation_pct or null].
     */
    private function fastestGrowingLink(Collection $perLink, Collection $previousByLink): array
    {
        $fastest = null;
        $fastestPct = null;

        foreach ($perLink as $row) {
            $previous = $previousByLink[$row->link_id] ?? 0;
            if ($previous === 0) {
                continue;
            }

            $pct = round((($row->clicks - $previous) * 100) / $previous, 1);
            if ($fastestPct === null || $pct > $fastestPct) {
                $fastestPct = $pct;
                $fastest = $row;
            }
        }

        return [$fastest, $fastestPct];
    }
}
