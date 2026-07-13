<?php

namespace App\Services\Analytics\Insights\Generators;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Services\Analytics\Insights\InsightGeneratorInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generates an insight highlighting the dominant device type for a link.
 *
 * Fires when a single device type accounts for any non-zero share of clicks.
 * Priority is 'high' when one device exceeds 70% of total clicks.
 */
class DeviceInsightGenerator implements InsightGeneratorInterface
{
    /**
     * Returns a device dominance insight, or null if no device data is present.
     *
     * The returned array includes both the original Portuguese strings
     * (for backwards compatibility) and i18n keys + params for frontend
     * localisation via react-i18next.
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Total click count for the link, already filtered.
     * @param  AnalyticsFilters  $filters  Active filter state, applied to the query below.
     * @return array<string, mixed>|null
     */
    public function generate(int $linkId, int $totalClicks, AnalyticsFilters $filters): ?array
    {
        $top = $filters->applyToQuery(
            DB::table('clicks')->where('link_id', $linkId)
        )
            ->selectRaw('device, COUNT(*) as clicks')
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
            'title_key' => 'insights.generators.device.dominant.title',
            'description' => "{$top->device} representa {$pct}% dos acessos.",
            'description_key' => 'insights.generators.device.dominant.description',
            'description_params' => ['device' => $top->device, 'pct' => $pct],
            'priority' => $pct > 70 ? 'high' : 'medium',
            'actionable' => true,
            'confidence' => 0.9,
            'impact_score' => 7,
            'recommendation' => "Otimize sua página de destino para {$top->device}.",
            'recommendation_key' => 'insights.generators.device.dominant.recommendation',
            'recommendation_params' => ['device' => $top->device],
        ];
    }
}
