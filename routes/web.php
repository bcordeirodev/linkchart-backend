<?php

use App\Http\Controllers\Links\RedirectController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Link Charts API is running!',
        'version' => '1.0.0',
        'status' => 'active',
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
                'api' => 'running',
            ],
            'version' => '1.0.0',
        ]);

    } catch (Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'timestamp' => now()->toISOString(),
            'error' => 'Database connection failed',
            'services' => [
                'database' => 'disconnected',
                'cache' => 'unknown',
                'api' => 'running',
            ],
        ], 503);
    }
});

/**
 * 🌐 ROTA DE REDIRECIONAMENTO PÚBLICO COM METADADOS
 *
 * Esta rota serve HTML com metadados Open Graph para preview em redes sociais
 * e redireciona usuários para o link original.
 *
 * Funcionalidades:
 * - Detecta bots (WhatsApp, Telegram, etc.) e serve metadados apropriados
 * - Redireciona usuários humanos instantaneamente
 * - Mantém TODAS as métricas e tracking do sistema
 * - Cache inteligente de metadados
 */
Route::get('/r/{slug}', [RedirectController::class, 'redirect'])
    ->middleware(['throttle:redirect', 'metrics.redirect'])
    ->name('public.redirect');
