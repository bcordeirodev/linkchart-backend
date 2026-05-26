<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates an insight about the primary country driving link traffic.
 *
 * Always fires when there is at least one geo-located click (country != 'localhost').
 * Priority is 'high' when the top country accounts for more than 50% of clicks.
 */
class GeographicInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a top-country insight, or null if no geo-located clicks exist.
     *
     * The returned array includes both the original Portuguese strings
     * (for backwards compatibility) and i18n keys + params for frontend
     * localisation via react-i18next.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count for percentage calculation.
     * @return array<string, mixed>|null
     */
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $top = DB::table('clicks')
            ->selectRaw('country, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('country')
            ->where('country', '!=', 'localhost')
            ->groupBy('country')
            ->orderBy('clicks', 'desc')
            ->first();

        if (! $top || $totalClicks === 0) {
            return null;
        }

        $pct = round(($top->clicks / $totalClicks) * 100, 1);

        return [
            'type' => 'geographic',
            'title' => 'Mercado Principal',
            'title_key' => 'insights.generators.geographic.mainMarket.title',
            'description' => "O {$top->country} representa {$pct}% dos seus cliques. Considere criar conteúdo específico para este mercado.",
            'description_key' => 'insights.generators.geographic.mainMarket.description',
            'description_params' => ['country' => $top->country, 'pct' => $pct],
            'priority' => $pct > 50 ? 'high' : 'medium',
            'actionable' => true,
            'confidence' => 0.9,
            'impact_score' => 8,
            'recommendation' => 'Crie campanhas direcionadas para este país e considere traduzir o conteúdo.',
            'recommendation_key' => 'insights.generators.geographic.mainMarket.recommendation',
        ];
    }
}
