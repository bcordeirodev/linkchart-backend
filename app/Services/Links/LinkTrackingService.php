<?php

namespace App\Services\Links;

use App\Models\Click;
use App\Models\Link;
use App\Models\LinkUtm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;

class LinkTrackingService
{
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Registra clique a partir de payload serializável (extraído do Request no controller).
     * Ponto de entrada usado pelo ProcessLinkClickJob.
     *
     * @param  array{
     *     ip?: string,
     *     user_agent?: ?string,
     *     referer?: ?string,
     *     accept_language?: ?string,
     *     query_params?: array<string, string>,
     *     start_time?: float
     * }  $payload
     */
    public function registrarCliqueFromPayload(int $linkId, array $payload): void
    {
        $link = Link::find($linkId);

        if (! $link) {
            Log::warning('Tracking job: link not found', ['link_id' => $linkId]);

            return;
        }

        $startTime = $payload['start_time'] ?? microtime(true);
        $ip = $payload['ip'] ?? '0.0.0.0';
        $userAgent = $payload['user_agent'] ?? 'Unknown';
        $referer = $payload['referer'] ?? null;
        $acceptLanguage = $payload['accept_language'] ?? null;
        $queryParams = $payload['query_params'] ?? [];

        $locationData = $this->resolveDetailedLocation($ip);
        $deviceData = $this->parseUserAgent($userAgent);
        $temporalData = $this->enrichTemporalData(now(), $locationData['timezone']);
        $behaviorData = $this->analyzeVisitorBehavior($ip, $link->id, $referer);
        $performanceData = $this->collectPerformanceData($acceptLanguage, $startTime);

        $click = Click::create(array_merge([
            'link_id' => $link->id,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'referer' => $referer,
            'country' => $locationData['country'],
            'city' => $locationData['city'],
            'device' => $this->resolveDevice($userAgent),
            'iso_code' => $locationData['iso_code'],
            'state' => $locationData['state'],
            'state_name' => $locationData['state_name'],
            'postal_code' => $locationData['postal_code'],
            'latitude' => $locationData['latitude'],
            'longitude' => $locationData['longitude'],
            'timezone' => $locationData['timezone'],
            'continent' => $locationData['continent'],
            'currency' => $locationData['currency'],
        ], $deviceData, $temporalData, $behaviorData, $performanceData));

        $utm = $this->extractUtm($queryParams, $referer);

        if (! empty($utm)) {
            LinkUtm::create(array_merge(['click_id' => $click->id], $utm));
        }

        Log::info('Click registered', [
            'link_id' => $link->id,
            'slug' => $link->slug,
            'ip' => $ip,
            'country' => $locationData['country'],
            'state' => $locationData['state'],
            'city' => $locationData['city'],
            'device' => $this->resolveDevice($userAgent),
            'referer' => $referer,
            'utm_data' => $utm,
        ]);
    }

    /**
     * Resolve o IP real do cliente antes de dispatchar o job assíncrono
     * (o Job não tem acesso ao Request).
     *
     * Prioridade: real_ip query param → X-Real-IP → X-Forwarded-For (primeiro IP) → CF-Connecting-IP → request->ip().
     */
    public function resolveRealUserIP(Request $request): string
    {
        if ($realIP = $request->query('real_ip')) {
            $cleanIP = trim($realIP);
            if ($this->isValidIP($cleanIP)) {
                return $cleanIP;
            }
        }

        if ($realIP = $request->header('X-Real-IP')) {
            $cleanIP = trim($realIP);
            if ($this->isValidIP($cleanIP)) {
                return $cleanIP;
            }
        }

        if ($forwardedFor = $request->header('X-Forwarded-For')) {
            $ips = array_map('trim', explode(',', $forwardedFor));
            $clientIP = $ips[0];

            if ($this->isValidIP($clientIP)) {
                return $clientIP;
            }
        }

        if ($cfIP = $request->header('CF-Connecting-IP')) {
            $cleanIP = trim($cfIP);
            if ($this->isValidIP($cleanIP)) {
                return $cleanIP;
            }
        }

        return $request->ip() ?: '127.0.0.1';
    }

    private function extractUtm(array $queryParams, ?string $referer): array
    {
        $fromQuery = array_filter(array_intersect_key($queryParams, array_flip(self::UTM_KEYS)));

        if (! empty($fromQuery)) {
            return $fromQuery;
        }

        if ($referer) {
            return $this->extractUtmFromReferer($referer);
        }

        return [];
    }

    private function extractUtmFromReferer(string $referer): array
    {
        $parsedUrl = parse_url($referer);

        if (! isset($parsedUrl['query'])) {
            return [];
        }

        parse_str($parsedUrl['query'], $queryParams);

        return array_intersect_key($queryParams, array_flip(self::UTM_KEYS));
    }

    private function resolveDetailedLocation(?string $ip): array
    {
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

        if (! $ip || in_array($ip, ['127.0.0.1', '::1'])) {
            return $defaultData;
        }

        try {
            $geoip = app('geoip');
            $location = $geoip->getLocation($ip);

            if (! $location->default) {
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
                    'timezone' => $location->timezone,
                ];
            }

            Log::warning('GeoIP returned default location for IP: '.$ip);

            return $defaultData;
        } catch (\Exception $e) {
            Log::warning('GeoIP lookup failed: '.$e->getMessage(), [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return $defaultData;
        }
    }

    private function resolveDevice(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

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

    private function parseUserAgent(string $userAgent): array
    {
        try {
            $agent = new Agent;
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
                'error' => $e->getMessage(),
            ]);

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

    private function enrichTemporalData(\DateTimeInterface $timestamp, ?string $timezone): array
    {
        try {
            $localTime = \DateTime::createFromInterface($timestamp);

            if ($timezone) {
                try {
                    $localTime->setTimezone(new \DateTimeZone($timezone));
                } catch (\Exception $e) {
                    Log::warning('Invalid timezone', ['timezone' => $timezone]);
                }
            }

            $hour = (int) $localTime->format('H');
            $dayOfWeek = (int) $localTime->format('N');

            return [
                'hour_of_day' => $hour,
                'day_of_week' => $dayOfWeek,
                'day_of_month' => (int) $localTime->format('d'),
                'month' => (int) $localTime->format('m'),
                'year' => (int) $localTime->format('Y'),
                'local_time' => $localTime->format('Y-m-d H:i:s'),
                'is_weekend' => in_array($dayOfWeek, [6, 7]),
                'is_business_hours' => $hour >= 9 && $hour <= 17,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to enrich temporal data', [
                'error' => $e->getMessage(),
                'timezone' => $timezone,
            ]);

            $hour = (int) $timestamp->format('H');
            $dayOfWeek = (int) $timestamp->format('N');

            return [
                'hour_of_day' => $hour,
                'day_of_week' => $dayOfWeek,
                'day_of_month' => (int) $timestamp->format('d'),
                'month' => (int) $timestamp->format('m'),
                'year' => (int) $timestamp->format('Y'),
                'local_time' => $timestamp->format('Y-m-d H:i:s'),
                'is_weekend' => in_array($dayOfWeek, [6, 7]),
                'is_business_hours' => $hour >= 9 && $hour <= 17,
            ];
        }
    }

    private function analyzeVisitorBehavior(string $ip, int $linkId, ?string $referer): array
    {
        try {
            $recentClicks = Click::where('ip', $ip)
                ->where('created_at', '>=', now()->subDay())
                ->count();

            $sessionClicks = Click::where('ip', $ip)
                ->where('created_at', '>=', now()->subHour())
                ->count() + 1;

            return [
                'is_return_visitor' => $recentClicks > 0,
                'session_clicks' => $sessionClicks,
                'click_source' => $this->categorizeClickSource($referer),
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to analyze visitor behavior', [
                'ip' => $ip,
                'link_id' => $linkId,
                'error' => $e->getMessage(),
            ]);

            return [
                'is_return_visitor' => false,
                'session_clicks' => 1,
                'click_source' => 'unknown',
            ];
        }
    }

    private function categorizeClickSource(?string $referer): string
    {
        if (! $referer || $referer === '-') {
            return 'direct';
        }

        $domain = parse_url($referer, PHP_URL_HOST);

        if (! $domain) {
            return 'unknown';
        }

        $domain = strtolower($domain);

        if (preg_match('/(facebook|twitter|instagram|linkedin|tiktok|youtube|whatsapp|telegram)/i', $domain)) {
            return 'social';
        }

        if (preg_match('/(google|bing|yahoo|duckduckgo|baidu|yandex)/i', $domain)) {
            return 'search';
        }

        if (preg_match('/(gmail|outlook|mail|webmail|hotmail)/i', $domain)) {
            return 'email';
        }

        return 'referral';
    }

    private function collectPerformanceData(?string $acceptLanguage, float $startTime): array
    {
        try {
            $responseTime = (microtime(true) - $startTime) * 1000;

            return [
                'response_time' => round($responseTime, 3),
                'accept_language' => $acceptLanguage,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to collect performance data', [
                'error' => $e->getMessage(),
            ]);

            return [
                'response_time' => null,
                'accept_language' => null,
            ];
        }
    }

    private function isValidIP(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (config('app.env') === 'production') {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
