<?php

use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\OnboardingController;
use App\Http\Controllers\Links\LinkController;
use App\Http\Controllers\Links\PublicLinkController;
use App\Http\Controllers\Links\TagController;
use App\Http\Controllers\Reports\ReportsController;
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
    Route::get('/link/{slug}', 'showBySlug')->middleware('throttle:60,1');                   // ✅ NOVO: Informações básicas do link
    Route::get('/links/suggest-slug', 'suggestSlug')->middleware('throttle:suggest-slug');   // Slug disponível resolvido server-side
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

    // === VERIFICAÇÃO DE EMAIL ===
    Route::post('/verify-email', 'verifyEmail');
    Route::post('/forgot-password', 'forgotPassword');
    Route::post('/reset-password', 'resetPassword');
});

// Auth0 token exchange — separate from login throttle; has its own limiter.
Route::post('/auth/auth0-exchange', [AuthController::class, 'auth0Exchange'])
    ->middleware('throttle:auth0-exchange');

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
    Route::post('/resend-verification-email', [AuthController::class, 'resendVerificationEmail'])
        ->middleware('throttle:resend-verification');      // ✅ NOVO: Reenviar email (rate-limited)

    // === ONBOARDING (marca tour/dicas como já vistos, por conta) ===
    Route::post('/onboarding/seen', [OnboardingController::class, 'seen']);

    // === EXCLUSÃO DE CONTA (LGPD — permitida mesmo sem email verificado) ===
    Route::delete('/account', [AccountController::class, 'destroy']);
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
    Route::get('/profile/stats', [AuthController::class, 'stats']);      // ✅ NOVO: Stats do perfil

    // === GERENCIAMENTO DE LINKS (RESTful API) ===
    Route::prefix('links')->controller(LinkController::class)->group(function () {
        Route::get('/', 'index');                                        // ✅ USADO: LinkService.all()
        Route::post('/', 'store');                                       // ✅ USADO: LinkService.save()
        // Must stay registered before the /{id} wildcard below — "bulk-action"
        // is not numeric so the [0-9]+ constraint would not collide, but the
        // explicit ordering documents the invariant for future routes.
        Route::post('/bulk-action', 'bulkAction');                       // ✅ NOVO: ações em massa (ativar/desativar/excluir)
        Route::get('/{id}', 'show')->where('id', '[0-9]+');            // ✅ USADO: LinkService.findOne()
        Route::put('/{id}', 'update')->where('id', '[0-9]+');          // ✅ USADO: LinkService.update()
        Route::delete('/{id}', 'destroy')->where('id', '[0-9]+');      // ✅ USADO: LinkService.remove()
        Route::get('/{id}/analytics', [AnalyticsController::class, 'getLinkSummaryAnalytics'])->where('id', '[0-9]+'); // moved to AnalyticsController
    });

    // === GERENCIAMENTO DE TAGS (RESTful API) ===
    Route::prefix('tags')->controller(TagController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{id}', 'update')->where('id', '[0-9]+');
        Route::delete('/{id}', 'destroy')->where('id', '[0-9]+');
    });

    // === META-DADOS DE LINKS (sparkline, trend, preview, health, url-meta) ===
    Route::prefix('links')->controller(\App\Http\Controllers\Links\LinkMetaController::class)->group(function () {
        Route::get('/url-meta', 'urlMeta')->middleware('throttle:url-meta');
        Route::post('/batch-meta', 'batchMeta');
        Route::get('/{id}/sparkline', 'sparkline')->where('id', '[0-9]+');
        Route::get('/{id}/trend', 'trend')->where('id', '[0-9]+');
        Route::get('/{id}/preview', 'preview')->where('id', '[0-9]+');
        Route::get('/{id}/health', 'health')->where('id', '[0-9]+');
    });

    // === DADOS DETALHADOS DE LINKS ===
    Route::prefix('link')->controller(LinkController::class)->group(function () {
        Route::get('/{id}/clicks-list', 'getClicksList')->where('id', '[0-9]+'); // ✅ USADO: ClicksTable tab em LinkAnalyticsTabs
    });

    // === ANALYTICS POR LINK ===
    Route::prefix('analytics/link')->controller(AnalyticsController::class)->group(function () {
        Route::get('/{linkId}/dashboard', 'getLinkDashboardData')->where('linkId', '[0-9]+');     // ✅ NOVO: useDashboardData (linkMode)
        Route::get('/{linkId}/comprehensive', 'getLinkAnalytics')->where('linkId', '[0-9]+');       // ✅ USADO: useEnhancedAnalytics
        Route::get('/{linkId}/geographic', 'getGeographicAnalytics')->where('linkId', '[0-9]+');  // ✅ USADO: useGeographicData
        Route::get('/{linkId}/insights', 'getBusinessInsights')->where('linkId', '[0-9]+');       // ✅ USADO: useInsightsData
        Route::get('/{linkId}/temporal', 'getTemporalAnalytics')->where('linkId', '[0-9]+');      // ✅ USADO: useTemporalData
        Route::get('/{linkId}/audience', 'getAudienceAnalytics')->where('linkId', '[0-9]+');      // ✅ USADO: useAudienceData
    });

    // === RELATÓRIOS AGREGADOS MULTI-LINK ===
    Route::prefix('reports')->controller(ReportsController::class)->group(function () {
        Route::get('/summary', 'summary');
        Route::get('/timeseries', 'timeseries');
        Route::get('/top-links', 'topLinks');
        Route::get('/breakdown', 'breakdown');
        Route::get('/export/clicks', 'exportClicks');
    });

    // === GERENCIAMENTO DE SUBDOMÍNIO(S) ===
    // Plural (múltiplos por usuário) — API atual, consumida pelo frontend.
    Route::get('/subdomains', [\App\Http\Controllers\Subdomain\SubdomainController::class, 'index']);
    Route::post('/subdomains', [\App\Http\Controllers\Subdomain\SubdomainController::class, 'store'])
        ->middleware('throttle:subdomain-claim');
    Route::delete('/subdomains/{id}', [\App\Http\Controllers\Subdomain\SubdomainController::class, 'destroy'])
        ->whereNumber('id');

    // Singulares — @deprecated, mantidos por um release (compat com frontend antigo
    // durante o deploy blue/green). check must be registered before the bare GET
    // /subdomain to avoid route collision.
    Route::get('/subdomain/check', [\App\Http\Controllers\Subdomain\SubdomainController::class, 'check']);
    Route::get('/subdomain', [\App\Http\Controllers\Subdomain\SubdomainController::class, 'show']);
    Route::post('/subdomain', [\App\Http\Controllers\Subdomain\SubdomainController::class, 'claim'])
        ->middleware('throttle:subdomain-claim');
    Route::delete('/subdomain', [\App\Http\Controllers\Subdomain\SubdomainController::class, 'release']);
});
