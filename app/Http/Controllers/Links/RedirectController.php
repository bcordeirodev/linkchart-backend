<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\Services\Links\LinkTrackingService;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;

/**
 * 🚀 CONTROLLER DE REDIRECIONAMENTO - CORAÇÃO DO SISTEMA
 *
 * FUNCIONALIDADE COMPLETA: Mantém todas as métricas e funcionalidades
 * SOLUÇÃO: Bypass de middlewares problemáticos, mas mantém coleta completa
 */
class RedirectController extends Controller
{
    public function __construct(
        protected LinkServiceInterface $linkService,
        protected LinkTrackingService $linkTrackingService
    ) {}

    /**
     * 🚀 CORAÇÃO DO SISTEMA: Processa link e retorna URL para redirecionamento
     * FLUXO: Frontend → Backend (coleta métricas) → Frontend (redireciona)
     *
     * @param string $slug
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(string $slug, Request $request)
    {
        try {
            // Busca o link antes de processar
            $link = \App\Models\Link::where('slug', $slug)
                                  ->where('is_active', true)
                                  ->first();

            if (!$link) {
                return response()->json([
                    'success' => false,
                    'error' => 'Link não encontrado',
                    'message' => 'O link solicitado não foi encontrado ou está inativo.'
                ], 404);
            }

            // Verifica se o link não expirou
            if ($link->expires_at && now()->isAfter($link->expires_at)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Link expirado',
                    'message' => 'Este link expirou e não está mais disponível.'
                ], 404);
            }

            // Verifica se já pode ser usado (starts_in)
            if ($link->starts_in && now()->isBefore($link->starts_in)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Link não disponível',
                    'message' => 'Este link ainda não está disponível.'
                ], 404);
            }

            // Verifica se é modo preview (não registra clique)
            $isPreview = $request->has('preview') || $request->header('X-Preview-Mode') === 'true';

            // COLETA DE MÉTRICAS COMPLETAS
            if (!$isPreview) {
                $this->processMetricsWithFallback($link, $request, $slug);
            }

            // RETORNA O ESSENCIAL PARA REDIRECIONAMENTO + TÍTULO
            return response()->json([
                'success' => true,
                'redirect_url' => $link->original_url,
                'title' => $link->title,
                'slug' => $link->slug
            ]);

        } catch (\Exception $e) {
            // Log do erro para debugging
            \Log::error('RedirectController Error', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erro interno do servidor',
                'message' => 'Erro interno do servidor. Verifique os logs para mais detalhes.'
            ], 500);
        }
    }

    /**
     * Processa métricas com sistema de fallback ultra-robusto.
     * GARANTIA: Este método NUNCA lança exceções que possam quebrar o redirecionamento.
     *
     * @param \App\Models\Link $link
     * @param Request $request
     * @param string $slug
     * @return void
     */
    private function processMetricsWithFallback($link, Request $request, string $slug): void
    {
        $metricsContext = [
            'slug' => $slug,
            'link_id' => $link->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->headers->get('referer'),
            'timestamp' => now()->toISOString(),
        ];

        // NÍVEL 1: Tentativa principal de tracking
        $trackingSuccess = $this->attemptTracking($link, $request, $metricsContext);

        // NÍVEL 2: Incremento de cliques (sempre tenta, mesmo se tracking falhar)
        $clickIncrementSuccess = $this->attemptClickIncrement($link, $metricsContext);

        // NÍVEL 3: Log de métricas (sempre executa, independente dos anteriores)
        $this->logMetricsResult($trackingSuccess, $clickIncrementSuccess, $metricsContext);

        // NÍVEL 4: Fallback de emergência se tudo falhar
        if (!$trackingSuccess && !$clickIncrementSuccess) {
            $this->emergencyMetricsFallback($link, $metricsContext);
        }
    }

    /**
     * Tentativa de tracking com isolamento total de erros.
     *
     * @param \App\Models\Link $link
     * @param Request $request
     * @param array $context
     * @return bool
     */
    private function attemptTracking($link, Request $request, array $context): bool
    {
        try {
            $this->linkTrackingService->registrarClique($link, $request);

            // Log de sucesso detalhado
            \Log::info('✅ Tracking successful', array_merge($context, [
                'tracking_service' => 'LinkTrackingService',
                'status' => 'success'
            ]));

            return true;
        } catch (\Throwable $e) {
            // Log detalhado do erro, mas NÃO propaga exceção
            \Log::error('❌ Tracking service failed', array_merge($context, [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'tracking_service' => 'LinkTrackingService',
                'status' => 'failed',
                'fallback_required' => true
            ]));

            return false;
        }
    }

    /**
     * Tentativa de incremento de cliques com isolamento de erros.
     *
     * @param \App\Models\Link $link
     * @param array $context
     * @return bool
     */
    private function attemptClickIncrement($link, array $context): bool
    {
        try {
            $link->increment('clicks');

            \Log::info('✅ Click increment successful', array_merge($context, [
                'new_click_count' => $link->fresh()->clicks,
                'operation' => 'click_increment',
                'status' => 'success'
            ]));

            return true;
        } catch (\Throwable $e) {
            // Tenta fallback com query direta
            try {
                \DB::table('links')
                    ->where('id', $link->id)
                    ->increment('clicks');

                \Log::warning('⚠️ Click increment via fallback', array_merge($context, [
                    'error_message' => $e->getMessage(),
                    'fallback_method' => 'direct_db_query',
                    'status' => 'success_via_fallback'
                ]));

                return true;
            } catch (\Throwable $fallbackError) {
                \Log::error('❌ Click increment failed completely', array_merge($context, [
                    'primary_error' => $e->getMessage(),
                    'fallback_error' => $fallbackError->getMessage(),
                    'operation' => 'click_increment',
                    'status' => 'failed'
                ]));

                return false;
            }
        }
    }

    /**
     * Log consolidado do resultado das métricas.
     *
     * @param bool $trackingSuccess
     * @param bool $clickIncrementSuccess
     * @param array $context
     * @return void
     */
    private function logMetricsResult(bool $trackingSuccess, bool $clickIncrementSuccess, array $context): void
    {
        try {
            $status = 'success';
            $icon = '✅';

            if (!$trackingSuccess && !$clickIncrementSuccess) {
                $status = 'total_failure';
                $icon = '🚨';
            } elseif (!$trackingSuccess || !$clickIncrementSuccess) {
                $status = 'partial_failure';
                $icon = '⚠️';
            }

            $logData = array_merge($context, [
                'metrics_summary' => [
                    'tracking_success' => $trackingSuccess,
                    'click_increment_success' => $clickIncrementSuccess,
                    'overall_status' => $status
                ],
                'redirect_status' => 'proceeding_normally'
            ]);

            if ($status === 'success') {
                \Log::info("$icon Redirect metrics completed successfully", $logData);
            } else {
                \Log::warning("$icon Redirect metrics had issues but redirect proceeding", $logData);
            }
        } catch (\Throwable $e) {
            // Se até o log falhar, usa error_log como último recurso
            error_log("LinkChart: Metrics logging failed for slug {$context['slug']} - " . $e->getMessage());
        }
    }

    /**
     * Fallback de emergência quando tudo mais falha.
     *
     * @param \App\Models\Link $link
     * @param array $context
     * @return void
     */
    private function emergencyMetricsFallback($link, array $context): void
    {
        try {
            // Tenta salvar métricas básicas em arquivo como último recurso
            $emergencyData = [
                'timestamp' => $context['timestamp'],
                'link_id' => $link->id,
                'slug' => $context['slug'],
                'ip' => $context['ip'],
                'user_agent' => substr($context['user_agent'] ?? '', 0, 200),
                'status' => 'emergency_fallback'
            ];

            $logFile = storage_path('logs/emergency-metrics.log');
            file_put_contents($logFile, json_encode($emergencyData) . "\n", FILE_APPEND | LOCK_EX);

            \Log::critical('🆘 Emergency metrics fallback activated', array_merge($context, [
                'fallback_method' => 'file_storage',
                'emergency_file' => $logFile,
                'message' => 'All primary metrics systems failed, using emergency fallback'
            ]));
        } catch (\Throwable $e) {
            // Se até o fallback de emergência falhar, apenas registra no error_log
            error_log("LinkChart CRITICAL: All metrics systems failed for slug {$context['slug']} - " . $e->getMessage());
        }
    }
}
