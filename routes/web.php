<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

Route::get('/', function () {
    return response()->json([
        'message' => 'Link Charts API is running!',
        'version' => '1.0.0',
        'status' => 'active'
    ]);
});

Route::get('/health', function () {
    try {
        // Verificar conexão com banco de dados
        DB::connection()->getPdo();
        $dbStatus = 'connected';

        // Verificar Redis/Cache
        $cacheStatus = 'connected';
        try {
            Cache::put('health_check', 'ok', 10);
            Cache::get('health_check');
        } catch (Exception $e) {
            $cacheStatus = 'disconnected';
        }

        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'services' => [
                'database' => $dbStatus,
                'cache' => $cacheStatus,
                'api' => 'running'
            ],
            'version' => '1.0.0'
        ]);

    } catch (Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'timestamp' => now()->toISOString(),
            'error' => 'Database connection failed',
            'services' => [
                'database' => 'disconnected',
                'cache' => 'unknown',
                'api' => 'running'
            ]
        ], 503);
    }
});
