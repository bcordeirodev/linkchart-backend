<?php

namespace App\Services\Analytics\Insights\Generators;

use App\DTOs\Analytics\AnalyticsFilters;
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
     * The returned array includes both the original Portuguese strings
     * (for backwards compatibility) and i18n keys + params for frontend
     * localisation via react-i18next.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @param  AnalyticsFilters  $filters  Active filter state, applied to the query below. The
     *                                     `HAVING COUNT(*) > 50` counts within the filtered window,
     *                                     which is intentional: an IP with 60 clicks over a year is
     *                                     not an anomaly for today's window.
     * @return array<string, mixed>|null
     */
    public function generate(int $linkId, int $totalClicks, AnalyticsFilters $filters): ?array
    {
        $n = $filters->applyToQuery(
            DB::table('clicks')->where('link_id', $linkId)
        )
            ->selectRaw('ip, COUNT(*) as c')
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
            'title_key' => 'insights.generators.security.suspicious.title',
            'description' => "Detectamos {$n} IP(s) com atividade anormalmente alta. Monitore possível tráfego artificial.",
            'description_key' => 'insights.generators.security.suspicious.description',
            'description_params' => ['count' => $n],
            'priority' => 'high',
            'actionable' => true,
            'confidence' => 0.7,
            'impact_score' => 5,
            'recommendation' => 'Analise os IPs com maior atividade e considere implementar rate limiting.',
            'recommendation_key' => 'insights.generators.security.suspicious.recommendation',
        ];
    }
}
