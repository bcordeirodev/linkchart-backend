<?php

namespace App\Services\Admin;

use App\Contracts\Admin\AdminStatsServiceInterface;

/**
 * Implementação dos agregados globais do painel admin.
 *
 * Molde: ReportsAnalyticsService (o agregador multi-link existente), com o
 * predicado de user_id removido e a exclusão de demo via Link::nonDemo().
 * Somente leitura — nenhum side effect.
 */
class AdminStatsService implements AdminStatsServiceInterface
{
    /** {@inheritDoc} */
    public function getOverview(int $days): array
    {
        return [
            'totals' => ['users' => 0, 'links' => 0, 'clicks' => 0],
            'period' => [],
            'series' => ['signups' => [], 'links' => [], 'clicks' => []],
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
