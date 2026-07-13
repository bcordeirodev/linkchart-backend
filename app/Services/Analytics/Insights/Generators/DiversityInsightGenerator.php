<?php

namespace App\Services\Analytics\Insights\Generators;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

/**
 * Generates an insight about international geographic reach.
 *
 * Only fires when the link has clicks from more than 5 distinct countries.
 * Priority is 'high' for more than 10 countries.
 */
class DiversityInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a geographic diversity insight, or null if ≤5 countries reached.
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
        $countries = $filters->applyToQuery(
            Click::where('link_id', $linkId)
        )
            ->whereNotNull('country')
            ->where('country', '!=', 'localhost')
            ->distinct('country')
            ->count();

        if ($countries <= 5) {
            return null;
        }

        return [
            'type' => 'geographic',
            'title' => 'Alcance Internacional',
            'title_key' => 'insights.generators.diversity.international.title',
            'description' => "Seu link alcançou {$countries} países diferentes, mostrando potencial global.",
            'description_key' => 'insights.generators.diversity.international.description',
            'description_params' => ['countries' => $countries],
            'priority' => $countries > 10 ? 'high' : 'medium',
            'actionable' => true,
            'confidence' => 0.85,
            'impact_score' => 8,
            'recommendation' => 'Considere expandir para mercados internacionais com maior tráfego.',
            'recommendation_key' => 'insights.generators.diversity.international.recommendation',
        ];
    }
}
