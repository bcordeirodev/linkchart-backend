<?php

use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Links\LinkController;
use App\Http\Controllers\Links\PublicLinkController;
use Illuminate\Support\Facades\Route;

/**
 * 🚀 ROTA PÚBLICA DE REDIRECIONAMENTO - CORAÇÃO DO SISTEMA
 *
 * FUNCIONALIDADE:
 * - Recebe requisição AJAX/fetch do frontend com headers CORS
 * - Coleta TODAS as métricas possíveis do navegador (User-Agent, IP, Referer, etc.)
 * - Retorna JSON com URL original para frontend redirecionar
 * - Middleware específico para coleta completa de dados
 */
/**
 * ⚠️ ROTA DESABILITADA - MIGRADA PARA WEB
 *
 * Esta rota foi movida para routes/web.php para suportar:
 * - Preview de metadados em redes sociais (Open Graph)
 * - Redirecionamento direto sem passar pelo front-end
 * - Tracking completo de cliques
 *
 * Nova rota: Route::get('/r/{slug}', [RedirectController::class, 'redirect'])
 * Local: routes/web.php
 *
 * Data da migração: 04/11/2025
 */
// Route::middleware(['metrics.redirect'])
//     ->get('/r/{slug}', [RedirectController::class, 'handle']);

/**
 * ==============================
 * ROTAS PÚBLICAS DE ENCURTAMENTO
 * ==============================
 * Endpoints para encurtamento de URLs sem autenticação
 */
Route::prefix('public')->controller(PublicLinkController::class)->group(function () {
    Route::post('/shorten', 'store')->middleware('throttle:public-shorten'); // ✅ NOVO: Encurtamento público
    Route::get('/link/{slug}', 'showBySlug');                   // ✅ NOVO: Informações básicas do link
    Route::get('/analytics/{slug}', 'basicAnalytics')->middleware('throttle:public-analytics'); // ✅ NOVO: Analytics básicos públicos
});

/**
 * ==============================
 * ROTAS DE AUTENTICAÇÃO
 * ==============================
 * Endpoints usados pelo front-end para autenticação de usuários
 */
Route::prefix('auth')->middleware('throttle:login')->controller(AuthController::class)->group(function () {
    Route::post('/login', 'login');
    Route::post('/register', 'register');
    Route::post('/google', 'googleLogin');

    // === VERIFICAÇÃO DE EMAIL ===
    Route::post('/verify-email', 'verifyEmail');
    Route::post('/forgot-password', 'forgotPassword');
    Route::post('/reset-password', 'resetPassword');
});

/**
 * ==============================
 * ROTAS PROTEGIDAS POR JWT
 * ==============================
 * Todas as rotas abaixo requerem autenticação via JWT
 */
Route::middleware(['api.auth:api'])->group(function () {
    // === AUTENTICAÇÃO E PERFIL (SEM VERIFICAÇÃO DE EMAIL) ===
    Route::get('/me', [AuthController::class, 'me']);                    // ✅ USADO: AuthService.getMe()
    Route::post('/logout', [AuthController::class, 'logout']);           // ✅ USADO: AuthService.signOut()

    // === VERIFICAÇÃO DE EMAIL (AUTENTICADO) ===
    Route::get('/email-verification-status', [AuthController::class, 'checkEmailVerificationStatus']); // ✅ NOVO: Status de verificação
    Route::post('/resend-verification-email', [AuthController::class, 'resendVerificationEmail']);      // ✅ NOVO: Reenviar email
});

/**
 * ==============================
 * ROTAS QUE REQUEREM EMAIL VERIFICADO
 * ==============================
 * Todas as rotas abaixo requerem autenticação via JWT E email verificado
 */
Route::middleware(['api.auth:api', 'verified'])->group(function () {
    // === PERFIL (REQUER EMAIL VERIFICADO) ===
    Route::put('/profile', [AuthController::class, 'updateProfile']);    // ✅ USADO: AuthService.updateProfile()
    Route::put('/change-password', [AuthController::class, 'changePassword']); // ✅ NOVO: Alterar senha

    // === GERENCIAMENTO DE LINKS (RESTful API) ===
    Route::prefix('links')->controller(LinkController::class)->group(function () {
        Route::get('/', 'index');                                        // ✅ USADO: LinkService.all()
        Route::post('/', 'store');                                       // ✅ USADO: LinkService.save()
        Route::get('/{id}', 'show')->where('id', '[0-9]+');            // ✅ USADO: LinkService.findOne()
        Route::put('/{id}', 'update')->where('id', '[0-9]+');          // ✅ USADO: LinkService.update()
        Route::delete('/{id}', 'destroy')->where('id', '[0-9]+');      // ✅ USADO: LinkService.remove()
        Route::get('/{id}/analytics', 'analyticsByLinkId')->where('id', '[0-9]+'); // ✅ USADO: LinkService.getAnalytics()
    });

    // === META-DADOS DE LINKS (sparkline, trend, preview, health) ===
    Route::prefix('links')->controller(\App\Http\Controllers\Links\LinkMetaController::class)->group(function () {
        Route::post('/batch-meta', 'batchMeta');
        Route::get('/{id}/sparkline', 'sparkline')->where('id', '[0-9]+');
        Route::get('/{id}/trend', 'trend')->where('id', '[0-9]+');
        Route::get('/{id}/preview', 'preview')->where('id', '[0-9]+');
        Route::get('/{id}/health', 'health')->where('id', '[0-9]+');
    });

    // === DADOS DETALHADOS DE LINKS ===
    Route::prefix('link')->controller(LinkController::class)->group(function () {
        Route::get('/{id}/clicks', 'getClicksData')->where('id', '[0-9]+'); // ✅ USADO: LinkClicksRealTime component
    });

    // === ANALYTICS POR LINK ===
    Route::prefix('analytics/link')->controller(AnalyticsController::class)->group(function () {
        Route::get('/{linkId}/dashboard', 'getLinkDashboardData')->where('linkId', '[0-9]+');     // ✅ NOVO: useDashboardData (linkMode)
        Route::get('/{linkId}/comprehensive', 'getLinkAnalytics')->where('linkId', '[0-9]+');       // ✅ USADO: useEnhancedAnalytics
        Route::get('/{linkId}/heatmap', 'getHeatmapData')->where('linkId', '[0-9]+');             // ✅ USADO: useHeatmapData
        Route::get('/{linkId}/geographic', 'getGeographicAnalytics')->where('linkId', '[0-9]+');  // ✅ USADO: useGeographicData
        Route::get('/{linkId}/insights', 'getBusinessInsights')->where('linkId', '[0-9]+');       // ✅ USADO: useInsightsData
        Route::get('/{linkId}/temporal', 'getTemporalAnalytics')->where('linkId', '[0-9]+');      // ✅ USADO: useTemporalData
        Route::get('/{linkId}/audience', 'getAudienceAnalytics')->where('linkId', '[0-9]+');      // ✅ USADO: useAudienceData
    });
});
