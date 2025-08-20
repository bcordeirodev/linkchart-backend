<?php

namespace App\Http\Controllers;

use App\Services\GeographicAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeographicAnalyticsController
{
    public function __construct(
        private GeographicAnalyticsService $geographicAnalyticsService
    ) {}

    /**
     * Obtém estatísticas básicas por país
     */
    public function getCountryStats(Request $request): JsonResponse
    {
        $linkId = $request->query('link_id');

        $stats = $this->geographicAnalyticsService->getCountryStats($linkId);

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Obtém estatísticas básicas por estado
     */
    public function getStateStats(Request $request): JsonResponse
    {
        $linkId = $request->query('link_id');

        $stats = $this->geographicAnalyticsService->getStateStats(null, $linkId);

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
