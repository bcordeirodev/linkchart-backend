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
     * Primary social-crawler User-Agent used when fetching OG metadata.
     *
     * Most platforms (YouTube, LinkedIn, Twitter, Shopee, etc.) serve their
     * full Open Graph tags in response to recognised social-crawler UAs.
     * A custom non-standard UA typically results in a stripped or consent
     * page with no real metadata.
     */
    private const METADATA_FETCH_UA_PRIMARY = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /**
     * Fallback User-Agent tried when the primary UA produces no og:image.
     *
     * Some e-commerce platforms (Amazon, Mercado Livre, etc.) deliberately
     * withhold product images from Meta / Facebook crawlers because Meta
     * operates a competing shopping platform. Twitterbot is accepted by most
     * of those platforms and returns full OG data including og:image.
     */
    private const METADATA_FETCH_UA_FALLBACK = 'Twitterbot/1.0';

    /**
     * Generic browser User-Agent used as a last-resort fallback.
     *
     * Platforms that block known social-crawler UAs (facebookexternalhit,
     * Twitterbot) but do not perform source-IP verification against published
     * crawler IP ranges will serve a full HTML response to this UA.
     * Paired with complete browser Accept/Accept-Language headers to pass
     * basic bot-detection heuristics that inspect header completeness.
     */
    private const METADATA_FETCH_UA_BROWSER = 'Mozilla/5.0 (compatible; LinkPreview/1.0; +https://linkchar.com.br)';

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

            $metadata = $this->fetchOriginalMetadata($link->original_url);

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

    /**
     * Fetch and cache Open Graph metadata for the given URL (24-hour TTL).
     *
     * Fetch strategy:
     *   1. YouTube: public oEmbed JSON endpoint — clean structured data, no HTML
     *      parsing, no anti-bot filtering.
     *   2. Primary HTML fetch: {@see METADATA_FETCH_UA_PRIMARY} (facebookexternalhit).
     *      Works for most social platforms, affiliate networks, and Shopee.
     *   3. Fallback HTML fetch: {@see METADATA_FETCH_UA_FALLBACK} (Twitterbot).
     *      Triggered only when the primary fetch returns no og:image. Amazon and
     *      some other e-commerce platforms block facebookexternalhit (Meta is a
     *      competing shopping platform) but serve full OG data to Twitterbot.
     *   4. Browser fallback: {@see METADATA_FETCH_UA_BROWSER} (generic browser UA).
     *      Triggered when both crawler UAs return non-2xx. Platforms that block
     *      known bot UAs without IP-range verification will serve a full page here.
     *      When ALL three strategies fail, the returned array includes the marker
     *      `_fetch_failed => true` so the caller can choose to redirect bots to
     *      the original URL (letting the bot's own verified IP fetch OG data
     *      directly, bypassing Cloudflare IP verification).
     *
     * @return array{title:string,description:string,og_title:string,og_description:string,og_image:string|null,og_type:string,url:string,_fetch_failed?:bool}
     */
    private function fetchOriginalMetadata(string $url): array
    {
        if (! $this->isSafeFetchUrl($url)) {
            AppLogger::ogFetchSkipped($url, 'unsafe_url');

            return $this->getDefaultMetadata($url);
        }

        // Normalize the URL (strip tracking params) only for the cache key so
        // that the same page reached via ?utm_source=twitter and ?fbclid=xyz
        // shares one cache entry. The actual HTTP request uses the original URL.
        $cacheKey = 'metadata_'.md5($this->normalizeMetaUrl($url));

        return Cache::remember($cacheKey, self::METADATA_CACHE_TTL_SECONDS, function () use ($url) {
            // YouTube: oEmbed API gives clean structured data without HTML parsing.
            if ($this->isYouTubeUrl($url)) {
                $ytMeta = $this->fetchYouTubeOembed($url);
                if ($ytMeta !== null) {
                    AppLogger::ogFetchSucceeded($url, 'oembed');

                    return $ytMeta;
                }
                // oEmbed failed (private/deleted video) — fall through to HTML fetch.
            }

            // Primary fetch: facebookexternalhit works for most platforms.
            $metadata = $this->doHtmlFetch($url, self::METADATA_FETCH_UA_PRIMARY);

            // UA fallback: Amazon and certain e-commerce platforms block facebookexternalhit
            // because Meta operates a competing shopping platform. Retry with Twitterbot,
            // which those platforms accept and which returns full OG data.
            if ($metadata === null || empty($metadata['og_image'])) {
                $fallback = $this->doHtmlFetch($url, self::METADATA_FETCH_UA_FALLBACK);
                if ($fallback !== null && ! empty($fallback['og_image'])) {
                    AppLogger::ogFetchSucceeded($url, 'html_fallback');

                    return $fallback;
                }
                // Promote fallback result if it has more data than the primary.
                if ($fallback !== null && $metadata === null) {
                    $metadata = $fallback;
                }
            }

            if ($metadata !== null) {
                AppLogger::ogFetchSucceeded($url, 'html');

                return $metadata;
            }

            // Both crawler UAs returned non-2xx (e.g. Cloudflare blocks VPS IPs
            // that impersonate facebookexternalhit/Twitterbot without a matching
            // source IP). Try a generic browser UA — some WAF configs allow it.
            $browserMeta = $this->doHtmlFetch(
                $url,
                self::METADATA_FETCH_UA_BROWSER,
                [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                ]
            );
            if ($browserMeta !== null) {
                AppLogger::ogFetchSucceeded($url, 'html_browser_ua');

                return $browserMeta;
            }

            // All three strategies failed — mark the result so the caller can
            // decide to pass the bot through directly to the original URL.
            $failed = $this->getDefaultMetadata($url);
            $failed['_fetch_failed'] = true;
            AppLogger::event('redirect', 'warning', 'og.fetch_all_attempts_failed', ['url' => $url]);

            return $failed;
        });
    }

    /**
     * Issue a single HTTP GET for $url with the given $userAgent and parse OG tags.
     *
     * The body is truncated at 512 KB — OG tags live in <head> (typically < 32 KB),
     * so truncating prevents huge pages from consuming excessive memory.
     *
     * Returns the parsed metadata array on HTTP 2xx, or null on non-2xx response
     * or on any exception (network error, TLS failure, timeout, etc.).
     *
     * @param  array<string,string>  $extraHeaders  Additional HTTP headers merged after User-Agent.
     * @return array{title:string,description:string,og_title:string,og_description:string,og_image:string|null,og_type:string,url:string}|null
     */
    private function doHtmlFetch(string $url, string $userAgent, array $extraHeaders = []): ?array
    {
        try {
            $response = Http::withHeaders(array_merge(
                ['User-Agent' => $userAgent],
                $extraHeaders,
            ))
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

                return null;
            }

            $body = $response->body();
            if (strlen($body) > 524288) {
                $body = substr($body, 0, 524288);
            }

            return $this->parseMetaTags($body, $url);
        } catch (\Throwable $e) {
            AppLogger::ogFetchFailed($url, $e);

            return null;
        }
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
                'og_image_width' => $videoId ? 1280 : null,
                'og_image_height' => $videoId ? 720 : null,
                'og_type' => 'video.other',
                'url' => $url,
            ];
        } catch (\Throwable $e) {
            AppLogger::ogFetchFailed($url, $e);

            return null;
        }
    }

    /**
     * Strip well-known tracking query parameters from a URL so that the same
     * destination page reached via different campaign URLs shares one cache entry.
     *
     * Only the query string is modified — scheme, host, path, and semantic params
     * (e.g., YouTube's `v=`) are preserved. The original URL (not the normalised
     * one) is used for the actual HTTP fetch so destination sites that require
     * specific params continue to serve the correct page.
     *
     * Stripped params: UTM family, fbclid, gclid, msclkid, ttclid, twclid,
     * YouTube's `si` / `feature`, Instagram's `igshid`, Google Analytics `_ga`,
     * common `ref` / `ref_src` / `ref_url` referral markers.
     */
    private function normalizeMetaUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (! isset($parsed['query'])) {
            return $url;
        }

        parse_str($parsed['query'], $params);

        $trackingParams = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'fbclid', 'gclid', 'msclkid', 'ttclid', 'twclid',
            'si', 'feature',
            'igshid',
            '_ga',
            'ref', 'ref_src', 'ref_url',
            'lis',   // OLX internal listing-source tracking param
        ];

        foreach ($trackingParams as $param) {
            unset($params[$param]);
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        $query = $params ? '?'.http_build_query($params) : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        return "{$scheme}://{$host}{$path}{$query}{$fragment}";
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
     * SSRF protection: reject non-HTTP schemes, internal hostnames, private/
     * reserved literal IPs (IPv4 and IPv6), and hostnames that resolve to a
     * private IP at the time of the check (DNS rebinding mitigation).
     *
     * Checks performed:
     *   1. Scheme must be http or https.
     *   2. Hardcoded loopback aliases (localhost, 0.0.0.0).
     *   3. *.local / *.internal / *.localhost TLD suffixes.
     *   4. Literal IPv4 in private/reserved ranges (10/8, 172.16/12, 192.168/16,
     *      169.254/16, 127/8).
     *   5. Literal IPv6 in loopback (::1) or private ranges (fc00::/7, fd00::/8,
     *      and IPv4-mapped equivalents like ::ffff:127.0.0.1).
     *   6. DNS resolution: hostname is resolved to an IPv4 address and the
     *      resolved IP is checked against private/reserved ranges. This prevents
     *      DNS rebinding attacks where a hostname passes the string checks but
     *      resolves to an internal IP at request time.
     *      Note: gethostbyname() covers IPv4 only; IPv6-only hosts are not
     *      checked via DNS here — mitigated by rejecting unknown IPv6 literals
     *      in check 5.
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

        // 1. Hardcoded loopback aliases.
        if (in_array($host, ['localhost', 'localhost.localdomain', '0.0.0.0'], true)) {
            return false;
        }

        // 2. Internal TLD suffixes.
        if (preg_match('/\.(local|internal|localhost)$/', $host)) {
            return false;
        }

        // 3. Literal IPv4 in private/reserved ranges.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        // 4. Literal IPv6 (parse_url strips brackets — host is raw IPv6 string).
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        // 5. DNS rebinding mitigation: resolve the hostname to an IPv4 address
        //    and reject if it falls in a private/reserved range.
        //    gethostbyname() returns the input unchanged on resolution failure,
        //    so the !== check correctly skips validation when DNS lookup fails.
        $resolvedIp = gethostbyname($host);
        if ($resolvedIp !== $host) {
            if (! filter_var($resolvedIp, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function parseMetaTags(string $html, string $url): array
    {
        $metadata = $this->getDefaultMetadata($url);

        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $metadata['title'] = html_entity_decode(trim(strip_tags($matches[1])));
        }

        // Normalize property names: colons in sub-properties (og:image:width) are
        // replaced with underscores so callers use consistent array keys like
        // og_image_width rather than og_image:width.
        preg_match_all('/<meta\s+property=["\']og:([^"\']+)["\']\s+content=["\']([^"\']+)["\']/i', $html, $ogMatches);
        for ($i = 0; $i < count($ogMatches[0]); $i++) {
            $key = 'og_'.str_replace(':', '_', $ogMatches[1][$i]);
            $metadata[$key] = html_entity_decode($ogMatches[2][$i]);
        }

        preg_match_all('/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:([^"\']+)["\']/i', $html, $ogMatchesAlt);
        for ($i = 0; $i < count($ogMatchesAlt[0]); $i++) {
            $key = 'og_'.str_replace(':', '_', $ogMatchesAlt[2][$i]);
            if (! isset($metadata[$key])) {
                $metadata[$key] = html_entity_decode($ogMatchesAlt[1][$i]);
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

        // Replace the default og_description with the real meta description when
        // the page provided one. Compare against the exact default string rather
        // than a substring so a legitimate description containing those words is
        // not discarded.
        if (! empty($metadata['description'])) {
            $defaultOgDesc = "Clique para acessar {$domain}";
            if (($metadata['og_description'] ?? '') === $defaultOgDesc) {
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

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="{$refreshDelay};url={$metaUrl}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="{$ogType}">
    <meta property="og:url" content="{$metaUrl}">
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$description}">
    {$imageTag}
{$imageDimTags}

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="{$metaUrl}">
    <meta name="twitter:title" content="{$title}">
    <meta name="twitter:description" content="{$description}">
    {$twitterImageTag}

    <!-- Canonical -->
    <link rel="canonical" href="{$metaUrl}">

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
        <a href="{$metaUrl}" class="btn">Ir Agora</a>
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
            window.location.href = {$targetUrl};
        }, 2000);

        setTimeout(function() {
            if (document.visibilityState === 'visible') {
                window.location.replace({$targetUrl});
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
