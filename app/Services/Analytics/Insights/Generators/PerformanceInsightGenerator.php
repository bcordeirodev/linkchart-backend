<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class PerformanceInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $avg = (float) DB::table('clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('response_time')
            ->avg('response_time');

        if ($avg <= 0) {
            return null;
        }

        $slow = $avg > 500;

        return [
            'type' => 'performance',
            'title' => $slow ? 'Velocidade de Resposta Lenta' : 'Boa Performance de Resposta',
            'description' => "Tempo médio de resposta: {$avg}ms.",
            'priority' => $slow ? 'high' : 'low',
            'actionable' => $slow,
            'confidence' => 0.8,
            'impact_score' => $slow ? 7 : 3,
            'recommendation' => $slow ? 'Otimize sua infraestrutura.' : 'Continue monitorando.',
        ];
    }
}
