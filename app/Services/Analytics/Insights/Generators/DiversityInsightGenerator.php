<?php

namespace App\Services\Analytics\Insights\Generators;

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
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @return array<string, mixed>|null
     */
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $countries = Click::where('link_id', $linkId)
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
            'description' => "Seu link alcançou {$countries} países diferentes, mostrando potencial global.",
            'priority' => $countries > 10 ? 'high' : 'medium',
            'actionable' => true,
            'confidence' => 0.85,
            'impact_score' => 8,
            'recommendation' => 'Considere expandir para mercados internacionais com maior tráfego.',
        ];
    }
}
