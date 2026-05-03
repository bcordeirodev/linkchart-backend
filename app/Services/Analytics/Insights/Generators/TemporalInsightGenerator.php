<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

class TemporalInsightGenerator implements InsightGeneratorInterface
{
    public function generate(int $linkId, int $totalClicks): ?array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $expr = $sqlite
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $peak = DB::table('clicks')
            ->selectRaw("{$expr} as hour, COUNT(*) as clicks")
            ->where('link_id', $linkId)
            ->groupByRaw($expr)
            ->orderBy('clicks', 'desc')
            ->first();

        if (! $peak) {
            return null;
        }

        return [
            'type' => 'temporal',
            'title' => 'Horário de Pico',
            'description' => "A maioria dos cliques ocorre às {$peak->hour}h.",
            'priority' => 'medium',
            'actionable' => true,
            'confidence' => 0.85,
            'impact_score' => 6,
            'recommendation' => "Publique conteúdo às {$peak->hour}h para maximizar alcance.",
        ];
    }
}
