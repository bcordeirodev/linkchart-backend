<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches and caches Open Graph metadata for the redirect interstitial page.
 *
 * Extracted verbatim from {@see \App\Http\Controllers\Links\RedirectController}
 * to separate the OG-fetch concern from the HTTP controller. Behaviour is
 * intentionally byte-for-byte identical to the previous inline implementation:
 * same cache key/TTL, same multi-UA fetch strategy, same regex-based parser, and
 * the same `_fetch_failed` marker contract.
 *
 * IMPORTANT — why this is NOT unified with {@see LinkPreviewService}:
 * LinkPreviewService parses OG tags with DOMDocument and returns a minimal field
 * set (og_title/og_image_url/og_description only). This fetcher uses a regex
 * parser that additionally extracts the <title> tag (used as an og:title
 * fallback), og:image:width/height dimension tags, the twitter:image fallback,
 * protocol-relative/absolute og:image URL resolution, and default-metadata
 * fallbacks. The two parsers produce DIFFERENT extracted fields for the same
 * HTML, so swapping in the DOM parser would change the rendered social-preview
 * output (lost title fallback, lost image dimensions, lost twitter fallback).
 * Full parser unification is therefore deferred; this extraction preserves the
 * exact observable behaviour of the redirect path.
 *
 * Fetch strategy (see {@see fetch()}):
 *   1. YouTube: public oEmbed JSON endpoint — clean structured data, no HTML
 *      parsing, no anti-bot filtering.
 *   2. Primary HTML fetch: {@see FETCH_UA_PRIMARY} (facebookexternalhit).
 *   3. Fallback HTML fetch: {@see FETCH_UA_FALLBACK} (Twitterbot) — only when the
 *      primary fetch returns no og:image.
 *   4. Browser fallback: {@see FETCH_UA_BROWSER} — only when both crawler UAs
 *      return non-2xx. When ALL three strategies fail, the returned array
 *      includes `_fetch_failed => true`.
 */
class RedirectMetadataFetcher
{
    private const METADATA_CACHE_TTL_SECONDS = 86400;

    /**
     * Maximum wall-clock the multi-strategy OG fetch may consume on the redirect
     * hot path before remaining fallback strategies are skipped. Checked between
     * strategies (soft deadline), so one in-flight request may overshoot by up to
     * one doHtmlFetch timeout.
     */
    private const FETCH_BUDGET_SECONDS = 4.5;

    /**
     * Primary social-crawler User-Agent used when fetching OG metadata.
     *
     * Most platforms (YouTube, LinkedIn, Twitter, Shopee, etc.) serve their
     * full Open Graph tags in response to recognised social-crawler UAs.
     * A custom non-standard UA typically results in a stripped or consent
     * page with no real metadata.
     */
    private const FETCH_UA_PRIMARY = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /**
     * Fallback User-Agent tried when the primary UA produces no og:image.
     *
     * Some e-commerce platforms (Amazon, Mercado Livre, etc.) deliberately
     * withhold product images from Meta / Facebook crawlers because Meta
     * operates a competing shopping platform. Twitterbot is accepted by most
     * of those platforms and returns full OG data including og:image.
     */
    private const FETCH_UA_FALLBACK = 'Twitterbot/1.0';

    /**
     * Generic browser User-Agent used as a last-resort fallback.
     *
     * Platforms that block known social-crawler UAs (facebookexternalhit,
     * Twitterbot) but do not perform source-IP verification against published
     * crawler IP ranges will serve a full HTML response to this UA.
     * Paired with complete browser Accept/Accept-Language headers to pass
     * basic bot-detection heuristics that inspect header completeness.
     */
    private const FETCH_UA_BROWSER = 'Mozilla/5.0 (compatible; LinkPreview/1.0; +https://linkchar.com.br)';

    /** Wall-clock source (seconds, monotonic); injectable for deterministic tests. */
    protected Closure $clock;

    /**
     * @param  SafeFetchUrlValidator  $safeFetchUrlValidator  SSRF guard for outbound fetches.
     * @param  Closure|null  $clock  Returns the current time in seconds as a float.
     *                               Defaults to microtime(true); override in tests.
     */
    public function __construct(
        protected SafeFetchUrlValidator $safeFetchUrlValidator,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Fetch and cache Open Graph metadata for the given URL (24-hour TTL).
     *
     * Fetch strategy:
     *   1. YouTube: public oEmbed JSON endpoint — clean structured data, no HTML
     *      parsing, no anti-bot filtering.
     *   2. Primary HTML fetch: {@see FETCH_UA_PRIMARY} (facebookexternalhit).
     *      Works for most social platforms, affiliate networks, and Shopee.
     *   3. Fallback HTML fetch: {@see FETCH_UA_FALLBACK} (Twitterbot).
     *      Triggered only when the primary fetch returns no og:image. Amazon and
     *      some other e-commerce platforms block facebookexternalhit (Meta is a
     *      competing shopping platform) but serve full OG data to Twitterbot.
     *   4. Browser fallback: {@see FETCH_UA_BROWSER} (generic browser UA).
     *      Triggered when both crawler UAs return non-2xx. Platforms that block
     *      known bot UAs without IP-range verification will serve a full page here.
     *      When ALL three strategies fail, the returned array includes the marker
     *      `_fetch_failed => true` so the caller can choose to redirect bots to
     *      the original URL (letting the bot's own verified IP fetch OG data
     *      directly, bypassing Cloudflare IP verification).
     *
     * @return array{title:string,description:string,og_title:string,og_description:string,og_image:string|null,og_type:string,url:string,_fetch_failed?:bool}
     */
    public function fetch(string $url): array
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
            $start = ($this->clock)();
            $deadline = $start + self::FETCH_BUDGET_SECONDS;

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
            $metadata = $this->doHtmlFetch($url, self::FETCH_UA_PRIMARY);

            // UA fallback: Amazon and certain e-commerce platforms block facebookexternalhit
            // because Meta operates a competing shopping platform. Retry with Twitterbot,
            // which those platforms accept and which returns full OG data — but only if the
            // hot-path budget still allows another outbound request.
            if ($metadata === null || empty($metadata['og_image'])) {
                if ($this->budgetExceeded($deadline)) {
                    AppLogger::ogFetchBudgetExceeded($url, round((($this->clock)() - $start) * 1000, 2));
                } else {
                    $fallback = $this->doHtmlFetch($url, self::FETCH_UA_FALLBACK);
                    if ($fallback !== null && ! empty($fallback['og_image'])) {
                        AppLogger::ogFetchSucceeded($url, 'html_fallback');

                        return $fallback;
                    }
                    // Promote fallback result if it has more data than the primary.
                    if ($fallback !== null && $metadata === null) {
                        $metadata = $fallback;
                    }
                }
            }

            if ($metadata !== null) {
                AppLogger::ogFetchSucceeded($url, 'html');

                return $metadata;
            }

            // Both crawler UAs returned non-2xx (e.g. Cloudflare blocks VPS IPs
            // that impersonate facebookexternalhit/Twitterbot without a matching
            // source IP). Try a generic browser UA — some WAF configs allow it —
            // unless the budget is already spent.
            if ($this->budgetExceeded($deadline)) {
                AppLogger::ogFetchBudgetExceeded($url, round((($this->clock)() - $start) * 1000, 2));
            } else {
                $browserMeta = $this->doHtmlFetch(
                    $url,
                    self::FETCH_UA_BROWSER,
                    [
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                    ]
                );
                if ($browserMeta !== null) {
                    AppLogger::ogFetchSucceeded($url, 'html_browser_ua');

                    return $browserMeta;
                }
            }

            // All strategies failed or were skipped by the budget — mark the result
            // so the caller can pass the bot through directly to the original URL.
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
    /**
     * True once the OG-fetch wall-clock budget has been exhausted.
     *
     * @param  float  $deadline  Absolute clock value (seconds) at which the budget expires.
     */
    private function budgetExceeded(float $deadline): bool
    {
        return ($this->clock)() >= $deadline;
    }

    private function doHtmlFetch(string $url, string $userAgent, array $extraHeaders = []): ?array
    {
        try {
            $response = Http::withHeaders(array_merge(
                ['User-Agent' => $userAgent],
                $extraHeaders,
            ))
                ->connectTimeout(2)
                ->timeout(3)
                ->retry(1, 0)
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
     * SSRF protection for the OG metadata fetch. Delegates to
     * {@see \App\Services\Links\SafeFetchUrlValidator}, which validates the
     * scheme, internal hostnames, private literal IPs, and resolves A+AAAA
     * records to mitigate DNS rebinding.
     */
    private function isSafeFetchUrl(string $url): bool
    {
        return $this->safeFetchUrlValidator->isSafe($url);
    }

    /**
     * Parse OG/Twitter/title/description tags from raw HTML using regex.
     *
     * Note: intentionally regex-based (not DOMDocument) to preserve the exact
     * extraction semantics the redirect interstitial relies on — including the
     * <title> fallback, og:image:width/height normalisation, twitter:image
     * fallback, and protocol-relative/absolute og:image URL resolution. See the
     * class-level note for why this differs from {@see LinkPreviewService}.
     *
     * @return array<string,mixed>
     */
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

    /**
     * Build the default metadata set used as the parse baseline and as the
     * fallback when fetching fails or the target URL is unsafe.
     *
     * @return array{title:string,description:string,og_title:string,og_description:string,og_image:null,og_type:string,url:string}
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
            'url' => $url,
        ];
    }
}
