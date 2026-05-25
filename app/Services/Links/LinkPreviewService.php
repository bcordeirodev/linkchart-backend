<?php

namespace App\Services\Links;

use Illuminate\Support\Facades\Http;

/**
 * Fetches Open Graph metadata and favicon URL for an arbitrary target URL.
 *
 * Used by FetchLinkPreviewJob (dashboard thumbnails) and LinkMetaController
 * (url-meta endpoint for the create-link form slug suggestion).
 *
 * Fetch strategy:
 *   1. YouTube URLs: public oEmbed JSON endpoint — returns clean structured
 *      data without HTML parsing and without anti-bot filtering. Thumbnail
 *      is upgraded to maxresdefault (1280×720).
 *   2. All other URLs: HTTP GET with the facebookexternalhit/1.1 User-Agent,
 *      which is the industry-standard social-crawler UA. Most platforms serve
 *      their full Open Graph tags to this UA. Parsing is done via DOMDocument
 *      to correctly handle attribute-order variations, quoted-string styles,
 *      and other HTML spec edge cases that regex-based parsers miss.
 *
 * HTTP client config (Laravel Http facade over Guzzle):
 *   - timeout: 5 s total, connect_timeout: 3 s
 *   - allow_redirects: up to 5 hops
 *   - TLS verification: ON (default) — broken TLS fails gracefully with null
 *     preview.
 *
 * Results are persisted by FetchLinkPreviewJob to the link_previews table
 * (24 h TTL via fetched_at comparison in the callers).
 */
class LinkPreviewService
{
    /**
     * Standard social-crawler UA shared with RedirectController.
     * Platforms (YouTube, LinkedIn, etc.) recognise this UA and serve their
     * full Open Graph meta tags in response to it.
     */
    private const FETCH_UA = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /**
     * Fetches Open Graph metadata and a favicon URL for the given URL.
     *
     * For YouTube, the oEmbed API is tried first (structured JSON, no HTML
     * parsing). For all other URLs, an HTTP GET is issued with the social-
     * crawler UA and the response is parsed via DOMDocument.
     *
     * On HTTP failure or exception, returns an empty metadata set with the
     * favicon URL still populated (derived from the host, no HTTP call needed).
     *
     * @param  string  $url  The original target URL to fetch previews for.
     * @return array{favicon_url: string|null, og_title: string|null, og_image_url: string|null, og_description: string|null}
     */
    public function fetchPreview(string $url): array
    {
        $empty = [
            'favicon_url' => $this->faviconUrl($url),
            'og_title' => null,
            'og_image_url' => null,
            'og_description' => null,
        ];

        // YouTube: oEmbed gives clean structured data with no HTML parsing.
        if ($this->isYouTubeUrl($url)) {
            $yt = $this->fetchYouTubeOembed($url);
            if ($yt !== null) {
                return array_merge($empty, $yt);
            }
            // oEmbed failed (private/deleted) — fall through to HTML fetch.
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => self::FETCH_UA,
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ])
                ->connectTimeout(3)
                ->timeout(5)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);

            if (! $response->ok()) {
                return $empty;
            }

            $data = $this->parseOg($response->body());
            $data['favicon_url'] = $this->faviconUrl($url);

            return $data;
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Parse og:title, og:image, and og:description from raw HTML via DOMDocument.
     *
     * DOMDocument handles attribute-order variations (`content` before or after
     * `property`), different quote styles, and extra attributes between `property`
     * and `content` — all cases that regex-based parsers miss.
     *
     * The charset injection (`<?xml encoding="UTF-8">`) avoids the memory-
     * intensive `mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')` pattern,
     * which can triple string size for UTF-8 content.
     *
     * @return array{og_title: string|null, og_image_url: string|null, og_description: string|null}
     */
    private function parseOg(string $html): array
    {
        $data = ['og_title' => null, 'og_image_url' => null, 'og_description' => null];

        $doc = new \DOMDocument;
        // Charset injection is more memory-efficient than mb_convert_encoding
        // to HTML-ENTITIES, which triples string size for non-ASCII content.
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        foreach ($doc->getElementsByTagName('meta') as $meta) {
            /** @var \DOMElement $meta */
            $prop = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            $content = trim($meta->getAttribute('content'));

            if ($content === '') {
                continue;
            }

            if ($prop === 'og:title' && $data['og_title'] === null) {
                $data['og_title'] = $content;
            } elseif ($prop === 'og:image' && $data['og_image_url'] === null) {
                $data['og_image_url'] = $content;
            } elseif (($prop === 'og:description' || $prop === 'description') && $data['og_description'] === null) {
                $data['og_description'] = $content;
            }
        }

        return $data;
    }

    /**
     * Fetch YouTube video metadata via the public oEmbed JSON endpoint.
     *
     * Returns null when the endpoint returns non-2xx (private/deleted video)
     * or when the JSON contains no usable title. The thumbnail is upgraded
     * from oEmbed's hqdefault (480×360) to maxresdefault (1280×720) when the
     * video ID can be extracted.
     *
     * @return array{og_title: string, og_image_url: string|null, og_description: string}|null
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
            $title = isset($data['title']) && $data['title'] !== '' ? (string) $data['title'] : null;

            if ($title === null) {
                return null;
            }

            $authorName = isset($data['author_name']) ? (string) $data['author_name'] : null;
            $description = $authorName ? "Por {$authorName} • YouTube" : 'YouTube';

            // Prefer maxresdefault (1280×720) over oEmbed's hqdefault (480×360).
            $thumbnail = isset($data['thumbnail_url']) ? (string) $data['thumbnail_url'] : null;
            $videoId = $this->extractYouTubeVideoId($url);
            if ($videoId) {
                $thumbnail = "https://i.ytimg.com/vi/{$videoId}/maxresdefault.jpg";
            }

            return [
                'og_title' => $title,
                'og_image_url' => $thumbnail,
                'og_description' => $description,
            ];
        } catch (\Throwable) {
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
     * Build the Google favicon API URL for the given target URL's hostname.
     * Returns the URL unconditionally — no HTTP call is made here.
     */
    private function faviconUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        return "https://www.google.com/s2/favicons?domain={$host}&sz=32";
    }
}
