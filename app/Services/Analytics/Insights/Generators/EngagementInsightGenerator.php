<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

/**
 * Generates an insight about week-over-week click growth or decline.
 *
 * Compares clicks in the last 7 days against the preceding 7 days. Only
 * fires when the previous period had at least one click and the change
 * exceeds ±20%. Priority is 'high' when the absolute change exceeds 50%.
 */
class EngagementInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a traffic trend insight, or null if previous period has no data
     * or the change is within ±20%.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @return array<string, mixed>|null
     */
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $recent = Click::where('link_id', $linkId)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $old = Click::where('link_id', $linkId)
            ->where('created_at', '<', now()->subDays(7))
            ->where('created_at', '>=', now()->subDays(14))
            ->count();

        if ($old === 0) {
            return null;
        }

        $rate = (($recent - $old) / $old) * 100;

        if (abs($rate) <= 20) {
            return null;
        }

        return [
            'type' => 'engagement',
            'title' => $rate > 0 ? 'Crescimento Acelerado' : 'Declínio no Engajamento',
            'description' => $rate > 0
                ? "Seus cliques cresceram {$rate}% na última semana. Continue com a estratégia atual!"
                : 'Seus cliques diminuíram '.abs($rate).'% na última semana. Revise sua estratégia de conteúdo.',
            'priority' => abs($rate) > 50 ? 'high' : 'medium',
            'actionable' => $rate < 0,
            'confidence' => 0.8,
            'impact_score' => abs($rate) > 50 ? 9 : 6,
            'recommendation' => $rate > 0
                ? 'Analise o que funcionou bem e replique a estratégia.'
                : 'Revise o conteúdo, timing e canais de distribuição.',
        ];
    }
}
