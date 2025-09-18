<?php

namespace App\Services\Links;

use App\Models\Link;
use App\Models\Click;
use App\Models\LinkUtm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;

class LinkTrackingService
{
    /**
     * Registra clique e dados complementares de tracking.
     */
    public function registrarClique(Link $link, Request $request): void
    {
        $startTime = microtime(true);
        $ip = $this->getRealUserIP($request);
        $userAgent = $request->userAgent() ?: 'Unknown';

        // Resolve localização detalhada
        $locationData = $this->resolveDetailedLocation($ip);

        // Parse detalhado do User-Agent
        $deviceData = $this->parseUserAgent($userAgent);

        // Dados temporais enriquecidos
        $temporalData = $this->enrichTemporalData(now(), $locationData['timezone']);

        // Análise de comportamento
        $behaviorData = $this->analyzeVisitorBehavior($ip, $link->id);

        // Dados de performance
        $performanceData = $this->collectPerformanceData($request, $startTime);

        // Registra clique no banco com todos os dados enriquecidos
        $click = Click::create(array_merge([
            'link_id'    => $link->id,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'referer'    => $request->headers->get('referer'),
            'country'    => $locationData['country'],
            'city'       => $locationData['city'],
            'device'     => $this->resolveDevice($userAgent),
            // Campos geográficos detalhados
            'iso_code'   => $locationData['iso_code'],
            'state'      => $locationData['state'],
            'state_name' => $locationData['state_name'],
            'postal_code'=> $locationData['postal_code'],
            'latitude'   => $locationData['latitude'],
            'longitude'  => $locationData['longitude'],
            'timezone'   => $locationData['timezone'],
            'continent'  => $locationData['continent'],
            'currency'   => $locationData['currency'],
        ], $deviceData, $temporalData, $behaviorData, $performanceData));

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
            // Usar o serviço GeoIP do Laravel
            $geoip = app('geoip');
            $location = $geoip->getLocation($ip);

            // Verificar se a localização foi encontrada (não é default)
            if (!$location->default) {
                return [
                    'country' => $location->country,
                    'city' => $location->city,
                    'iso_code' => $location->iso_code,
                    'state' => $location->state,
                    'postal_code' => $location->postal_code,
                    'continent' => $location->continent,
                    'currency' => $location->currency,
                    'state_name' => $location->state_name,
                    'latitude' => $location->lat,
                    'longitude' => $location->lon,
                    'timezone' => $location->timezone
                ];
            } else {
                Log::warning('GeoIP returned default location for IP: ' . $ip);
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

    /**
     * Parse detalhado do User-Agent usando Jenssegers\Agent
     */
    private function parseUserAgent(string $userAgent): array
    {
        try {
            $agent = new Agent();
            $agent->setUserAgent($userAgent);

            return [
                'browser' => $agent->browser() ?: 'Unknown',
                'browser_version' => $agent->version($agent->browser()) ?: null,
                'os' => $agent->platform() ?: 'Unknown',
                'os_version' => $agent->version($agent->platform()) ?: null,
                'is_mobile' => $agent->isMobile(),
                'is_tablet' => $agent->isTablet(),
                'is_desktop' => $agent->isDesktop(),
                'is_bot' => $agent->isRobot(),
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to parse user agent', [
                'user_agent' => $userAgent,
                'error' => $e->getMessage()
            ]);

            // Fallback para dados básicos
            return [
                'browser' => 'Unknown',
                'browser_version' => null,
                'os' => 'Unknown',
                'os_version' => null,
                'is_mobile' => false,
                'is_tablet' => false,
                'is_desktop' => true,
                'is_bot' => false,
            ];
        }
    }

    /**
     * Enriquece dados temporais com análise de padrões
     */
    private function enrichTemporalData(\DateTime $timestamp, ?string $timezone): array
    {
        try {
            $localTime = clone $timestamp;

            // Converter para timezone local se disponível
            if ($timezone) {
                try {
                    $localTime->setTimezone(new \DateTimeZone($timezone));
                } catch (\Exception $e) {
                    // Usar UTC se timezone inválido
                    Log::warning('Invalid timezone', ['timezone' => $timezone]);
                }
            }

            $hour = (int)$localTime->format('H');
            $dayOfWeek = (int)$localTime->format('N'); // 1=Monday, 7=Sunday

            return [
                'hour_of_day' => $hour,
                'day_of_week' => $dayOfWeek,
                'day_of_month' => (int)$localTime->format('d'),
                'month' => (int)$localTime->format('m'),
                'year' => (int)$localTime->format('Y'),
                'local_time' => $localTime->format('Y-m-d H:i:s'),
                'is_weekend' => in_array($dayOfWeek, [6, 7]), // Saturday, Sunday
                'is_business_hours' => $hour >= 9 && $hour <= 17,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to enrich temporal data', [
                'error' => $e->getMessage(),
                'timezone' => $timezone
            ]);

            // Fallback para dados UTC
            $hour = (int)$timestamp->format('H');
            $dayOfWeek = (int)$timestamp->format('N');

            return [
                'hour_of_day' => $hour,
                'day_of_week' => $dayOfWeek,
                'day_of_month' => (int)$timestamp->format('d'),
                'month' => (int)$timestamp->format('m'),
                'year' => (int)$timestamp->format('Y'),
                'local_time' => $timestamp->format('Y-m-d H:i:s'),
                'is_weekend' => in_array($dayOfWeek, [6, 7]),
                'is_business_hours' => $hour >= 9 && $hour <= 17,
            ];
        }
    }

    /**
     * Analisa comportamento do visitante
     */
    private function analyzeVisitorBehavior(string $ip, int $linkId): array
    {
        try {
            // Verificar se é visitante recorrente (últimas 24h)
            $recentClicks = Click::where('ip', $ip)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            // Contar cliques na sessão (última hora)
            $sessionClicks = Click::where('ip', $ip)
                ->where('created_at', '>=', now()->subHour())
                ->count() + 1; // +1 para o clique atual

            return [
                'is_return_visitor' => $recentClicks > 0,
                'session_clicks' => $sessionClicks,
                'click_source' => $this->categorizeClickSource(request()->headers->get('referer')),
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to analyze visitor behavior', [
                'ip' => $ip,
                'link_id' => $linkId,
                'error' => $e->getMessage()
            ]);

            return [
                'is_return_visitor' => false,
                'session_clicks' => 1,
                'click_source' => 'unknown',
            ];
        }
    }

    /**
     * Categoriza fonte do clique baseado no referer
     */
    private function categorizeClickSource(?string $referer): string
    {
        if (!$referer || $referer === '-') {
            return 'direct';
        }

        $domain = parse_url($referer, PHP_URL_HOST);

        if (!$domain) {
            return 'unknown';
        }

        $domain = strtolower($domain);

        // Redes sociais
        if (preg_match('/(facebook|twitter|instagram|linkedin|tiktok|youtube|whatsapp|telegram)/i', $domain)) {
            return 'social';
        }

        // Motores de busca
        if (preg_match('/(google|bing|yahoo|duckduckgo|baidu|yandex)/i', $domain)) {
            return 'search';
        }

        // Email
        if (preg_match('/(gmail|outlook|mail|webmail|hotmail)/i', $domain)) {
            return 'email';
        }

        return 'referral';
    }

    /**
     * Coleta dados de performance
     */
    private function collectPerformanceData(Request $request, float $startTime): array
    {
        try {
            $responseTime = (microtime(true) - $startTime) * 1000; // ms

            return [
                'response_time' => round($responseTime, 3),
                'accept_language' => $request->header('Accept-Language'),
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to collect performance data', [
                'error' => $e->getMessage()
            ]);

            return [
                'response_time' => null,
                'accept_language' => null,
            ];
        }
    }

    /**
     * 🌐 CAPTURA IP REAL DO USUÁRIO
     *
     * PRIORIDADE DE CAPTURA:
     * 1. real_ip query parameter (enviado pelo frontend para evitar CORS preflight)
     * 2. X-Real-IP (enviado pelo frontend via header)
     * 3. X-Forwarded-For (padrão de proxy)
     * 4. CF-Connecting-IP (Cloudflare)
     * 5. request->ip() (fallback)
     *
     * @param Request $request
     * @return string
     */
    private function getRealUserIP(Request $request): string
    {
        // 1. PRIORIDADE MÁXIMA: real_ip query param (evita CORS preflight)
        if ($realIP = $request->query('real_ip')) {
            $cleanIP = trim($realIP);
            if ($this->isValidIP($cleanIP)) {
                Log::info('IP captured via real_ip query parameter', [
                    'ip' => $cleanIP,
                    'source' => 'query_param'
                ]);
                return $cleanIP;
            }
        }

        // 2. X-Real-IP (enviado pelo nosso frontend via header)
        if ($realIP = $request->header('X-Real-IP')) {
            $cleanIP = trim($realIP);
            if ($this->isValidIP($cleanIP)) {
                Log::info('IP captured via X-Real-IP header', [
                    'ip' => $cleanIP,
                    'source' => 'X-Real-IP'
                ]);
                return $cleanIP;
            }
        }

        // 2. X-Forwarded-For (padrão da indústria para proxies)
        if ($forwardedFor = $request->header('X-Forwarded-For')) {
            // X-Forwarded-For pode ter múltiplos IPs: "client, proxy1, proxy2"
            $ips = array_map('trim', explode(',', $forwardedFor));
            $clientIP = $ips[0]; // Primeiro IP é sempre o cliente original

            if ($this->isValidIP($clientIP)) {
                Log::info('IP captured via X-Forwarded-For header', [
                    'ip' => $clientIP,
                    'source' => 'X-Forwarded-For',
                    'full_chain' => $forwardedFor
                ]);
                return $clientIP;
            }
        }

        // 3. CF-Connecting-IP (Cloudflare)
        if ($cfIP = $request->header('CF-Connecting-IP')) {
            $cleanIP = trim($cfIP);
            if ($this->isValidIP($cleanIP)) {
                Log::info('IP captured via CF-Connecting-IP header', [
                    'ip' => $cleanIP,
                    'source' => 'Cloudflare'
                ]);
                return $cleanIP;
            }
        }

        // 4. FALLBACK: IP da requisição (pode ser do proxy)
        $fallbackIP = $request->ip() ?: '127.0.0.1';

        Log::warning('Using fallback IP (may be proxy IP)', [
            'ip' => $fallbackIP,
            'source' => 'request->ip()',
            'warning' => 'This might be proxy IP, not real user IP'
        ]);

        return $fallbackIP;
    }

    /**
     * Valida se um IP é válido e não é privado/local
     *
     * @param string $ip
     * @return bool
     */
    private function isValidIP(string $ip): bool
    {
        // Validação básica de formato
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Rejeitar IPs privados/locais em produção
        if (config('app.env') === 'production') {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
            return false;
        }

        // Em desenvolvimento, aceitar qualquer IP válido
        return true;
    }
}
