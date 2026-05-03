<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class GeographicInsightGenerator implements InsightGeneratorInterface
{
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
            'description' => "O {$top->country} representa {$pct}% dos seus cliques. Considere criar conteúdo específico para este mercado.",
            'priority' => $pct > 50 ? 'high' : 'medium',
            'actionable' => true,
            'confidence' => 0.9,
            'impact_score' => 8,
            'recommendation' => 'Crie campanhas direcionadas para este país e considere traduzir o conteúdo.',
        ];
    }
}
