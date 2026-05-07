<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use App\Models\Click;
use App\Models\Link;
use App\Models\LinkUtm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;

class LinkTrackingService
{
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Registra clique a partir de payload serializável (extraído do Request no controller).
     * Ponto de entrada usado pelo ProcessLinkClickJob.
     *
     * Applies enrichments in order:
     *   - Phase 1: navigation context (Sec-Fetch-*), language parsing
     *   - Phase 2: viral rank (ClickVelocityService), holiday, season,
     *              connection type, rendering engine
     *
     * @param  int  $linkId  ID of the link being clicked
     * @param  array{
     *     ip?: string,
     *     user_agent?: ?string,
     *     referer?: ?string,
     *     accept_language?: ?string,
     *     query_params?: array<string, string>,
     *     http_response_ms?: float,
     *     sec_fetch_site?: ?string,
     *     sec_fetch_mode?: ?string,
     *     sec_fetch_dest?: ?string,
     *     ch_platform?: ?string,
     *     ch_is_mobile?: ?bool,
     *     save_data?: bool,
     *     server_protocol?: ?string
     * }  $payload  Serializable request payload extracted in the controller
     * @return void
     */
    public function registrarCliqueFromPayload(int $linkId, array $payload): void
    {
        $link = Link::find($linkId);

        if (! $link) {
            AppLogger::trackingLinkNotFound($linkId);

            return;
        }

        $httpResponseMs = $payload['http_response_ms'] ?? null;
        $ip = $payload['ip'] ?? '0.0.0.0';
        $userAgent = $payload['user_agent'] ?? 'Unknown';
        $referer = $payload['referer'] ?? null;
        $acceptLanguage = $payload['accept_language'] ?? null;
        $queryParams = $payload['query_params'] ?? [];

        $locationData = $this->resolveDetailedLocation($ip);
        $deviceData = $this->parseUserAgent($userAgent);
        $temporalData = $this->enrichTemporalData(now(), $locationData['timezone']);
        $behaviorData = $this->analyzeVisitorBehavior($ip, $link->id, $referer);
        $performanceData = $this->collectPerformanceData($acceptLanguage, $httpResponseMs);

        $navigationData = $this->enrichNavigationContext($payload);
        $languageData   = $this->parseAcceptLanguage($payload['accept_language'] ?? null);

        // Phase 2 — contextual intelligence
        $velocityData   = app(\App\Services\Links\ClickVelocityService::class)->record($link->id);

        $isoCode        = $locationData['iso_code'] ?? '';
        $holidayData    = $isoCode
            ? $this->enrichHoliday($isoCode, now())
            : ['is_holiday' => null, 'holiday_name' => null];
        $seasonData     = $isoCode
            ? ['season' => $this->enrichSeason($isoCode, now())]
            : ['season' => null];
        $connectionData = ['connection_type' => $this->classifyConnectionType($locationData['isp'] ?? null)];
        $engineData     = ['rendering_engine' => $this->deriveRenderingEngine($deviceData['browser'] ?? null)];

        // Phase 3 — quality scoring
        $allFields   = array_merge($deviceData, $behaviorData, $navigationData, $velocityData, $connectionData);
        $qualityData = $this->calculateQualityScore($allFields);

        $click = Click::create(array_merge([
            'link_id'      => $link->id,
            'ip'           => $ip,
            'user_agent'   => $userAgent,
            'referer'      => $referer,
            'country'      => $locationData['country'],
            'city'         => $locationData['city'],
            'device'       => $this->resolveDevice($userAgent),
            'iso_code'     => $locationData['iso_code'],
            'state'        => $locationData['state'],
            'state_name'   => $locationData['state_name'],
            'postal_code'  => $locationData['postal_code'],
            'latitude'     => $locationData['latitude'],
            'longitude'    => $locationData['longitude'],
            'timezone'     => $locationData['timezone'],
            'continent'    => $locationData['continent'],
            'currency'     => $locationData['currency'],
        ], $deviceData, $temporalData, $behaviorData, $performanceData,
           $navigationData, $languageData,
           $velocityData, $holidayData, $seasonData, $connectionData, $engineData,
           $qualityData));

        DB::table('links')->where('id', $link->id)->increment('clicks');

        $utm = $this->extractUtm($queryParams, $referer);

        if (! empty($utm)) {
            LinkUtm::create(array_merge(['click_id' => $click->id], $utm));
        }

        AppLogger::trackingClickRegistered($click->id, $link->id, [
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

            AppLogger::geoipDefaultLocation($ip);

            return $defaultData;
        } catch (\Exception $e) {
            AppLogger::geoipFailed($ip, $e);

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
            AppLogger::userAgentParseFailed($userAgent, $e);

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
                    AppLogger::event('tracking', 'warning', 'tracking.invalid_timezone', ['timezone' => $timezone]);
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
            AppLogger::trackingTemporalEnrichmentFailed($e, ['timezone' => $timezone]);

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
            AppLogger::trackingBehaviorAnalysisFailed($e, ['ip' => $ip, 'link_id' => $linkId]);

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

    private function collectPerformanceData(?string $acceptLanguage, ?float $httpResponseMs): array
    {
        return [
            'response_time' => $httpResponseMs !== null ? round($httpResponseMs, 3) : null,
            'accept_language' => $acceptLanguage,
        ];
    }

    /**
     * Derives a semantic navigation context from Sec-Fetch-* headers.
     *
     * Uses Sec-Fetch-Site and Sec-Fetch-Mode to produce a reliable attribution
     * category that is more accurate than the Referer header, which browsers
     * may suppress via Referrer-Policy.
     *
     * @param  array{
     *     sec_fetch_site?: ?string,
     *     sec_fetch_mode?: ?string,
     *     sec_fetch_dest?: ?string,
     *     ch_platform?: ?string,
     *     ch_is_mobile?: ?bool,
     *     save_data?: bool,
     *     server_protocol?: ?string
     * } $payload
     * @return array{navigation_context: string, fetch_dest: ?string, ch_platform: ?string, ch_is_mobile: ?bool, is_data_saver: bool, http_protocol: ?string}
     */
    private function enrichNavigationContext(array $payload): array
    {
        $site = $payload['sec_fetch_site'] ?? null;
        $mode = $payload['sec_fetch_mode'] ?? null;

        $context = match(true) {
            in_array($mode, ['prefetch', 'preload'], true)                              => 'preload',
            $site === 'none' && $mode === 'navigate'                                    => 'browser_direct',
            in_array($site, ['cross-site', 'same-site'], true) && $mode === 'navigate' => 'browser_referral',
            in_array($site, ['cross-site', 'same-site'], true) && $mode === 'no-cors'  => 'in_app_webview',
            $site === null && $mode === null                                             => 'api_programmatic',
            default                                                                     => 'browser_referral',
        };

        return [
            'navigation_context' => $context,
            'fetch_dest'         => $payload['sec_fetch_dest'] ?? null,
            'ch_platform'        => !empty($payload['ch_platform']) ? $payload['ch_platform'] : null,
            'ch_is_mobile'       => $payload['ch_is_mobile'] ?? null,
            'is_data_saver'      => (bool) ($payload['save_data'] ?? false),
            'http_protocol'      => $payload['server_protocol'] ?? null,
        ];
    }

    /**
     * Parses the Accept-Language header into primary language code and region.
     *
     * Extracts the highest-priority language tag from the quality-weighted list.
     * Correctly handles complex subtags like script codes (e.g. zh-Hant-TW → language=zh, region=TW).
     * Example: "pt-BR,pt;q=0.9,en;q=0.8" → ['primary_language' => 'pt', 'language_region' => 'BR']
     *
     * @param  string|null $raw  Raw Accept-Language header value
     * @return array{primary_language: ?string, language_region: ?string}
     */
    private function parseAcceptLanguage(?string $raw): array
    {
        if (!$raw) {
            return ['primary_language' => null, 'language_region' => null];
        }
        $first  = trim(explode(';', explode(',', $raw)[0])[0]);
        $parts  = explode('-', $first);
        $lang   = strtolower($parts[0]) ?: null;
        // Skip script subtags (e.g. "Hant" in zh-Hant-TW) — only take 2-letter region codes
        $region = null;
        foreach (array_slice($parts, 1) as $subtag) {
            if (strlen($subtag) === 2) {
                $region = strtoupper($subtag);
                break;
            }
        }
        return ['primary_language' => $lang, 'language_region' => $region];
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

    /**
     * Determines the calendar season for the given ISO country code and datetime.
     *
     * Accounts for southern hemisphere inversion — BR, AR, AU, etc. experience
     * opposite seasons to northern-hemisphere countries at the same time of year.
     *
     * @param  string             $isoCode   ISO 3166-1 alpha-2 country code (e.g. 'BR', 'US')
     * @param  \DateTimeInterface $clickedAt Click timestamp (used for month extraction)
     * @return string One of: spring, summer, fall, winter
     */
    private function enrichSeason(string $isoCode, \DateTimeInterface $clickedAt): string
    {
        $month    = (int) $clickedAt->format('n');
        $southern = ['BR', 'AR', 'CL', 'AU', 'NZ', 'ZA', 'PE', 'BO', 'PY', 'UY'];
        $isSouth  = in_array(strtoupper($isoCode), $southern, true);

        $northSeason = match(true) {
            in_array($month, [12, 1, 2], true) => 'winter',
            in_array($month, [3, 4, 5], true)  => 'spring',
            in_array($month, [6, 7, 8], true)  => 'summer',
            default                            => 'fall',
        };

        if (! $isSouth) {
            return $northSeason;
        }

        return match($northSeason) {
            'winter' => 'summer',
            'spring' => 'fall',
            'summer' => 'winter',
            'fall'   => 'spring',
        };
    }

    /**
     * Classifies the ISP name into a connection type category.
     *
     * Uses keyword matching against the ISP string from GeoIP. Returns 'unknown'
     * when ISP is null (e.g. free GeoLite2-City without ISP data).
     *
     * @param  string|null $isp  ISP name from GeoIP lookup, or null
     * @return string One of: datacenter, mobile, education, residential, unknown
     */
    private function classifyConnectionType(?string $isp): string
    {
        if (! $isp) {
            return 'unknown';
        }

        $lower = strtolower($isp);

        $datacenter = ['amazon', 'google', 'digitalocean', 'hetzner', 'ovh', 'vultr',
                       'linode', 'azure', 'cloudflare', 'akamai', 'fastly', 'microsoft'];
        $mobile     = ['claro', 'vivo', 'tim ', 'oi ', 'nextel', 't-mobile',
                       'verizon', 'at&t', 'sprint', 'net mobile'];
        $education  = ['university', 'universidade', 'instituto', 'college', 'escola'];

        foreach ($datacenter as $kw) {
            if (str_contains($lower, $kw)) {
                return 'datacenter';
            }
        }
        foreach ($mobile as $kw) {
            if (str_contains($lower, $kw)) {
                return 'mobile';
            }
        }
        foreach ($education as $kw) {
            if (str_contains($lower, $kw)) {
                return 'education';
            }
        }

        return 'residential';
    }

    /**
     * Derives the browser rendering engine from the parsed browser name.
     *
     * Maps browser names (as returned by jenssegers/agent) to their underlying
     * rendering engine family.
     *
     * @param  string|null $browser  Browser name from parseUserAgent(), e.g. 'Chrome', 'Safari'
     * @return string One of: blink, gecko, webkit, trident, unknown
     */
    private function deriveRenderingEngine(?string $browser): string
    {
        return match(true) {
            in_array($browser, ['Chrome', 'Edge', 'Opera', 'Brave', 'Vivaldi'], true) => 'blink',
            in_array($browser, ['Firefox'], true)                                      => 'gecko',
            in_array($browser, ['Safari'], true)                                       => 'webkit',
            in_array($browser, ['IE'], true)                                           => 'trident',
            default                                                                    => 'unknown',
        };
    }

    /**
     * Checks whether the click timestamp falls on a national holiday.
     *
     * Uses the azuyalabs/yasumi library. Returns null values for countries not
     * supported by yasumi or when the library throws. Requires `composer require
     * azuyalabs/yasumi` to have been run (Docker must be up for this step).
     *
     * @param  string             $isoCode   ISO 3166-1 alpha-2 country code
     * @param  \DateTimeInterface $clickedAt Click timestamp
     * @return array{is_holiday: ?bool, holiday_name: ?string}
     */
    private function enrichHoliday(string $isoCode, \DateTimeInterface $clickedAt): array
    {
        $supported = [
            'BR' => 'Brazil',      'US' => 'USA',         'GB' => 'UnitedKingdom',
            'DE' => 'Germany',     'FR' => 'France',       'PT' => 'Portugal',
            'ES' => 'Spain',       'NL' => 'Netherlands',  'AU' => 'Australia',
            'CA' => 'Canada',
        ];

        $iso = strtoupper($isoCode);
        if (! isset($supported[$iso])) {
            return ['is_holiday' => null, 'holiday_name' => null];
        }

        try {
            $year      = (int) $clickedAt->format('Y');
            $holidays  = \Yasumi\Yasumi::create($supported[$iso], $year);
            $date      = new \DateTime($clickedAt->format('Y-m-d'));
            $isHoliday = $holidays->isHoliday($date);

            return [
                'is_holiday'   => $isHoliday,
                'holiday_name' => $isHoliday ? ($holidays->whatObservance($date)?->getName() ?? null) : null,
            ];
        } catch (\Throwable) {
            return ['is_holiday' => null, 'holiday_name' => null];
        }
    }

    /**
     * Calculates a composite click quality score from server-side signals.
     *
     * Starts from 100 and applies deductions for each fraud/bot indicator found
     * in the merged click fields. All signals are derived from data already
     * collected in Phases 1 and 2 — no external API calls are made.
     *
     * Returns quality_score (0–100), quality_tier (organic|suspicious|likely_fraud),
     * and fingerprint_score (count of header inconsistencies 0–3).
     *
     * @param  array<string, mixed>  $fields  Merged click data (device + behavior + navigation)
     * @return array{quality_score: int, quality_tier: string, fingerprint_score: int}
     */
    private function calculateQualityScore(array $fields): array
    {
        if ($fields['is_bot'] ?? false) {
            return ['quality_score' => 0, 'quality_tier' => 'likely_fraud', 'fingerprint_score' => 0];
        }

        $score       = 100;
        $fingerprint = 0;

        if (($fields['connection_type'] ?? 'unknown') === 'datacenter') {
            $score -= 25;
        }

        if (($fields['navigation_context'] ?? null) === 'api_programmatic') {
            $score -= 15;
        }

        if (empty($fields['ch_platform'])) {
            $score -= 10;
        }

        $secsSince = $fields['seconds_since_last_click'] ?? null;
        if ($secsSince !== null && $secsSince < 2) {
            $score -= 20;
        }

        if (($fields['session_clicks'] ?? 1) > 10) {
            $score -= 15;
        }

        if (($fields['session_clicks'] ?? 1) > 5 && ($fields['is_return_visitor'] ?? false)) {
            $score -= 5;
        }

        if (empty($fields['accept_language'])) {
            $score -= 5;
        }

        $chMobile = $fields['ch_is_mobile'] ?? null;
        $uaMobile = $fields['is_mobile']    ?? false;
        if ($chMobile !== null && (bool) $chMobile !== (bool) $uaMobile) {
            $fingerprint++;
            $score -= 10;
        }

        $browser    = $fields['browser']     ?? null;
        $chPlatform = $fields['ch_platform'] ?? null;
        if (in_array($browser, ['Chrome', 'Edge'], true) && empty($chPlatform)) {
            $fingerprint++;
        }

        $score       = max(0, $score);
        $qualityTier = match(true) {
            $score >= 80 => 'organic',
            $score >= 50 => 'suspicious',
            default      => 'likely_fraud',
        };

        return [
            'quality_score'     => $score,
            'quality_tier'      => $qualityTier,
            'fingerprint_score' => $fingerprint,
        ];
    }
}
