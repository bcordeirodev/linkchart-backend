<?php

namespace App\Services\Analytics;

use App\Contracts\Analytics\ReportsAnalyticsServiceInterface;
use App\DTOs\Analytics\AnalyticsFilters;
use App\Services\Analytics\Support\SqlDateExpr;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
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
        $from = $filters->dateFrom ?? Carbon::now()->subDays(30);
        $to = $filters->dateTo ?? Carbon::now();
        $days = max(1, (int) ceil($from->diffInHours($to) / 24));

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
        $previousStart = $from->copy()->subDays($days);

        $previousQuery = DB::table('clicks')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->where('links.user_id', $userId)
            ->where('links.is_demo', false)
            ->whereBetween('clicks.created_at', [$previousStart, $from]);

        $previousClicks = $filters->applyDimensions($previousQuery, 'clicks.')->count();

        if ($previousClicks === 0) {
            return null;
        }

        return round((($currentClicks - $previousClicks) * 100) / $previousClicks, 1);
    }

    /** {@inheritDoc} */
    public function getTimeseries(int $userId, AnalyticsFilters $filters): array
    {
        $dateExpr = SqlDateExpr::date('clicks.created_at');

        return $this->baseQuery($userId, $filters)
            ->selectRaw("{$dateExpr} as date, COUNT(*) as clicks")
            ->groupByRaw($dateExpr)
            ->orderByRaw($dateExpr)
            ->get()
            ->map(fn ($r) => ['date' => $r->date, 'clicks' => (int) $r->clicks])
            ->all();
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
}
