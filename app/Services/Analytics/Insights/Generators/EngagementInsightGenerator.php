<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Models\Click;
use App\Services\Analytics\Insights\InsightGeneratorInterface;

class EngagementInsightGenerator implements InsightGeneratorInterface
{
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
                : 'Seus cliques diminuíram ' . abs($rate) . '% na última semana. Revise sua estratégia de conteúdo.',
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
