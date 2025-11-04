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
            'ip' => $request->ip(),
            'link_id' => $link->id,
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

    /**
     * 🌐 REDIRECIONAMENTO PÚBLICO COM METADADOS OPEN GRAPH
     *
     * Este método serve HTML com metadados para preview em redes sociais.
     * Detecta bots e usuários para servir conteúdo apropriado.
     *
     * MANTÉM TODAS AS FUNCIONALIDADES DE MÉTRICAS DO MÉTODO handle()
     *
     * @param string $slug
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function redirect(string $slug, Request $request)
    {
        try {
            // Busca o link (mesma lógica do handle())
            $link = \App\Models\Link::where('slug', $slug)
                                  ->where('is_active', true)
                                  ->first();

            if (!$link) {
                return $this->renderErrorPage('Link não encontrado ou inativo');
            }

            // Verifica expiração (mesma lógica do handle())
            if ($link->expires_at && now()->isAfter($link->expires_at)) {
                return $this->renderErrorPage('Este link expirou e não está mais disponível');
            }

            // Verifica starts_in (mesma lógica do handle())
            if ($link->starts_in && now()->isBefore($link->starts_in)) {
                return $this->renderErrorPage('Este link ainda não está disponível');
            }

            // Detecta se é bot/scraper
            $isBot = $this->isBotUserAgent($request->userAgent());
            $isPreview = $request->has('preview');

            // ✅ COLETA DE MÉTRICAS COMPLETAS (MANTÉM TODAS AS FUNCIONALIDADES)
            // Bots não registram clique (apenas fazem preview)
            // Usuários humanos registram clique normalmente
            if (!$isBot && !$isPreview) {
                $this->processMetricsWithFallback($link, $request, $slug);
            }

            // Busca metadados do site original (cache de 24h)
            $metadata = $this->fetchOriginalMetadata($link->original_url);

            // Retorna HTML com metadados Open Graph + redirecionamento
            return $this->renderRedirectPage($link, $metadata, $isBot);

        } catch (\Exception $e) {
            // Log do erro para debugging
            \Log::error('Redirect Error (HTML)', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return $this->renderErrorPage('Erro ao processar redirecionamento');
        }
    }

    /**
     * Detecta se o User-Agent é de um bot/scraper de redes sociais.
     *
     * @param string|null $userAgent
     * @return bool
     */
    private function isBotUserAgent(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return false;
        }

        // Lista completa de bots/scrapers de redes sociais
        $botPatterns = [
            'WhatsApp',
            'Telegram',
            'facebookexternalhit',
            'Facebot',
            'Twitterbot',
            'LinkedInBot',
            'Slackbot',
            'Discordbot',
            'SkypeUriPreview',
            'Google-Structured-Data',
            'bingbot',
            'Googlebot',
            'Pinterest',
            'TelegramBot',
            'Instagrambot',
            'Applebot',
            'Baiduspider',
            'YandexBot',
            'DuckDuckBot',
            'Slackbot-LinkExpanding'
        ];

        foreach ($botPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                \Log::info('🤖 Bot detected', [
                    'user_agent' => $userAgent,
                    'detected_pattern' => $pattern
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Busca metadados do site original com cache inteligente.
     *
     * @param string $url
     * @return array
     */
    private function fetchOriginalMetadata(string $url): array
    {
        $cacheKey = 'metadata_' . md5($url);

        return \Cache::remember($cacheKey, 86400, function () use ($url) {
            try {
                // Timeout de 5 segundos para não travar
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'user_agent' => 'LinkChart/1.0 (Metadata Fetcher)',
                        'follow_location' => true,
                        'max_redirects' => 5
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ]
                ]);

                $html = @file_get_contents($url, false, $context);

                if (!$html) {
                    \Log::warning('Failed to fetch URL for metadata', ['url' => $url]);
                    return $this->getDefaultMetadata($url);
                }

                // Parse HTML para extrair metadados
                $metadata = $this->parseMetaTags($html, $url);

                \Log::info('✅ Metadata fetched successfully', [
                    'url' => $url,
                    'title' => $metadata['title'] ?? 'N/A',
                    'has_image' => !empty($metadata['og_image'])
                ]);

                return $metadata;

            } catch (\Exception $e) {
                \Log::warning('Exception fetching metadata', [
                    'url' => $url,
                    'error' => $e->getMessage()
                ]);

                return $this->getDefaultMetadata($url);
            }
        });
    }

    /**
     * Parse meta tags do HTML para extrair Open Graph e outros metadados.
     *
     * @param string $html
     * @param string $url
     * @return array
     */
    private function parseMetaTags(string $html, string $url): array
    {
        $metadata = $this->getDefaultMetadata($url);

        // Extrair <title>
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $metadata['title'] = html_entity_decode(trim(strip_tags($matches[1])));
        }

        // Extrair Open Graph tags (og:*)
        preg_match_all('/<meta\s+property=["\']og:([^"\']+)["\']\s+content=["\']([^"\']+)["\']/i', $html, $ogMatches);
        for ($i = 0; $i < count($ogMatches[0]); $i++) {
            $property = $ogMatches[1][$i];
            $content = $ogMatches[2][$i];
            $metadata['og_' . $property] = html_entity_decode($content);
        }

        // Também tentar ordem inversa (content antes de property)
        preg_match_all('/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:([^"\']+)["\']/i', $html, $ogMatchesAlt);
        for ($i = 0; $i < count($ogMatchesAlt[0]); $i++) {
            $content = $ogMatchesAlt[1][$i];
            $property = $ogMatchesAlt[2][$i];
            if (!isset($metadata['og_' . $property])) {
                $metadata['og_' . $property] = html_entity_decode($content);
            }
        }

        // Extrair meta description
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['description'] = html_entity_decode(trim($matches[1]));
        }

        // Extrair Twitter Card tags
        if (preg_match('/<meta\s+name=["\']twitter:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            if (empty($metadata['og_image'])) {
                $metadata['og_image'] = html_entity_decode($matches[1]);
            }
        }

        // ✅ PASSO 1: Priorizar valores reais sobre defaults
        $domain = parse_url($url, PHP_URL_HOST);

        // Se og_title é apenas o domínio (default), usar title real se existir
        if (isset($metadata['title']) && !empty($metadata['title'])) {
            if ($metadata['og_title'] === $domain) {
                $metadata['og_title'] = $metadata['title'];
            }
        }

        // Se og_description é o default, usar description real se existir
        if (isset($metadata['description']) && !empty($metadata['description'])) {
            if (strpos($metadata['og_description'], 'Clique para acessar') !== false) {
                $metadata['og_description'] = $metadata['description'];
            }
        }

        // ✅ PASSO 2: Converter URLs de imagem relativas para absolutas
        if (!empty($metadata['og_image'])) {
            // URL sem protocolo: //cdn.example.com/image.png
            if (strpos($metadata['og_image'], '//') === 0) {
                $metadata['og_image'] = 'https:' . $metadata['og_image'];
            }
            // URL relativa: /images/logo.png
            elseif (strpos($metadata['og_image'], '/') === 0) {
                $parsedUrl = parse_url($url);
                $metadata['og_image'] = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $metadata['og_image'];
            }
        }

        return $metadata;
    }

    /**
     * Metadados padrão quando não consegue buscar do site original.
     *
     * @param string $url
     * @return array
     */
    private function getDefaultMetadata(string $url): array
    {
        $domain = parse_url($url, PHP_URL_HOST) ?? 'link';

        return [
            'title' => $domain,
            'description' => "Você será redirecionado para {$domain}",
            'og_title' => $domain,
            'og_description' => "Clique para acessar {$domain}",
            'og_image' => null,
            'og_type' => 'website',
            'url' => $url
        ];
    }

    /**
     * Renderiza página HTML com metadados Open Graph e redirecionamento.
     *
     * @param \App\Models\Link $link
     * @param array $metadata
     * @param bool $isBot
     * @return \Illuminate\Http\Response
     */
    private function renderRedirectPage($link, array $metadata, bool $isBot): \Illuminate\Http\Response
    {
        $title = htmlspecialchars($metadata['og_title'] ?? $metadata['title'] ?? $link->title ?? 'Redirecionando...', ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($metadata['og_description'] ?? $metadata['description'] ?? 'Aguarde...', ENT_QUOTES, 'UTF-8');
        $image = $metadata['og_image'] ?? null;
        $targetUrl = htmlspecialchars($link->original_url, ENT_QUOTES, 'UTF-8');

        // Meta refresh: 2 segundos para usuários (UX melhor), 5 segundos para bots
        $refreshDelay = $isBot ? 5 : 2;

        $imageTag = $this->renderImageTag($image);
        $twitterImageTag = $this->renderTwitterImageTag($image);
        $displayUrl = $this->truncateUrl($targetUrl, 60);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="{$refreshDelay};url={$targetUrl}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{$targetUrl}">
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$description}">
    {$imageTag}

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="{$targetUrl}">
    <meta name="twitter:title" content="{$title}">
    <meta name="twitter:description" content="{$description}">
    {$twitterImageTag}

    <!-- Canonical -->
    <link rel="canonical" href="{$targetUrl}">

    <!-- Metadados adicionais -->
    <meta property="og:site_name" content="LinkChart">
    <meta property="og:locale" content="pt_BR">

    <title>{$title}</title>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #252f3e 0%, #0d121b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        h1 {
            color: rgb(17, 24, 39);
            font-size: 24px;
            margin-bottom: 10px;
            word-wrap: break-word;
            font-weight: 600;
        }
        p {
            color: rgb(107, 114, 128);
            font-size: 16px;
            margin-bottom: 20px;
        }
        .url {
            background: #f6f7f9;
            padding: 15px;
            border-radius: 8px;
            word-break: break-all;
            color: #1976d2;
            font-weight: 600;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid rgba(0, 0, 0, 0.12);
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f6f7f9;
            border-top: 4px solid #1976d2;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .btn {
            display: inline-block;
            background: #1976d2;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            margin-top: 10px;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3);
        }
        .btn:hover {
            background: #0D47A1;
            box-shadow: 0 4px 16px rgba(25, 118, 210, 0.4);
            transform: translateY(-2px);
        }
        .footer {
            margin-top: 20px;
            font-size: 12px;
            color: rgb(107, 114, 128);
        }
        .countdown {
            font-size: 64px;
            font-weight: bold;
            color: #1976d2;
            margin: 10px 0;
            animation: pulse 1s infinite;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🚀</div>
        <h1>{$title}</h1>
        <p>Você será redirecionado automaticamente em:</p>
        <div class="countdown" id="countdown">2</div>
        <div class="url">{$displayUrl}</div>
        <div class="spinner"></div>
        <p style="font-size: 14px; color: #999;">
            Ou clique no botão abaixo:
        </p>
        <a href="{$targetUrl}" class="btn">Ir Agora</a>
        <div class="footer">
            🔗 Powered by LinkChart
        </div>
    </div>

    <script>
        // Contador visual de countdown
        let timeLeft = 2;
        const countdownElement = document.getElementById('countdown');

        const countdownInterval = setInterval(function() {
            timeLeft--;
            if (timeLeft > 0) {
                countdownElement.textContent = timeLeft;
            } else {
                countdownElement.textContent = '•••';
                countdownElement.style.fontSize = '32px';
                clearInterval(countdownInterval);
            }
        }, 1000);

        // Redirecionamento após 2 segundos
        setTimeout(function() {
            window.location.href = '{$targetUrl}';
        }, 2000);

        // Fallback adicional
        setTimeout(function() {
            if (document.visibilityState === 'visible') {
                window.location.replace('{$targetUrl}');
            }
        }, 2500);
    </script>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Renderiza página de erro com design consistente.
     *
     * @param string $message
     * @return \Illuminate\Http\Response
     */
    private function renderErrorPage(string $message): \Illuminate\Http\Response
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link não encontrado</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #252f3e 0%, #0d121b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: shake 0.5s;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        h1 {
            color: rgb(17, 24, 39);
            font-size: 24px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        p {
            color: rgb(107, 114, 128);
            font-size: 16px;
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            background: #1976d2;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(25, 118, 210, 0.3);
        }
        .btn:hover {
            background: #0D47A1;
            box-shadow: 0 4px 16px rgba(25, 118, 210, 0.4);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">❌</div>
        <h1>Oops!</h1>
        <p>{$safeMessage}</p>
        <a href="{$frontendUrl}" class="btn">Voltar à Página Inicial</a>
    </div>
</body>
</html>
HTML;

        return response($html, 404)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Helper para renderizar tag de imagem Open Graph.
     *
     * @param string|null $image
     * @return string
     */
    private function renderImageTag(?string $image): string
    {
        if (empty($image)) {
            return '';
        }

        $safeImage = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
        return "<meta property=\"og:image\" content=\"{$safeImage}\">";
    }

    /**
     * Helper para renderizar tag de imagem do Twitter.
     *
     * @param string|null $image
     * @return string
     */
    private function renderTwitterImageTag(?string $image): string
    {
        if (empty($image)) {
            return '';
        }

        $safeImage = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
        return "<meta name=\"twitter:image\" content=\"{$safeImage}\">";
    }

    /**
     * Trunca URL longa para exibição.
     *
     * @param string $url
     * @param int $maxLength
     * @return string
     */
    private function truncateUrl(string $url, int $maxLength): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength) . '...';
    }
}
