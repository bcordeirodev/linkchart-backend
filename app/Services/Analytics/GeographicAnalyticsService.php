<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

class GeographicAnalyticsService implements \App\Contracts\Analytics\GeographicAnalyticsInterface
{
    public function getLinkGeographicAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);

        if (! Click::where('link_id', $linkId)->exists()) {
            return ['top_countries' => [], 'top_states' => [], 'top_cities' => []];
        }

        return [
            'heatmap_data' => $this->getHeatmapData($linkId),
            'top_countries' => $this->getTopCountriesOptimized($linkId),
            'top_states' => $this->getTopStatesOptimized($linkId),
            'top_cities' => $this->getTopCitiesOptimized($linkId),
        ];
    }

    public function getHeatmapData(int $linkId): array
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
}
