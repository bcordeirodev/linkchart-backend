<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

class GeographicAnalyticsService implements \App\Contracts\Analytics\GeographicAnalyticsInterface
{
    private const CONTINENT_NAMES = [
        'NA' => 'América do Norte',
        'SA' => 'América do Sul',
        'EU' => 'Europa',
        'AS' => 'Ásia',
        'AF' => 'África',
        'OC' => 'Oceania',
        'AN' => 'Antártica',
    ];

    public function getLinkGeographicAnalytics(int $linkId): array
    {
        $link = Link::findOrFail($linkId);

        if (! Click::where('link_id', $linkId)->exists()) {
            return [
                'data' => [
                    'heatmap_data'  => [],
                    'top_countries' => [],
                    'top_states'    => [],
                    'top_cities'    => [],
                    'continents'    => [],
                ],
                'meta' => $this->buildGeographicMeta($link, []),
            ];
        }

        $heatmap = $this->getHeatmapData($linkId);

        return [
            'data' => [
                'heatmap_data'  => $heatmap,
                'top_countries' => $this->getTopCountriesOptimized($linkId),
                'top_states'    => $this->getTopStatesOptimized($linkId),
                'top_cities'    => $this->getTopCitiesOptimized($linkId),
                'continents'    => $this->getTopContinents($linkId),
            ],
            'meta' => $this->buildGeographicMeta($link, $heatmap),
        ];
    }

    private function getHeatmapData(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone, COUNT(*) as clicks, MAX(created_at) as last_click')
            ->where('link_id', $linkId)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNotNull('country')->where('country', '!=', 'localhost')->where('country', '!=', '')
            ->groupBy('latitude', 'longitude', 'city', 'country', 'iso_code', 'currency', 'state_name', 'continent', 'timezone')
            ->orderBy('clicks', 'desc')->get()
            ->map(fn ($r) => [
                'lat' => (float) $r->latitude,
                'lng' => (float) $r->longitude,
                'city' => $r->city ?: 'Cidade Desconhecida',
                'country' => $r->country,
                'clicks' => (int) $r->clicks,
                'iso_code' => $r->iso_code,
                'currency' => $r->currency,
                'state_name' => $r->state_name,
                'continent' => $r->continent,
                'timezone' => $r->timezone,
                'last_click' => $r->last_click,
            ])
            ->toArray();
    }

    private function getTopCountriesOptimized(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('country, iso_code, currency, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->groupBy('country', 'iso_code', 'currency')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['country' => $r->country, 'iso_code' => $r->iso_code, 'clicks' => (int) $r->clicks, 'currency' => $r->currency])
            ->toArray();
    }

    private function getTopStatesOptimized(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('country, state, state_name, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('state')
            ->groupBy('country', 'state', 'state_name')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['country' => $r->country, 'state' => $r->state, 'state_name' => $r->state_name, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getTopCitiesOptimized(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('city, country, state, COUNT(*) as clicks')
            ->where('link_id', $linkId)->whereNotNull('city')
            ->groupBy('city', 'country', 'state')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn ($r) => ['city' => $r->city, 'country' => $r->country, 'state' => $r->state, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getTopContinents(int $linkId): array
    {
        $results = DB::table('clicks')
            ->selectRaw('continent, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('continent')
            ->where('continent', '!=', '')
            ->groupBy('continent')
            ->orderByDesc('clicks')
            ->get();

        $total = $results->sum('clicks');

        return $results->map(function ($row) use ($total) {
            return [
                'continent'      => $row->continent,
                'continent_name' => self::CONTINENT_NAMES[$row->continent] ?? $row->continent,
                'clicks'         => (int) $row->clicks,
                'percentage'     => $total > 0
                    ? round(($row->clicks / $total) * 100, 1)
                    : 0.0,
            ];
        })->values()->toArray();
    }

    private function buildGeographicMeta(Link $link, array $heatmap): array
    {
        $countries = array_filter(array_column($heatmap, 'country'));
        $states    = array_filter(array_column($heatmap, 'state_name'));
        $cities    = array_filter(array_column($heatmap, 'city'));
        $clicks    = array_column($heatmap, 'clicks');

        return [
            'total_clicks'      => array_sum($clicks),
            'unique_countries'  => count(array_unique($countries)),
            'unique_states'     => count(array_unique($states)),
            'unique_cities'     => count(array_unique($cities)),
            'max_clicks'        => $clicks ? max($clicks) : 0,
            'total_locations'   => count($heatmap),
            'last_updated'      => now()->toISOString(),
            'link_info'         => $this->linkInfo($link),
        ];
    }

    private function linkInfo(Link $link): array
    {
        return [
            'id'        => $link->id,
            'title'     => $link->title,
            'short_url' => $link->getShortedUrl(),
            'is_active' => $link->is_active,
        ];
    }
}
