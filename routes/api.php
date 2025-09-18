<?php

use App\Http\Controllers\Analytics\ChartController;
use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Analytics\MetricsController;
use App\Http\Controllers\Links\LinkController;
use App\Http\Controllers\Links\PublicLinkController;
use App\Http\Controllers\Links\RedirectController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EmailTestController;
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
Route::middleware(['metrics.redirect'])
    ->get('/r/{slug}', [RedirectController::class, 'handle']);

/**
 * ==============================
 * ROTAS PÚBLICAS DE ENCURTAMENTO
 * ==============================
 * Endpoints para encurtamento de URLs sem autenticação
 */
Route::prefix('public')->controller(PublicLinkController::class)->group(function () {
    Route::post('/shorten', 'store');                           // ✅ NOVO: Encurtamento público
    Route::get('/link/{slug}', 'showBySlug');                   // ✅ NOVO: Informações básicas do link
    Route::get('/analytics/{slug}', 'basicAnalytics');          // ✅ NOVO: Analytics básicos públicos
});

/**
 * ==============================
 * TESTE PÚBLICO DE EMAIL (TEMPORÁRIO)
 * ==============================
 */
Route::prefix('public/email-test')->controller(EmailTestController::class)->group(function () {
    Route::get('/config', 'testConfiguration');                      // ✅ TESTE: Verificar configuração SendGrid
    Route::post('/sendgrid-api', 'sendTestViaSendGridAPI');          // ✅ TESTE: Enviar via SendGrid API
});

/**
 * ==============================
 * ROTAS DE AUTENTICAÇÃO
 * ==============================
 * Endpoints usados pelo front-end para autenticação de usuários
 */
Route::prefix('auth')->controller(AuthController::class)->group(function () {
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

    // === ANALYTICS LEGADOS ===
    Route::get('/analytics', [ChartController::class, 'index']);         // ✅ USADO: useDashboardData hook

    // === MÉTRICAS ===
    Route::prefix('metrics')->controller(MetricsController::class)->group(function () {
        Route::get('/dashboard', 'getDashboardMetrics');                 // ✅ USADO: useDashboardData hook
    });


    // === CRIAÇÃO DE LINKS (LEGACY) ===
    Route::prefix('gerar-url')->controller(LinkController::class)->group(function () {
        Route::post('/', 'store');                      // ✅ USADO: LinkService.createShortUrl()
    });

    // === GERENCIAMENTO DE LINKS (RESTful API) ===
    Route::prefix('links')->controller(LinkController::class)->group(function () {
        Route::get('/', 'index');                                        // ✅ USADO: LinkService.all()
        Route::post('/', 'store');                                       // ✅ USADO: LinkService.save()
        Route::get('/{id}', 'show')->where('id', '[0-9]+');            // ✅ USADO: LinkService.findOne()
        Route::put('/{id}', 'update')->where('id', '[0-9]+');          // ✅ USADO: LinkService.update()
        Route::delete('/{id}', 'destroy')->where('id', '[0-9]+');      // ✅ USADO: LinkService.remove()
        Route::get('/{id}/analytics', 'analyticsByLinkId')->where('id', '[0-9]+'); // ✅ USADO: LinkService.getAnalytics()
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
        Route::get('/{linkId}/temporal-advanced', 'getAdvancedTemporalAnalytics')->where('linkId', '[0-9]+'); // ✅ USADO: useTemporalData
        Route::get('/{linkId}/audience', 'getAudienceAnalytics')->where('linkId', '[0-9]+');      // ✅ USADO: useAudienceData
    });

    // === ANALYTICS GLOBAIS ===
    Route::prefix('analytics/global')->controller(AnalyticsController::class)->group(function () {
        Route::get('/dashboard', 'getGlobalDashboardData');     // ✅ NOVO: useDashboardData (globalMode)
        Route::get('/heatmap', 'getGlobalHeatmapData');         // ✅ USADO: useHeatmapData (globalMode)
        Route::get('/geographic', 'getGlobalGeographicData');   // ✅ USADO: useGeographicData (globalMode)
        Route::get('/temporal', 'getGlobalTemporalData');       // ✅ USADO: useTemporalData (globalMode)
        Route::get('/insights', 'getGlobalInsightsData');       // ✅ USADO: useInsightsData (globalMode)
        Route::get('/performance', 'getGlobalPerformanceData'); // ✅ NOVO: useLinkPerformance hook
        Route::get('/audience', 'getGlobalAudienceData');       // ✅ USADO: useAudienceData (globalMode)
    });

    // === TESTE DE EMAIL (DESENVOLVIMENTO/DEBUG) ===
    Route::prefix('email-test')->controller(EmailTestController::class)->group(function () {
        Route::get('/config', 'testConfiguration');                      // ✅ NOVO: Verificar configuração de email
        Route::post('/send', 'sendTest');                               // ✅ NOVO: Enviar email de teste (SendGrid API + SMTP fallback)
        Route::post('/sendgrid-api', 'sendTestViaSendGridAPI');          // ✅ NOVO: Enviar email via SendGrid API especificamente
        Route::post('/custom', 'sendCustom');                           // ✅ NOVO: Enviar email personalizado
    });
});
