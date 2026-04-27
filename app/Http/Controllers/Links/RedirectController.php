<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\Jobs\ProcessLinkClickJob;
use App\Models\Link;
use App\Services\Links\LinkTrackingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;

/**
 * 🚀 CONTROLLER DE REDIRECIONAMENTO - CORAÇÃO DO SISTEMA
 *
 * Fluxos:
 *   - Humanos (não-bot, não-preview): tracking assíncrono via fila + 302 direto para a URL original.
 *   - Bots / scrapers (WhatsApp, Telegram, etc.): HTML com meta-tags Open Graph para preview.
 *   - ?preview=1: HTML de preview sem registrar clique.
 */
class RedirectController extends Controller
{
    private const METADATA_CACHE_TTL_SECONDS = 86400;

    private const BOT_USER_AGENT_PATTERNS = [
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
        'Slackbot-LinkExpanding',
    ];

    public function __construct(
        protected LinkServiceInterface $linkService,
        protected LinkTrackingService $linkTrackingService
    ) {}

    /**
     * 🌐 REDIRECIONAMENTO PÚBLICO
     *
     * Mantém Open Graph para bots e envia humanos direto via 302.
     */
    public function redirect(string $slug, Request $request)
    {
        try {
            $link = Link::findActiveBySlugCached($slug);

            if (! $link) {
                return $this->renderErrorPage('Link não encontrado ou inativo');
            }

            if ($link->expires_at && now()->isAfter($link->expires_at)) {
                return $this->renderErrorPage('Este link expirou e não está mais disponível');
            }

            if ($link->starts_in && now()->isBefore($link->starts_in)) {
                return $this->renderErrorPage('Este link ainda não está disponível');
            }

            $isBot = $this->isBotUserAgent($request->userAgent());
            $isPreview = $request->has('preview');

            if (! $isBot && ! $isPreview) {
                $this->dispatchTracking($link, $request, $slug);

                return redirect()->away($link->original_url, 302)->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }

            $metadata = $this->fetchOriginalMetadata($link->original_url);

            return $this->renderRedirectPage($link, $metadata, $isBot);
        } catch (\Exception $e) {
            Log::error('Redirect Error', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->renderErrorPage('Erro ao processar redirecionamento');
        }
    }

    /**
     * Enfileira o tracking do clique e incrementa o contador denormalizado
     * em `links.clicks` via query direta (sem disparar model events — o cache
     * do Link por slug permanece válido durante picos de clique).
     */
    private function dispatchTracking(Link $link, Request $request, string $slug): void
    {
        try {
            $payload = [
                'ip' => $this->linkTrackingService->resolveRealUserIP($request),
                'user_agent' => $request->userAgent(),
                'referer' => $request->header('referer'),
                'accept_language' => $request->header('Accept-Language'),
                'query_params' => $request->only(LinkTrackingService::UTM_KEYS),
                'start_time' => microtime(true),
            ];

            ProcessLinkClickJob::dispatch($link->id, $payload);

            DB::table('links')->where('id', $link->id)->increment('clicks');
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch click tracking', [
                'slug' => $slug,
                'link_id' => $link->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isBotUserAgent(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return false;
        }

        foreach (self::BOT_USER_AGENT_PATTERNS as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        return $agent->isRobot();
    }

    private function fetchOriginalMetadata(string $url): array
    {
        if (! $this->isSafeFetchUrl($url)) {
            Log::info('Skipping OG fetch for unsafe URL', ['url' => $url]);

            return $this->getDefaultMetadata($url);
        }

        $cacheKey = 'metadata_'.md5($url);

        return Cache::remember($cacheKey, self::METADATA_CACHE_TTL_SECONDS, function () use ($url) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'LinkChart/1.0 (Metadata Fetcher)',
                ])
                    ->connectTimeout(3)
                    ->timeout(5)
                    ->retry(2, 200)
                    ->withOptions([
                        'allow_redirects' => [
                            'max' => 5,
                            'strict' => true,
                            'protocols' => ['http', 'https'],
                        ],
                        'verify' => false,
                    ])
                    ->get($url);

                if (! $response->ok()) {
                    Log::warning('OG fetch returned non-OK status', [
                        'url' => $url,
                        'status' => $response->status(),
                    ]);

                    return $this->getDefaultMetadata($url);
                }

                return $this->parseMetaTags($response->body(), $url);
            } catch (\Throwable $e) {
                Log::warning('Exception fetching metadata', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                return $this->getDefaultMetadata($url);
            }
        });
    }

    /**
     * Proteção básica contra SSRF: rejeita esquemas não-HTTP, hostnames
     * internos (*.local, *.internal, localhost) e IPs privados/loopback literais.
     */
    private function isSafeFetchUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (! $parsed || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parsed['host']);

        if (in_array($host, ['localhost', 'localhost.localdomain', '0.0.0.0'], true)) {
            return false;
        }

        if (preg_match('/\.(local|internal|localhost)$/', $host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return true;
    }

    private function parseMetaTags(string $html, string $url): array
    {
        $metadata = $this->getDefaultMetadata($url);

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $metadata['title'] = html_entity_decode(trim(strip_tags($matches[1])));
        }

        preg_match_all('/<meta\s+property=["\']og:([^"\']+)["\']\s+content=["\']([^"\']+)["\']/i', $html, $ogMatches);
        for ($i = 0; $i < count($ogMatches[0]); $i++) {
            $property = $ogMatches[1][$i];
            $content = $ogMatches[2][$i];
            $metadata['og_'.$property] = html_entity_decode($content);
        }

        preg_match_all('/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:([^"\']+)["\']/i', $html, $ogMatchesAlt);
        for ($i = 0; $i < count($ogMatchesAlt[0]); $i++) {
            $content = $ogMatchesAlt[1][$i];
            $property = $ogMatchesAlt[2][$i];
            if (! isset($metadata['og_'.$property])) {
                $metadata['og_'.$property] = html_entity_decode($content);
            }
        }

        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['description'] = html_entity_decode(trim($matches[1]));
        }

        if (preg_match('/<meta\s+name=["\']twitter:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            if (empty($metadata['og_image'])) {
                $metadata['og_image'] = html_entity_decode($matches[1]);
            }
        }

        $domain = parse_url($url, PHP_URL_HOST);

        if (isset($metadata['title']) && ! empty($metadata['title'])) {
            if ($metadata['og_title'] === $domain) {
                $metadata['og_title'] = $metadata['title'];
            }
        }

        if (isset($metadata['description']) && ! empty($metadata['description'])) {
            if (strpos($metadata['og_description'], 'Clique para acessar') !== false) {
                $metadata['og_description'] = $metadata['description'];
            }
        }

        if (! empty($metadata['og_image'])) {
            if (strpos($metadata['og_image'], '//') === 0) {
                $metadata['og_image'] = 'https:'.$metadata['og_image'];
            } elseif (strpos($metadata['og_image'], '/') === 0) {
                $parsedUrl = parse_url($url);
                $metadata['og_image'] = $parsedUrl['scheme'].'://'.$parsedUrl['host'].$metadata['og_image'];
            }
        }

        return $metadata;
    }

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
            'url' => $url,
        ];
    }

    private function renderRedirectPage(Link $link, array $metadata, bool $isBot): \Illuminate\Http\Response
    {
        $title = e($metadata['og_title'] ?? $metadata['title'] ?? $link->title ?? 'Redirecionando...');
        $description = e($metadata['og_description'] ?? $metadata['description'] ?? 'Aguarde...');
        $image = $metadata['og_image'] ?? null;
        $targetUrl = e($link->original_url);

        $refreshDelay = $isBot ? 5 : 2;

        $imageTag = $this->renderMetaImageTag($image, 'og:image', 'property');
        $twitterImageTag = $this->renderMetaImageTag($image, 'twitter:image', 'name');
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

        setTimeout(function() {
            window.location.href = '{$targetUrl}';
        }, 2000);

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

    private function renderErrorPage(string $message): \Illuminate\Http\Response
    {
        $safeMessage = e($message);
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

    private function renderMetaImageTag(?string $image, string $key, string $attr): string
    {
        if (empty($image)) {
            return '';
        }

        return '<meta '.$attr.'="'.$key.'" content="'.e($image).'">';
    }

    private function truncateUrl(string $url, int $maxLength): string
    {
        if (strlen($url) <= $maxLength) {
            return $url;
        }

        return substr($url, 0, $maxLength).'...';
    }
}
