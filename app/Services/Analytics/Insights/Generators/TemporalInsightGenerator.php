<?php

namespace App\Services\Analytics\Insights\Generators;

use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates an insight identifying the peak hour of click activity.
 *
 * Uses the pre-computed hour_of_day column (Phase 1) with a fallback to
 * EXTRACT/strftime for older clicks. Always fires when at least one click exists.
 */
class TemporalInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a peak-hour insight, or null if no clicks exist.
     *
     * The returned array includes both the original Portuguese strings
     * (for backwards compatibility) and i18n keys + params for frontend
     * localisation via react-i18next.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count (unused; kept for interface compatibility).
     * @return array<string, mixed>|null
     */
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
            'title_key' => 'insights.generators.temporal.peakHour.title',
            'description' => "A maioria dos cliques ocorre às {$peak->hour}h.",
            'description_key' => 'insights.generators.temporal.peakHour.description',
            'description_params' => ['hour' => $peak->hour],
            'priority' => 'medium',
            'actionable' => true,
            'confidence' => 0.85,
            'impact_score' => 6,
            'recommendation' => "Publique conteúdo às {$peak->hour}h para maximizar alcance.",
            'recommendation_key' => 'insights.generators.temporal.peakHour.recommendation',
            'recommendation_params' => ['hour' => $peak->hour],
        ];
    }
}
