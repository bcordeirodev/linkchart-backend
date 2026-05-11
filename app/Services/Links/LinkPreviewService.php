<?php

namespace App\Services\Links;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Fetches Open Graph metadata and favicon URL for an arbitrary target URL.
 *
 * Used by RedirectController when rendering Open Graph HTML previews for bot
 * user-agents (WhatsApp, Telegram, etc.) so social platforms can display link
 * previews without following the redirect.
 *
 * HTTP client config (Guzzle):
 *   - timeout: 5s total, connect_timeout: 3s
 *   - allow_redirects: up to 5 hops
 *   - TLS verification: ON (default Guzzle behaviour) — sites with broken or
 *     self-signed TLS certificates will fail gracefully with a null preview.
 *
 * Results are cached upstream (RedirectController caches the full preview payload
 * for 24h per slug).
 */
class LinkPreviewService
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout' => 5,
            'connect_timeout' => 3,
            'allow_redirects' => ['max' => 5],
        ]);
    }

    /**
     * Fetches Open Graph metadata and a favicon URL for the given URL.
     *
     * Makes a GET request with a bot User-Agent, parses og:title and og:image
     * from the HTML response, and resolves a favicon via the Google favicon API.
     * On HTTP failure, returns an empty metadata set with the favicon URL still
     * populated (favicon is derived from the host name, no HTTP call needed).
     *
     * @param  string  $url  The original target URL to fetch previews for.
     * @return array{favicon_url: string|null, og_title: string|null, og_image_url: string|null}
     */
    public function fetchPreview(string $url): array
    {
        $empty = ['favicon_url' => null, 'og_title' => null, 'og_image_url' => null];

        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'User-Agent' => 'LinkChartBot/1.0 (preview-fetcher)',
                    'Accept' => 'text/html',
                ],
            ]);
            $html = (string) $response->getBody();
        } catch (RequestException $e) {
            return $this->withFavicon($empty, $url);
        }

        $data = $this->parseOg($html);
        $data['favicon_url'] = $this->faviconUrl($url);

        return $data;
    }

    private function parseOg(string $html): array
    {
        $data = ['og_title' => null, 'og_image_url' => null];

        $doc = new \DOMDocument;
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $prop = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            $content = $meta->getAttribute('content');
            if ($prop === 'og:title' && ! $data['og_title']) {
                $data['og_title'] = $content;
            }
            if ($prop === 'og:image' && ! $data['og_image_url']) {
                $data['og_image_url'] = $content;
            }
        }

        return $data;
    }

    private function faviconUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';

        return "https://www.google.com/s2/favicons?domain={$host}&sz=32";
    }

    private function withFavicon(array $data, string $url): array
    {
        $data['favicon_url'] = $this->faviconUrl($url);

        return $data;
    }
}
