<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

/**
 * Detects IPs with abnormally high click volume for a link.
 *
 * Queries for IPs with more than 50 clicks on the same link. Only fires
 * when at least one such IP is found. Useful for identifying potential
 * bot or click-fraud activity without requiring the full quality scoring pipeline.
 */
class SecurityInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a suspicious activity insight, or null if no IP exceeds 50 clicks.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @return array<string, mixed>|null
     */
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
