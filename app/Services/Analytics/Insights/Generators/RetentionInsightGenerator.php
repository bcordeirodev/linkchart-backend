<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

class RetentionInsightGenerator implements InsightGeneratorInterface
{
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

        return [
            'type' => 'retention',
            'title' => 'Taxa de Retenção',
            'description' => "Sua taxa de visitantes recorrentes é {$rate}% ({$bench}). ".($rate >= 25
                ? 'Excelente! Seu conteúdo está gerando lealdade.'
                : 'Considere estratégias para aumentar o retorno de visitantes.'),
            'priority' => $rate < 15 ? 'high' : ($rate >= 25 ? 'low' : 'medium'),
            'actionable' => $rate < 25,
            'confidence' => 0.85,
            'impact_score' => $rate < 15 ? 8 : 5,
            'recommendation' => $rate < 25
                ? 'Implemente newsletters, notificações push ou conteúdo serializado.'
                : 'Continue criando conteúdo de qualidade para manter a lealdade.',
            'data_points' => [
                'total_visitors' => $total,
                'return_visitors' => $return,
                'return_visitor_rate' => $rate,
                'benchmark_comparison' => $bench,
            ],
        ];
    }
}
