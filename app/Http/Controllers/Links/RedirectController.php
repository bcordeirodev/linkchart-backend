<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\LinkServiceInterface;
use App\Jobs\ProcessLinkClickJob;
use App\Logging\AppLogger;
use App\Logging\Context\RequestContext;
use App\Models\Link;
use App\Services\Links\LinkTrackingService;
use App\Services\Links\RedirectMetadataFetcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        protected LinkTrackingService $linkTrackingService,
        protected RedirectMetadataFetcher $metadataFetcher
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
     *   Bot + all OG fetches failed: 302 redirect to original_url so the bot's
     *     own verified IP fetches OG data directly from the destination
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
            $isPreview = $request->boolean('preview');

            if (! $isBot && ! $isPreview) {
                $this->dispatchTracking($link, $request, $slug);

                return redirect()->away($link->original_url, 302)->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }

            $metadata = $this->metadataFetcher->fetch($link->original_url);

            // When all OG fetch strategies failed (e.g. Cloudflare IP-verifies
            // known crawler UAs and blocks VPS IPs), redirect the bot to the
            // original URL so its own verified IP fetches OG data directly.
            if ($isBot && ! empty($metadata['_fetch_failed'])) {
                AppLogger::redirectStarted($slug, $link->id, ['reason' => 'og_fetch_failed_bot_passthrough']);

                return redirect()->away($link->original_url, 302)->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }

            return $this->renderRedirectPage($link, $metadata, $isBot);
        } catch (\Exception $e) {
            AppLogger::redirectError($slug, $e);

            return $this->renderErrorPage('Erro ao processar redirecionamento');
        }
    }

    /**
     * Enqueue ProcessLinkClickJob with the full tracking payload (resolved IP,
     * UA, referer, UTM, Sec-Fetch metadata, client hints, server-generated
     * dedup_key). Returns immediately.
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
                // Server-generated, never client-influenced — used only for retry dedup.
                'dedup_key' => 'clk_'.bin2hex(random_bytes(8)),
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

    private function renderRedirectPage(Link $link, array $metadata, bool $isBot): \Illuminate\Http\Response
    {
        $title = e($metadata['og_title'] ?? $metadata['title'] ?? $link->title ?? 'Redirecionando...');
        $description = e($metadata['og_description'] ?? $metadata['description'] ?? 'Aguarde...');
        $image = $metadata['og_image'] ?? null;
        // $metaUrl: HTML-escaped for the <meta http-equiv="refresh"> attribute context
        $metaUrl = htmlspecialchars($link->original_url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // $targetUrl: JSON-encoded for JS string literal — json_encode includes surrounding quotes
        // JSON_HEX_TAG prevents </script> injection; other flags escape quotes/ampersands
        $targetUrl = json_encode(
            $link->original_url,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );

        $refreshDelay = $isBot ? 5 : 2;

        $ogType = e($metadata['og_type'] ?? 'website');
        $imageTag = $this->renderMetaImageTag($image, 'og:image', 'property');
        $twitterImageTag = $this->renderMetaImageTag($image, 'twitter:image', 'name');
        $displayUrl = $this->truncateUrl($metaUrl, 60);

        // og:image:width/height let crawlers (WhatsApp, Facebook) validate image
        // dimensions without downloading it — preview appears faster and a too-small
        // image is skipped without a wasted HTTP round-trip.
        $imageWidth = isset($metadata['og_image_width']) ? (int) $metadata['og_image_width'] : null;
        $imageHeight = isset($metadata['og_image_height']) ? (int) $metadata['og_image_height'] : null;
        $imageDimTags = ($imageWidth > 0 && $imageHeight > 0)
            ? "    <meta property=\"og:image:width\" content=\"{$imageWidth}\">\n    <meta property=\"og:image:height\" content=\"{$imageHeight}\">"
            : '';

        // The view consumes the values already escaped here (e()/htmlspecialchars/
        // json_encode) and emits them raw via {!! !!}, so the rendered bytes are
        // identical to the previous inline heredoc. See resources/views/redirect/.
        return response()->view('redirect.interstitial', [
            'refreshDelay' => $refreshDelay,
            'metaUrl' => $metaUrl,
            'ogType' => $ogType,
            'title' => $title,
            'description' => $description,
            'imageTag' => $imageTag,
            'imageDimTags' => $imageDimTags,
            'twitterImageTag' => $twitterImageTag,
            'displayUrl' => $displayUrl,
            'targetUrl' => $targetUrl,
        ], 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    private function renderErrorPage(string $message): \Illuminate\Http\Response
    {
        $safeMessage = e($message);
        // Use config(), not env(): with config:cache active in production,
        // env() outside config/ returns null and the error page would link to
        // localhost. config('app.frontend_url') reads the cached value.
        $frontendUrl = config('app.frontend_url');

        // $safeMessage is pre-escaped (e()) and $frontendUrl comes from config;
        // the view emits both raw via {!! !!}, keeping the bytes identical to the
        // previous inline heredoc. See resources/views/redirect/error.blade.php.
        return response()->view('redirect.error', [
            'safeMessage' => $safeMessage,
            'frontendUrl' => $frontendUrl,
        ], 404)
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
