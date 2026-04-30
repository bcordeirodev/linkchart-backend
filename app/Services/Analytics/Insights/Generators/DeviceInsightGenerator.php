<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class DeviceInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $top = DB::table('clicks')
            ->selectRaw('device, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('device')
            ->groupBy('device')
            ->orderBy('clicks', 'desc')
            ->first();

        if (! $top || $totalClicks === 0) {
            return null;
        }

        $pct = round(($top->clicks / $totalClicks) * 100, 1);

        return [
            'type' => 'audience',
            'title' => 'Dispositivo Dominante',
            'description' => "{$top->device} representa {$pct}% dos acessos.",
            'priority' => $pct > 70 ? 'high' : 'medium',
            'actionable' => true,
            'confidence' => 0.9,
            'impact_score' => 7,
            'recommendation' => "Otimize sua página de destino para {$top->device}.",
        ];
    }
}
