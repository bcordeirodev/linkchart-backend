<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ChartService;

class ChartController
{
    protected ChartService $chartService;

    public function __construct(ChartService $chartService)
    {
        $this->chartService = $chartService;
    }

    public function index(Request $request)
    {
        // Pega o ID do usuário autenticado por padrão
        $userId = $request->query('user_id') ?? auth()->guard('api')->id();
        $linkId = $request->query('link_id');

        try {
            $charts = $this->chartService->getAllCharts($userId, $linkId);
            return response()->json($charts);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao buscar dados analíticos.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
