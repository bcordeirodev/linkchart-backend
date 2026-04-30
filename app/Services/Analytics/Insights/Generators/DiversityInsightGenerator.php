<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

class DiversityInsightGenerator implements InsightGeneratorInterface
{
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
