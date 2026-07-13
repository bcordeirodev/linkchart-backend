<?php

namespace App\Services\Analytics\Insights\Generators;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates an insight about redirect response time performance.
 *
 * Only fires when at least one click has a recorded response_time value.
 * Priority is 'high' and actionable=true when average response time exceeds 500ms.
 */
class PerformanceInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a response time performance insight, or null if no response_time data exists.
     *
     * The returned array includes both the original Portuguese strings
     * (for backwards compatibility) and i18n keys + params for frontend
     * localisation via react-i18next.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @param  AnalyticsFilters  $filters  Active filter state, applied to the query below.
     * @return array<string, mixed>|null
     */
    public function generate(int $linkId, int $totalClicks, AnalyticsFilters $filters): ?array
    {
        $avg = (float) $filters->applyToQuery(
            DB::table('clicks')->where('link_id', $linkId)
        )
            ->whereNotNull('response_time')
            ->avg('response_time');

        if ($avg <= 0) {
            return null;
        }

        $slow = $avg > 500;
        $avgRounded = round($avg, 1);

        return [
            'type' => 'performance',
            'title' => $slow ? 'Velocidade de Resposta Lenta' : 'Boa Performance de Resposta',
            'title_key' => $slow
                ? 'insights.generators.performance.slow.title'
                : 'insights.generators.performance.good.title',
            'description' => "Tempo médio de resposta: {$avgRounded}ms.",
            'description_key' => $slow
                ? 'insights.generators.performance.slow.description'
                : 'insights.generators.performance.good.description',
            'description_params' => ['avg' => $avgRounded],
            'priority' => $slow ? 'high' : 'low',
            'actionable' => $slow,
            'confidence' => 0.8,
            'impact_score' => $slow ? 7 : 3,
            'recommendation' => $slow ? 'Otimize sua infraestrutura.' : 'Continue monitorando.',
            'recommendation_key' => $slow
                ? 'insights.generators.performance.slow.recommendation'
                : 'insights.generators.performance.good.recommendation',
        ];
    }
}
