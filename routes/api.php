<?php

use App\Http\Controllers\ChartController;
use App\Http\Controllers\RateLimitController;
use App\Http\Controllers\GeographicAnalyticsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\RedirectController;
use App\Http\Controllers\WordController;
use App\Http\Controllers\AuthController;

// Rota pública de redirecionamento - ÚNICA com rate limiting e coleta de métricas específicas
// SISTEMA ROBUSTO: Métricas nunca impedem redirecionamento
Route::middleware(['metrics.redirect', 'rate.limit.advanced:link.redirect'])
    ->get('/r/{slug}', [RedirectController::class, 'handle']);

// ROTA TEMPORÁRIA DE TESTE - SEM MIDDLEWARES
Route::get('/r-test/{slug}', [RedirectController::class, 'handle']);

// Rota pública de preview - SEM rate limiting (apenas informação)
Route::get('/link/by-slug/{slug}', [LinkController::class, 'showBySlug']);

// ==============================
// ROTAS DE AUTENTICAÇÃO
// ==============================
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('/login', 'login');
    Route::post('/register', 'register');
    Route::post('/google', 'googleLogin');
});

// ==============================
// ROTAS PROTEGIDAS POR JWT
// ==============================
Route::middleware(['api.auth:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/analytics', [ChartController::class, 'index']);

    // === MÉTRICAS UNIFICADAS (NOVO) ===
    Route::prefix('metrics')->controller(\App\Http\Controllers\UnifiedMetricsController::class)->group(function () {
        Route::get('/dashboard', 'getDashboardMetrics');           // GET /metrics/dashboard
        Route::get('/category/{category}', 'getMetricsByCategory'); // GET /metrics/category/performance
        Route::get('/link/{linkId}', 'getLinkMetrics');            // GET /metrics/link/123
        Route::get('/compare', 'compareMetrics');                  // GET /metrics/compare?current=24&previous=48
        Route::delete('/cache', 'clearCache');                     // DELETE /metrics/cache
    });

    // === ENDPOINTS LEGADOS (DEPRECATED) ===
    // TODO: Migrar front-end para usar /metrics/dashboard
    // Route::get('/redirect-dashboard', [\App\Http\Controllers\RedirectDashboardController::class, 'dashboard']); // REMOVIDO - controller deletado

    // ❌ REMOVIDO: Use /metrics/dashboard ou /metrics/category/performance

    // Validação de consistência de dados (seguro - filtrado por usuário)
    Route::get('/data-validation', function() {
        $userId = auth()->id();
        $service = app(\App\Services\RedirectAnalyticsService::class);
        return response()->json($service->validateDataConsistencyForUser($userId));
    });

    // Rate Limiting Management (apenas endpoints essenciais)
    Route::prefix('rate-limit')->withoutMiddleware([\App\Http\Middleware\AdvancedRateLimit::class])
        ->controller(RateLimitController::class)->group(function () {
        // ❌ REMOVIDOS: dashboard, status, performance - Use /metrics/* endpoints

        Route::get('/config', 'rateLimitConfig');       // Manter para configurações
        Route::get('/violations', 'violations');        // Manter para segurança

        // Admin only routes (would need admin middleware)
        Route::post('/reset-user', 'resetUserLimits'); // ->middleware('admin')
    });

    // Links - SEM rate limiting (apenas gerenciamento administrativo)
    Route::prefix('gerar-url')->controller(LinkController::class)->group(function () {
        Route::post('/', 'store'); // Criação de links SEM rate limiting
    });

    // Links Routes (RESTful API)
    Route::prefix('links')->controller(LinkController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{id}', 'show')->where('id', '[0-9]+');
        Route::put('/{id}', 'update')->where('id', '[0-9]+');
        Route::delete('/{id}', 'destroy')->where('id', '[0-9]+');
        Route::get('/{id}/analytics', 'analyticsByLinkId')->where('id', '[0-9]+');
    });
});

// Rotas adicionais para LinkController (protegidas por autenticação) - SINGULAR
Route::middleware(['api.auth:api'])->prefix('link')->controller(LinkController::class)->group(function () {
    Route::get('/{id}/audit', 'auditHistory')->where('id', '[0-9]+'); // Histórico de auditoria por ID
    Route::get('/{slug}/analytics', 'analytics')->where('slug', '[a-zA-Z0-9\-_]+'); // Analytics por slug
    Route::get('/{id}/clicks', 'getClicksData')->where('id', '[0-9]+'); // Dados de cliques detalhados
});

// Enhanced Analytics Routes (protegidas) - ADICIONADO AGORA
Route::middleware(['api.auth:api'])->prefix('analytics/link')->controller(\App\Http\Controllers\EnhancedAnalyticsController::class)->group(function () {
    // Analytics básicos
    Route::get('/{linkId}/comprehensive', 'getLinkAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/heatmap', 'getHeatmapData')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/geographic', 'getGeographicAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/insights', 'getBusinessInsights')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/temporal', 'getTemporalAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/audience', 'getAudienceAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/summary', 'getExecutiveSummary')->where('linkId', '[0-9]+');

    // Analytics avançados - NOVOS
    Route::get('/{linkId}/browser', 'getBrowserAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/referrer', 'getRefererAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/temporal-advanced', 'getAdvancedTemporalAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/engagement', 'getEngagementAnalytics')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/performance-region', 'getPerformanceByRegion')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/traffic-quality', 'getTrafficQualityReport')->where('linkId', '[0-9]+');
});

// Relatórios executivos (protegidos)
Route::middleware(['api.auth:api'])->prefix('reports/link')->controller(\App\Http\Controllers\AnalyticsReportController::class)->group(function () {
    Route::get('/{linkId}/executive', 'getExecutiveReport')->where('linkId', '[0-9]+');
    Route::get('/{linkId}/dashboard', 'getDashboardData')->where('linkId', '[0-9]+');
});

// ==============================
// ROTAS DE LOGGING E DIAGNÓSTICO
// ==============================
Route::middleware(['api.auth:api'])->prefix('logs')->controller(\App\Http\Controllers\LogController::class)->group(function () {
    Route::get('/', 'listLogs');                    // GET /logs - Lista arquivos de log
    Route::get('/recent-errors', 'getRecentErrors'); // GET /logs/recent-errors - Erros recentes
    Route::get('/diagnostic', 'systemDiagnostic');   // GET /logs/diagnostic - Diagnóstico completo
    Route::post('/test', 'testLogging');             // POST /logs/test - Testar sistema de logs
    Route::get('/{filename}', 'readLog');            // GET /logs/{filename} - Ler arquivo específico
});

// Rota de teste completo para analytics (temporária)
Route::get('/test-analytics/{linkId}', function($linkId) {
    $service = app(\App\Services\EnhancedLinkAnalyticsService::class);
    $analytics = $service->getComprehensiveLinkAnalytics($linkId);

    return response()->json([
        'success' => true,
        'has_data' => $analytics['overview']['total_clicks'] > 0,
        'link_info' => $analytics['link_info'],
        'overview' => $analytics['overview'],
        'geographic' => $analytics['geographic'],
        'temporal' => $analytics['temporal'],
        'audience' => $analytics['audience'],
        'insights' => $analytics['insights'],
    ]);
})->where('linkId', '[0-9]+');
