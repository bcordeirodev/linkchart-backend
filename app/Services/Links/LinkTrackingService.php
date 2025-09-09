<?php

namespace App\Services\Links;

use App\Models\Link;
use App\Models\Click;
use App\Models\LinkUtm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LinkTrackingService
{
    /**
     * Registra clique e dados complementares de tracking.
     */
    public function registrarClique(Link $link, Request $request): void
    {
        $ip = $request->ip() ?: '127.0.0.1'; // Fallback para localhost se IP for null
        $userAgent = $request->userAgent() ?: 'Unknown';

        // Resolve localização detalhada, com fallback para localhost
        $locationData = $this->resolveDetailedLocation($ip);

        // Registra clique no banco com dados geográficos detalhados
        $click = Click::create([
            'link_id'    => $link->id,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'referer'    => $request->headers->get('referer'),
            'country'    => $locationData['country'],
            'city'       => $locationData['city'],
            'device'     => $this->resolveDevice($userAgent),
            // Novos campos geográficos detalhados
            'iso_code'   => $locationData['iso_code'],
            'state'      => $locationData['state'],
            'state_name' => $locationData['state_name'],
            'postal_code'=> $locationData['postal_code'],
            'latitude'   => $locationData['latitude'],
            'longitude'  => $locationData['longitude'],
            'timezone'   => $locationData['timezone'],
            'continent'  => $locationData['continent'],
            'currency'   => $locationData['currency'],
        ]);

        // Captura e registra UTM de query params ou headers
        $utm = $this->extractUtmData($request);

        if (!empty($utm)) {
            LinkUtm::create(array_merge(['click_id' => $click->id], $utm));
        }

        // Log do clique para debugging
        Log::info('Click registered', [
            'link_id' => $link->id,
            'slug' => $link->slug,
            'ip' => $ip,
            'country' => $locationData['country'],
            'state' => $locationData['state'],
            'city' => $locationData['city'],
            'device' => $this->resolveDevice($userAgent),
            'referer' => $request->headers->get('referer'),
            'utm_data' => $utm
        ]);
    }

    /**
     * Extrai dados UTM de query parameters ou referer
     */
    private function extractUtmData(Request $request): array
    {
        $utm = [];

        // 1. Primeiro, tentar pegar dos query params
        $queryUtm = $request->only([
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        ]);

        // 2. Se não houver nos query params, tentar extrair do referer
        if (empty(array_filter($queryUtm))) {
            $referer = $request->headers->get('referer');
            if ($referer) {
                $utm = $this->extractUtmFromReferer($referer);
            }
        } else {
            $utm = array_filter($queryUtm);
        }

        return $utm;
    }

    /**
     * Extrai dados UTM do referer URL
     */
    private function extractUtmFromReferer(string $referer): array
    {
        $utm = [];
        $parsedUrl = parse_url($referer);

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);

            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $utmParam) {
                if (isset($queryParams[$utmParam])) {
                    $utm[$utmParam] = $queryParams[$utmParam];
                }
            }
        }

        return $utm;
    }

    /**
     * Resolve localização detalhada via GeoIP.
     */
    private function resolveDetailedLocation(?string $ip): array
    {
        // Dados padrão para localhost ou IPs inválidos
        $defaultData = [
            'country' => 'localhost',
            'city' => 'localhost',
            'iso_code' => null,
            'state' => null,
            'state_name' => null,
            'postal_code' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null,
            'continent' => null,
            'currency' => null,
        ];

        if (!$ip || in_array($ip, ['127.0.0.1', '::1'])) {
            return $defaultData;
        }

        try {
            // Verifica se a função geoip existe antes de usar
            if (function_exists('geoip')) {
                $location = geoip($ip);

                return [
                    'country' => $location->getAttribute('country'),
                    'city' => $location->getAttribute('city'),
                    'iso_code' => $location->getAttribute('iso_code'),
                    'state' => $location->getAttribute('state'),
                    'state_name' => $location->getAttribute('state_name'),
                    'postal_code' => $location->getAttribute('postal_code'),
                    'latitude' => $location->getAttribute('lat'),
                    'longitude' => $location->getAttribute('lon'),
                    'timezone' => $location->getAttribute('timezone'),
                    'continent' => $location->getAttribute('continent'),
                    'currency' => $location->getAttribute('currency'),
                ];
            } else {
                Log::warning('GeoIP function not available');
                return $defaultData;
            }
        } catch (\Exception $e) {
            Log::warning('GeoIP lookup failed: ' . $e->getMessage(), [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return $defaultData;
        }
    }

    /**
     * Detecta tipo de dispositivo com base no User-Agent.
     */
    private function resolveDevice(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        // Detecção mais precisa de dispositivos
        if (preg_match('/(ipad|tablet|android(?!.*mobile))/i', $userAgent)) {
            return 'tablet';
        }

        if (preg_match('/(mobile|phone|iphone|ipod|blackberry|iemobile|opera mini)/i', $userAgent)) {
            return 'mobile';
        }

        if (preg_match('/(bot|crawler|spider|scraper)/i', $userAgent)) {
            return 'bot';
        }

        return 'desktop';
    }
}
