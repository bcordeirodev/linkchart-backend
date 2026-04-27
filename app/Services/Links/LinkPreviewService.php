<?php

namespace App\Services\Links;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class LinkPreviewService
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout'         => 5,
            'connect_timeout' => 3,
            'allow_redirects' => ['max' => 5],
            'verify'          => false,
        ]);
    }

    /**
     * Fetches OG meta + favicon for a URL.
     * Returns ['favicon_url', 'og_title', 'og_image_url'] — all nullable on failure.
     */
    public function fetchPreview(string $url): array
    {
        $empty = ['favicon_url' => null, 'og_title' => null, 'og_image_url' => null];

        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'User-Agent' => 'LinkChartBot/1.0 (preview-fetcher)',
                    'Accept'     => 'text/html',
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

        $doc = new \DOMDocument();
        @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $prop    = $meta->getAttribute('property') ?: $meta->getAttribute('name');
            $content = $meta->getAttribute('content');
            if ($prop === 'og:title' && !$data['og_title']) {
                $data['og_title'] = $content;
            }
            if ($prop === 'og:image' && !$data['og_image_url']) {
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
