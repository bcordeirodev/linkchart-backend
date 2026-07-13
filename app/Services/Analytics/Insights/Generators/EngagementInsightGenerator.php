<?php

namespace App\Services\Analytics\Insights\Generators;

use App\DTOs\Analytics\AnalyticsFilters;
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
     * The returned array includes both the original Portuguese strings
     * (for backwards compatibility) and i18n keys + params for frontend
     * localisation via react-i18next.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @param  AnalyticsFilters  $filters  Active filter state. Only dimensions (country/device/
     *                                     channel/continent) and bot exclusion are applied via
     *                                     applyDimensions() — this generator owns its own 7d vs
     *                                     previous-7d window, so date_from/date_to must NOT be
     *                                     layered on top or the comparison loses its meaning.
     * @return array<string, mixed>|null
     */
    public function generate(int $linkId, int $totalClicks, AnalyticsFilters $filters): ?array
    {
        $recent = $filters->applyDimensions(
            Click::where('link_id', $linkId)
        )
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $old = $filters->applyDimensions(
            Click::where('link_id', $linkId)
        )
            ->where('created_at', '<', now()->subDays(7))
            ->where('created_at', '>=', now()->subDays(14))
            ->count();

        if ($old === 0) {
            return null;
        }

        $rate = round((($recent - $old) / $old) * 100, 1);

        if (abs($rate) <= 20) {
            return null;
        }

        $positive = $rate > 0;

        return [
            'type' => 'engagement',
            'title' => $positive ? 'Crescimento Acelerado' : 'Declínio no Engajamento',
            'title_key' => $positive
                ? 'insights.generators.engagement.positiveGrowth.title'
                : 'insights.generators.engagement.negativeGrowth.title',
            'description' => $positive
                ? "Seus cliques cresceram {$rate}% na última semana. Continue com a estratégia atual!"
                : 'Seus cliques diminuíram '.abs($rate).'% na última semana. Revise sua estratégia de conteúdo.',
            'description_key' => $positive
                ? 'insights.generators.engagement.positiveGrowth.description'
                : 'insights.generators.engagement.negativeGrowth.description',
            'description_params' => ['rate' => abs($rate)],
            'priority' => abs($rate) > 50 ? 'high' : 'medium',
            'actionable' => $rate < 0,
            'confidence' => 0.8,
            'impact_score' => abs($rate) > 50 ? 9 : 6,
            'recommendation' => $positive
                ? 'Analise o que funcionou bem e replique a estratégia.'
                : 'Revise o conteúdo, timing e canais de distribuição.',
            'recommendation_key' => $positive
                ? 'insights.generators.engagement.positiveGrowth.recommendation'
                : 'insights.generators.engagement.negativeGrowth.recommendation',
        ];
    }
}
