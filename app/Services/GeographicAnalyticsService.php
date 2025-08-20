<?php

namespace App\Services;

use App\Models\Click;

/**
 * Serviço para análises geográficas básicas - MVP
 */
class GeographicAnalyticsService
{
    /**
     * Obtém estatísticas por país
     */
    public function getCountryStats(int $linkId = null): array
    {
        $query = Click::select('country', 'iso_code')
            ->selectRaw('COUNT(*) as clicks')
            ->whereNotNull('country')
            ->where('country', '!=', 'localhost')
            ->groupBy('country', 'iso_code')
            ->orderBy('clicks', 'desc');

        if ($linkId) {
            $query->where('link_id', $linkId);
        }

        return $query->get()->toArray();
    }

    /**
     * Obtém estatísticas por estado/região
     */
    public function getStateStats(string $countryCode = null, int $linkId = null): array
    {
        $query = Click::select('country', 'state', 'state_name')
            ->selectRaw('COUNT(*) as clicks')
            ->whereNotNull('state')
            ->groupBy('country', 'state', 'state_name')
            ->orderBy('clicks', 'desc');

        if ($countryCode) {
            $query->where('iso_code', $countryCode);
        }

        if ($linkId) {
            $query->where('link_id', $linkId);
        }

        return $query->get()->toArray();
    }
}
