<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

/**
 * Generates an insight about visitor retention rate.
 *
 * Computes the percentage of distinct IPs flagged as return visitors
 * (is_return_visitor=true). Benchmarks: ≥40% excellent, ≥25% good,
 * ≥15% average, <15% needs improvement. Always fires when at least
 * one distinct visitor exists. The return shape includes an extra
 * data_points key not present in other generators.
 */
class RetentionInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a retention rate insight, or null if no visitor data exists.
     *
     * The returned array includes both the original Portuguese strings
     * (for backwards compatibility) and i18n keys + params for frontend
     * localisation via react-i18next. The benchmark label is passed as a
     * separate `bench_key` so the frontend can translate it independently.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @return array<string, mixed>|null Includes extra key: data_points (array with retention breakdown).
     */
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $total = Click::where('link_id', $linkId)->distinct('ip')->count('ip');

        if ($total === 0) {
            return null;
        }

        $return = Click::where('link_id', $linkId)
            ->where('is_return_visitor', true)
            ->distinct('ip')
            ->count('ip');

        $rate = round(($return / $total) * 100, 1);
        $bench = $rate >= 25 ? 'acima da média' : ($rate >= 15 ? 'na média' : 'abaixo da média');

        // i18n key for the benchmark label so the frontend can translate it
        $benchKey = $rate >= 25
            ? 'insights.generators.retention.benchmark.above'
            : ($rate >= 15 ? 'insights.generators.retention.benchmark.average' : 'insights.generators.retention.benchmark.below');

        $excellent = $rate >= 25;

        return [
            'type' => 'retention',
            'title' => 'Taxa de Retenção',
            'title_key' => 'insights.generators.retention.rate.title',
            'description' => "Sua taxa de visitantes recorrentes é {$rate}% ({$bench}). ".($excellent
                ? 'Excelente! Seu conteúdo está gerando lealdade.'
                : 'Considere estratégias para aumentar o retorno de visitantes.'),
            'description_key' => $excellent
                ? 'insights.generators.retention.excellent.description'
                : 'insights.generators.retention.low.description',
            'description_params' => ['rate' => $rate, 'bench' => $bench, 'bench_key' => $benchKey],
            'priority' => $rate < 15 ? 'high' : ($rate >= 25 ? 'low' : 'medium'),
            'actionable' => $rate < 25,
            'confidence' => 0.85,
            'impact_score' => $rate < 15 ? 8 : 5,
            'recommendation' => $rate < 25
                ? 'Implemente newsletters, notificações push ou conteúdo serializado.'
                : 'Continue criando conteúdo de qualidade para manter a lealdade.',
            'recommendation_key' => $excellent
                ? 'insights.generators.retention.excellent.recommendation'
                : 'insights.generators.retention.low.recommendation',
            'data_points' => [
                'total_visitors' => $total,
                'return_visitors' => $return,
                'return_visitor_rate' => $rate,
                'benchmark_comparison' => $bench,
                'benchmark_key' => $benchKey,
            ],
        ];
    }
}
