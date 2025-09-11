<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Link Charts API is running!',
        'version' => '1.0.0',
        'status' => 'active'
    ]);
});

// Health check endpoint for deployment monitoring
Route::get('/health', function () {
    try {
        // Test database connection
        \DB::connection()->getPdo();
        
        return response()->json([
            'status' => 'healthy',
            'message' => 'All systems operational',
            'timestamp' => now()->toISOString(),
            'services' => [
                'database' => 'connected',
                'application' => 'running'
            ]
        ], 200);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'message' => 'Service degraded',
            'error' => $e->getMessage(),
            'timestamp' => now()->toISOString()
        ], 503);
    }
});
