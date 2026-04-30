<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class SecurityInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $n = DB::table('clicks')
            ->selectRaw('ip, COUNT(*) as c')
            ->where('link_id', $linkId)
            ->groupBy('ip')
            ->havingRaw('COUNT(*) > 50')
            ->get()
            ->count();

        if ($n === 0) {
            return null;
        }

        return [
            'type' => 'security',
            'title' => 'Atividade Suspeita Detectada',
            'description' => "Detectamos {$n} IP(s) com atividade anormalmente alta. Monitore possível tráfego artificial.",
            'priority' => 'high',
            'actionable' => true,
            'confidence' => 0.7,
            'impact_score' => 5,
            'recommendation' => 'Analise os IPs com maior atividade e considere implementar rate limiting.',
        ];
    }
}
