<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\Jobs\ProcessLinkClickJob;
use App\Logging\AppLogger;
use App\Logging\Context\RequestContext;
use App\Models\Link;
use App\Services\Links\LinkTrackingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jenssegers\Agent\Agent;

/**
 * Public redirect controller — the performance-critical heart of the system.
 *
 * Served by TWO routes in routes/web.php (both use throttle:redirect +
 * metrics.redirect middlewares):
 *   GET /r/{slug}  (named public.redirect)              — original path
 *   GET /{slug}    (named public.redirect.clean)        — clean-URL alias used
 *                    in production via NEXT_PUBLIC_REDIRECT_URL without /r/ prefix
 *
 * Three execution branches in redirect():
 *   1. Human visitor (not a bot, no ?preview=1):
 *      - Dispatches ProcessLinkClickJob with full tracking payload (resolved IP,
 *        UA, UTM, Sec-Fetch headers, client hints, server timing).
 *      - Returns an immediate 302 to the original URL with no-cache headers.
 *      - The denormalised links.clicks counter is NOT incremented here; it is
 *        incremented inside the job by LinkTrackingService.
 *
 *   2. Bot / social scraper (WhatsApp, Telegram, Twitterbot, Googlebot, etc.):
 *      - Fetches and caches OG metadata from the original URL (TTL 24 h).
 *      - Renders an HTML page with Open Graph + Twitter Card meta-tags so
 *        social previews display correctly. No click is recorded.
 *
 *   3. ?preview=1 query param:
 *      - Same HTML rendering path as bots, but intended for human preview.
 *      - No click is recorded.
 *
 * Cache invariant: Link::findActiveBySlugCached() uses a 10-minute TTL and is
 * invalidated by Link's saved/deleted model events. Do not bypass this cache.
 *
 * CRITICAL: Any change to this controller, its job, its model, or its routes
 * must be verified against RedirectTest and ProcessLinkClickJobTest before merge.
 * The AJAX /api/r/{slug} route was disabled in 04/11/2025 and must not be
 * reopened without a documented reason.
 */
class RedirectController extends Controller
{
    private const METADATA_CACHE_TTL_SECONDS = 86400;

    /**
     * Standard social-crawler User-Agent used when fetching OG metadata.
     *
     * Most platforms (YouTube, LinkedIn, Twitter, etc.) serve their full
     * Open Graph tags in response to recognised social-crawler UAs.
     * A custom non-standard UA typically results in a stripped or consent
     * page with no real metadata.
     */
    private const METADATA_FETCH_UA = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

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
     * GET /r/{slug}  (public.redirect)
     * GET /{slug}    (public.redirect.clean)
     *
     * Resolve a slug to an active link and route the request to one of three
     * branches: human redirect (302), bot/preview OG HTML page, or error page.
     *
     * Middleware (both routes): throttle:redirect (600/min per IP), metrics.redirect
     * Auth: not required
     * Owner check: no (public endpoint)
     *
     * Response shape:
     *   Human visitor: 302 redirect to original_url with no-cache headers
     *   Bot / ?preview=1: 200 HTML with OG meta-tags (Content-Type: text/html)
     *   Not found / expired / click-limit: 404 HTML error page
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function redirect(string $slug, Request $request)
    {
        try {
            $link = Link::findActiveBySlugCached($slug);

            if (! $link) {
                AppLogger::redirectBlocked($slug, 'not_found');

                return $this->renderErrorPage('Link não encontrado ou inativo');
            }

            AppLogger::redirectStarted($slug, $link->id);

            // Subdomain context: null = root domain, false = unregistered subdomain, UserSubdomain = registered
            $subdomainCtx = $request->attributes->get('subdomain_context', null);

            if ($subdomainCtx === false) {
                AppLogger::redirectBlocked($slug, 'subdomain_not_found');

                return $this->renderErrorPage('Link não encontrado ou inativo');
            }

            if ($subdomainCtx !== null && $link->user_id !== $subdomainCtx->user_id) {
                AppLogger::redirectBlocked($slug, 'subdomain_ownership_mismatch');

                return $this->renderErrorPage('Link não encontrado ou inativo');
            }

            if ($link->expires_at && now()->isAfter($link->expires_at)) {
                AppLogger::redirectBlocked($slug, 'expired');

                return $this->renderErrorPage('Este link expirou e não está mais disponível');
            }

            if ($link->starts_in && now()->isBefore($link->starts_in)) {
                AppLogger::redirectBlocked($slug, 'not_started');

                return $this->renderErrorPage('Este link ainda não está disponível');
            }

            if ($link->hasReachedClickLimit()) {
                AppLogger::redirectBlocked($slug, 'click_limit');

                return $this->renderErrorPage('Este link atingiu o limite de cliques');
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
            AppLogger::redirectError($slug, $e);

            return $this->renderErrorPage('Erro ao processar redirecionamento');
        }
    }

    /**
     * Enqueue ProcessLinkClickJob with the full tracking payload (resolved IP,
     * UA, referer, UTM, Sec-Fetch metadata, client hints). Returns immediately.
     *
     * The denormalised links.clicks counter is incremented later by
     * LinkTrackingService::registrarCliqueFromPayload running inside the job,
     * not by this method. Keeping the increment in the job avoids blocking
     * the HTTP response on a DB write.
     *
     * On exception: logs via AppLogger::redirectError and swallows. Tracking
     * is best-effort by design — losing a click is preferable to delaying
     * the redirect.
     */
    private function dispatchTracking(Link $link, Request $request, string $slug): void
    {
        try {
            $payload = [
                'request_id' => RequestContext::current()?->requestId,
                'ip' => $this->linkTrackingService->resolveRealUserIP($request),
                'user_agent' => $request->userAgent(),
                'referer' => $request->header('referer'),
                'accept_language' => $request->header('Accept-Language'),
                'query_params' => $request->only(LinkTrackingService::UTM_KEYS),
                'http_response_ms' => round((microtime(true) - (defined('LARAVEL_START') ? LARAVEL_START : microtime(true))) * 1000, 2),
                'sec_fetch_site' => $request->header('Sec-Fetch-Site'),
                'sec_fetch_mode' => $request->header('Sec-Fetch-Mode'),
                'sec_fetch_dest' => $request->header('Sec-Fetch-Dest'),
                'ch_platform' => trim($request->header('Sec-CH-UA-Platform', ''), '"'),
                'ch_is_mobile' => $request->hasHeader('Sec-CH-UA-Mobile')
                                        ? $request->header('Sec-CH-UA-Mobile') === '?1'
                                        : null,
                'save_data' => $request->header('Save-Data') === 'on',
                'server_protocol' => $request->server('SERVER_PROTOCOL'),
            ];

            ProcessLinkClickJob::dispatch($link->id, $payload);
            AppLogger::redirectDispatched($link->id, ProcessLinkClickJob::class);
        } catch (\Throwable $e) {
            AppLogger::redirectError($slug, $e);
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

    /**
     * Fetch and cache Open Graph metadata for the given URL (24-hour TTL).
     *
     * For YouTube URLs the public oEmbed API is tried first; it returns clean
     * structured JSON without the need to parse HTML and without anti-bot
     * filtering. For all other URLs (and as a YouTube fallback), an HTTP GET is
     * issued with the {@see METADATA_FETCH_UA} social-crawler User-Agent so
     * platforms serve their full OG tags instead of a generic page.
     *
     * @return array{title:string,description:string,og_title:string,og_description:string,og_image:string|null,og_type:string,url:string}
     */
    private function fetchOriginalMetadata(string $url): array
    {
        if (! $this->isSafeFetchUrl($url)) {
            AppLogger::ogFetchSkipped($url, 'unsafe_url');

            return $this->getDefaultMetadata($url);
        }

        $cacheKey = 'metadata_'.md5($url);

        return Cache::remember($cacheKey, self::METADATA_CACHE_TTL_SECONDS, function () use ($url) {
            // YouTube: oEmbed API gives clean structured data without HTML parsing.
            if ($this->isYouTubeUrl($url)) {
                $ytMeta = $this->fetchYouTubeOembed($url);
                if ($ytMeta !== null) {
                    return $ytMeta;
                }
                // oEmbed failed (private/deleted video) — fall through to HTML fetch.
            }

            try {
                $response = Http::withHeaders([
                    'User-Agent' => self::METADATA_FETCH_UA,
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
                    ])
                    ->get($url);

                if (! $response->ok()) {
                    AppLogger::ogFetchNonOk($url, $response->status());

                    return $this->getDefaultMetadata($url);
                }

                return $this->parseMetaTags($response->body(), $url);
            } catch (\Throwable $e) {
                AppLogger::ogFetchFailed($url, $e);

                return $this->getDefaultMetadata($url);
            }
        });
    }

    /**
     * Fetch YouTube video metadata via the public oEmbed JSON endpoint.
     *
     * Returns an array compatible with {@see getDefaultMetadata()} on success,
     * or null if the endpoint returns a non-2xx response (private/deleted video)
     * or if the JSON contains no usable title.
     *
     * The oEmbed thumbnail is always hqdefault (480×360). When a video ID can
     * be extracted, the thumbnail is upgraded to maxresdefault (1280×720),
     * which is the image WhatsApp and other platforms display in link previews.
     *
     * @return array{title:string,description:string,og_title:string,og_description:string,og_image:string|null,og_type:string,url:string}|null
     */
    private function fetchYouTubeOembed(string $url): ?array
    {
        try {
            $oembedUrl = 'https://www.youtube.com/oembed?url='.urlencode($url).'&format=json';
            $response = Http::connectTimeout(3)->timeout(5)->get($oembedUrl);

            if (! $response->ok()) {
                return null;
            }

            $data = $response->json();
            $title = isset($data['title']) && $data['title'] !== '' ? $data['title'] : null;

            if (! $title) {
                return null;
            }

            $authorName = $data['author_name'] ?? null;
            $description = $authorName
                ? "Por {$authorName} • YouTube"
                : 'YouTube';

            // Prefer maxresdefault (1280×720) over oEmbed's hqdefault (480×360).
            $thumbnail = $data['thumbnail_url'] ?? null;
            $videoId = $this->extractYouTubeVideoId($url);
            if ($videoId) {
                $thumbnail = "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg";
            }

            return [
                'title' => $title,
                'description' => $description,
                'og_title' => $title,
                'og_description' => $description,
                'og_image' => $thumbnail,
                'og_type' => 'video.other',
                'url' => $url,
            ];
        } catch (\Throwable $e) {
            AppLogger::ogFetchFailed($url, $e);

            return null;
        }
    }

    /**
     * Return true if the URL hostname belongs to YouTube.
     */
    private function isYouTubeUrl(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return in_array($host, ['youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com'], true);
    }

    /**
     * Extract the 11-character YouTube video ID from a watch or short URL.
     * Returns null if the URL does not match either pattern.
     */
    private function extractYouTubeVideoId(string $url): ?string
    {
        if (preg_match('/(?:youtube\.com\/watch\?.*?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches)) {
            return $matches[1];
        }

        return null;
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
