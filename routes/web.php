<?php

use App\Http\Controllers\Bio\PublicBioController;
use App\Http\Controllers\Email\DigestUnsubscribeController;
use App\Http\Controllers\Links\RedirectController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * GET / — was a raw closure returning the static "API is running" welcome
 * JSON, host-agnostic (Laravel routes match by path only, unless ->domain()
 * is used — this route matched the root domain AND every subdomain root
 * identically, since none of them carry a domain constraint).
 *
 * Now delegates to PublicBioController::redirectFromSubdomainRoot(), which
 * reproduces that exact payload for every case except one new one: a
 * subdomain host with an ACTIVE bio page associated with it
 * (bio_pages.subdomain_id) now 302s to the frontend bio page instead. See
 * that method's docblock for the full behavior matrix and
 * tests/Feature/Subdomain/SubdomainRootTest.php for the characterization +
 * new-behavior coverage. resolve.subdomain resolves the Host header the
 * same way it already does for /{slug} and /r/{slug} below; throttle:redirect
 * is the same 600/min-per-IP limiter already protecting those routes — this
 * root path previously had no rate limit at all.
 */
Route::middleware(['resolve.subdomain', 'throttle:redirect'])
    ->get('/', [PublicBioController::class, 'redirectFromSubdomainRoot']);

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
 * Opt-out do digest semanal (link assinado no rodapé do e-mail — LGPD).
 * `signed` valida a assinatura gerada por URL::signedRoute; sem auth, um
 * clique na caixa de entrada tem que funcionar. Caminho multi-segmento, então
 * o catch-all /{slug} (um segmento) lá embaixo nunca o sombreia.
 */
Route::get('/email/digest/unsubscribe/{user}', DigestUnsubscribeController::class)
    ->middleware('signed')
    ->name('digest.unsubscribe');

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
// Clean URL alias: redirect.linkcharts.com.br/{slug} (no /r/ prefix)
// NEXT_PUBLIC_REDIRECT_URL is set without /r/ in production, so frontend-generated
// short URLs use this path. Must be last to avoid shadowing other routes.
// Unlock de link protegido por senha. Rota web (grupo web => sessão + CSRF do
// ValidateCsrfToken padrão) com throttle próprio, mais restrito que o do
// redirect: 10/min por IP+slug contra brute-force de senha. Não usa
// metrics.redirect — as métricas de redirect medem o hot path GET; o clique só
// é contado (job + increment) quando o unlock é bem-sucedido.
Route::post('/r/{slug}/unlock', [RedirectController::class, 'unlock'])
    ->middleware(['resolve.subdomain', 'throttle:redirect-unlock'])
    ->name('public.redirect.unlock');

Route::middleware(['resolve.subdomain', 'throttle:redirect', 'metrics.redirect'])
    ->group(function () {
        Route::get('/r/{slug}', [RedirectController::class, 'redirect'])
            ->name('public.redirect');

        Route::get('/{slug}', [RedirectController::class, 'redirect'])
            ->name('public.redirect.clean')
            ->where('slug', '[^/]+');
    });
