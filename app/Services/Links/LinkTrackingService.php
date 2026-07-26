<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use App\Models\Click;
use App\Models\Link;
use App\Models\LinkUtm;
use App\Support\ClientIpResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;

/**
 * Enriches and persists click tracking data for a link redirect event.
 *
 * This service is the SINGLE write path for the clicks table and for the
 * denormalized links.clicks counter. It is invoked exclusively by
 * ProcessLinkClickJob — never called synchronously from a request lifecycle.
 *
 * Enrichment pipeline (3 phases):
 *   Phase 1 — navigation context: Sec-Fetch-* headers, Accept-Language parsing,
 *              Client Hints (ch_platform, ch_is_mobile), Save-Data, HTTP protocol.
 *   Phase 2 — contextual intelligence: viral rank via ClickVelocityService (Redis
 *              sliding windows), holiday detection (azuyalabs/yasumi), hemisphere-
 *              aware season, ISP connection type classification, rendering engine.
 *   Phase 3 — quality scoring: composite bot/fraud score (0–100), tier assignment
 *              (organic | suspicious | likely_fraud), header-fingerprint count.
 *
 * DI graph: ClickVelocityService is constructor-injected (since R-12 — previously
 * instantiated inline). All other helpers (GeoIP, Agent) are instantiated locally.
 *
 * Side effects:
 *   - Reads: links (to confirm link exists), clicks (return visitor check)
 *   - Writes: clicks (insertOrIgnore keyed on the UNIQUE dedup_key — durable
 *     retry idempotency), link_utms (LinkUtm::create if UTM present)
 *   - Increment: DB::table('links')->where('id', $link->id)->increment('clicks')
 *     ONLY when a new clicks row was actually inserted (a dedup_key collision is
 *     a no-op); raw query bypasses model observers, keeping the Link cache stable
 *   - Logs: tracking channel via AppLogger::trackingClickRegistered on success;
 *     AppLogger::trackingLinkNotFound, geoip*, userAgent*, behavior* on error paths
 *
 * Schema migrations this service writes into:
 *   - Phase 1: navigation_context, fetch_dest, ch_platform, ch_is_mobile,
 *              is_data_saver, http_protocol, primary_language, language_region
 *   - Phase 2: viral_rank, seconds_since_last_click, is_holiday, holiday_name,
 *              season, connection_type, rendering_engine
 *   - Phase 3: quality_score, quality_tier, fingerprint_score
 */
class LinkTrackingService
{
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Well-known datacenter IP prefixes used as a lightweight fallback when the
     * GeoIP provider (GeoLite2-City) does not return ISP data. Covers the most
     * common public cloud ranges: AWS, GCP, Cloudflare, and DigitalOcean.
     * Prefix-match only (not full CIDR) — sufficient for the highest-volume ranges.
     */
    private const DATACENTER_CIDR_PREFIXES = [
        '3.',       // AWS us-east
        '13.',      // AWS
        '18.',      // AWS
        '34.',      // GCP
        '35.',      // GCP
        '104.',     // Cloudflare / various
        '130.211.', // GCP
        '146.148.', // GCP
        '162.243.', // DigitalOcean
        '167.99.',  // DigitalOcean
        '192.241.', // DigitalOcean
        '198.199.', // DigitalOcean
        '207.154.', // DigitalOcean
        '45.55.',   // DigitalOcean
        '52.',      // AWS
        '54.',      // AWS
    ];

    /**
     * Lazily-opened GeoLite2-ASN reader; null until first use or when the DB file
     * is absent. Typed as mixed because geoip2/geoip2 is an optional dependency —
     * the class may not exist in environments that have not installed it.
     */
    private mixed $asnReader = null;

    /**
     * @param  ClickVelocityService  $clickVelocityService  Constructor-injected (R-12); tracks click
     *                                                      velocity via Redis sliding windows and falls
     *                                                      back gracefully when Redis is unavailable.
     * @param  ClientIpResolver  $clientIpResolver  Shared client-IP resolver (single source of truth for
     *                                              proxy/CDN header precedence). Defaults to a fresh instance
     *                                              so the unit tests that build this service by hand keep working.
     */
    public function __construct(
        private readonly ClickVelocityService $clickVelocityService,
        private readonly ClientIpResolver $clientIpResolver = new ClientIpResolver,
    ) {}

    /**
     * Registers a click from a serializable payload extracted before the HTTP response was sent.
     *
     * This is the sole public entry point and is called only from ProcessLinkClickJob.
     * The payload is assembled in RedirectController::dispatchTracking() and queued
     * so that the 302 response is returned to the visitor before tracking begins.
     *
     * Applies enrichments in order:
     *   1. Basic geo/device/temporal/behavior/performance (always)
     *   2. Phase 1: navigation context (Sec-Fetch-*), Accept-Language parsing
     *   3. Phase 2: viral rank, holiday, season, connection type, rendering engine
     *   4. Phase 3: quality scoring
     *
     * The denormalized click counter is incremented via a direct DB query
     * (DB::table('links')->where('id', ...)->increment('clicks')) to avoid
     * firing model observers and invalidating the Link slug cache. The insert
     * is idempotent under retry via the UNIQUE clicks.dedup_key constraint: a
     * collision is silently ignored and the counter is NOT incremented again.
     *
     * @param  int  $linkId  Primary key of the link being clicked.
     * @param  array{
     *     dedup_key?: ?string,
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
     * } $payload  Serializable request snapshot assembled in RedirectController.
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
        $languageData = $this->parseAcceptLanguage($payload['accept_language'] ?? null);

        // Phase 2 — contextual intelligence
        $velocityData = $this->clickVelocityService->record($link->id);

        $isoCode = $locationData['iso_code'] ?? '';
        $holidayData = $isoCode
            ? $this->enrichHoliday($isoCode, now())
            : ['is_holiday' => null, 'holiday_name' => null];
        $seasonData = $isoCode
            ? ['season' => $this->enrichSeason($isoCode, now())]
            : ['season' => null];
        $connectionData = ['connection_type' => $this->classifyConnectionType($locationData['isp'] ?? null, $ip)];
        $engineData = ['rendering_engine' => $this->deriveRenderingEngine($deviceData['browser'] ?? null)];

        // Phase 3 — quality scoring
        $allFields = array_merge($deviceData, $behaviorData, $navigationData, $velocityData, $connectionData, $performanceData);
        $qualityData = $this->calculateQualityScore($allFields);

        $dedupKey = $payload['dedup_key'] ?? null;

        $attributes = array_merge([
            'dedup_key' => $dedupKey,
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
        ], $deviceData, $temporalData, $behaviorData, $performanceData,
            $navigationData, $languageData,
            $velocityData, $holidayData, $seasonData, $connectionData, $engineData,
            $qualityData);

        $click = $this->insertClickIdempotently($attributes);

        // A null return means the dedup_key collided with an already-persisted
        // row (a retry of the same click). The DB unique constraint is the
        // durable source of truth here, so we must NOT count the click again
        // and must NOT create a duplicate UTM row — even if the Cache fast-path
        // was unavailable. The job treats this as a successful no-op.
        if ($click === null) {
            AppLogger::event('tracking', 'info', 'tracking.duplicate_ignored', [
                'link_id' => $link->id,
                'dedup_key' => $dedupKey,
            ]);

            return;
        }

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
     * Inserts a click row, treating a dedup_key collision as a no-op.
     *
     * This is the durable idempotency guarantee for click persistence. The
     * insert is performed via the query builder's insertOrIgnore() so a UNIQUE
     * constraint violation on dedup_key (a retry of the same click) is silently
     * ignored at the database level instead of throwing — this holds even when
     * the Cache fast-path is completely unavailable.
     *
     * Behavior:
     *   - New row inserted → returns the freshly persisted Click model so the
     *     caller can increment the counter and attach UTM data.
     *   - Row already existed (insertOrIgnore affected 0 rows) → returns null;
     *     the caller skips the counter increment and UTM creation.
     *   - dedup_key is null (legacy/edge events) → NULLs are distinct in both
     *     PostgreSQL and SQLite unique indexes, so the row always inserts,
     *     preserving the previous at-least-once semantics.
     *
     * Timestamps are set explicitly because insertOrIgnore() bypasses Eloquent
     * (no model events, no automatic created_at/updated_at), matching the
     * existing direct-query convention used for the links.clicks counter.
     *
     * @param  array<string, mixed>  $attributes  Fully built click row (must include dedup_key + link_id).
     * @return \App\Models\Click|null The inserted Click, or null when a duplicate was ignored.
     */
    private function insertClickIdempotently(array $attributes): ?Click
    {
        // Defensively clamp every bounded column to its width before the raw
        // insertOrIgnore (which bypasses Eloquent mutators): a client-supplied
        // header longer than its column — e.g. a Facebook l.php `referer` — must
        // never abort the insert and silently drop the click. See Click::COLUMN_LIMITS.
        $attributes = Click::clampToColumnLimits($attributes);

        $now = now();
        $row = array_merge($attributes, [
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $inserted = Click::query()->insertOrIgnore($row) > 0;

        if (! $inserted) {
            return null;
        }

        // Re-read the row we just inserted. For events carrying a dedup_key we
        // can locate it by that UNIQUE key; otherwise (null dedup_key) fall back
        // to the most recent row for this link/IP — that row was just created by
        // this call, since insertOrIgnore reported a new insert.
        $query = Click::query();

        if (! empty($attributes['dedup_key'])) {
            $query->where('dedup_key', $attributes['dedup_key']);
        } else {
            $query->where('link_id', $attributes['link_id'])
                ->where('ip', $attributes['ip'] ?? null)
                ->orderByDesc('id');
        }

        return $query->first();
    }

    /**
     * Resolves the real client IP before the async job is dispatched.
     *
     * The Job has no access to the Request object, so this must be called
     * synchronously in RedirectController::dispatchTracking() before queuing.
     *
     * Priority chain (first valid public IP wins):
     *   1. ?real_ip query parameter (dev/proxy override)
     *   2. X-Real-IP header (nginx proxy)
     *   3. X-Forwarded-For header, first token (CDN/load balancer)
     *   4. CF-Connecting-IP header (Cloudflare)
     *   5. Request::ip() fallback (or '127.0.0.1' when that is also empty)
     *
     * In production, private/reserved ranges are excluded via FILTER_FLAG_NO_PRIV_RANGE
     * | FILTER_FLAG_NO_RES_RANGE — this prevents internal proxy IPs from being stored.
     *
     * Delegates the actual precedence/validation logic to the shared ClientIpResolver
     * (single source of truth, also used by RedirectMetricsCollector).
     *
     * @param  Request  $request  The current HTTP request.
     * @return string A valid IP address string.
     */
    public function resolveRealUserIP(Request $request): string
    {
        return $this->clientIpResolver->fromRequest($request);
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
                'social_platform' => $this->detectSocialPlatform($referer),
            ];
        } catch (\Exception $e) {
            AppLogger::trackingBehaviorAnalysisFailed($e, ['ip' => $ip, 'link_id' => $linkId]);

            return [
                'is_return_visitor' => false,
                'session_clicks' => 1,
                'click_source' => 'unknown',
                'social_platform' => null,
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

    /**
     * Identifies the specific social platform from a referer URL.
     *
     * Returns null when click_source is not 'social' or the domain is not a
     * known social proxy. Called alongside categorizeClickSource() so both
     * fields are derived from the same referer string.
     *
     * Short-domain checks (t.co, wa.me, t.me) use exact-match or suffix-match
     * to prevent substring false-positives (e.g. 'not.me.evil.com' must not
     * match 't.me'). t.co is Twitter's redirect domain and never has subdomains,
     * so === is sufficient. wa.me and t.me may have subdomains, so str_ends_with
     * with the leading dot is used in addition to the exact-match check.
     *
     * @param  string|null  $referer  HTTP Referer header value.
     * @return string|null Platform slug (instagram|tiktok|facebook|youtube|twitter|whatsapp|telegram|linkedin) or null.
     */
    private function detectSocialPlatform(?string $referer): ?string
    {
        if (! $referer || $referer === '-') {
            return null;
        }

        $domain = strtolower(parse_url($referer, PHP_URL_HOST) ?? '');

        return match (true) {
            str_contains($domain, 'instagram.com') => 'instagram',
            str_contains($domain, 'tiktok.com') => 'tiktok',
            str_contains($domain, 'facebook.com') || str_contains($domain, 'fb.com') => 'facebook',
            str_contains($domain, 'youtube.com') || str_contains($domain, 'youtu.be') => 'youtube',
            str_contains($domain, 'twitter.com') || $domain === 't.co' || str_contains($domain, 'x.com') => 'twitter',
            str_contains($domain, 'whatsapp.com') || $domain === 'wa.me' || str_ends_with($domain, '.wa.me') => 'whatsapp',
            str_contains($domain, 'telegram.org') || $domain === 't.me' || str_ends_with($domain, '.t.me') => 'telegram',
            str_contains($domain, 'linkedin.com') => 'linkedin',
            default => null,
        };
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

        $context = match (true) {
            in_array($mode, ['prefetch', 'preload'], true) => 'preload',
            $site === 'none' && $mode === 'navigate' => 'browser_direct',
            in_array($site, ['cross-site', 'same-site'], true) && $mode === 'navigate' => 'browser_referral',
            in_array($site, ['cross-site', 'same-site'], true) && $mode === 'no-cors' => 'in_app_webview',
            $site === null && $mode === null => 'api_programmatic',
            default => 'browser_referral',
        };

        return [
            'navigation_context' => $context,
            'fetch_dest' => $payload['sec_fetch_dest'] ?? null,
            'ch_platform' => ! empty($payload['ch_platform']) ? $payload['ch_platform'] : null,
            'ch_is_mobile' => $payload['ch_is_mobile'] ?? null,
            'is_data_saver' => (bool) ($payload['save_data'] ?? false),
            'http_protocol' => $payload['server_protocol'] ?? null,
        ];
    }

    /**
     * Parses the Accept-Language header into primary language code and region.
     *
     * Extracts the highest-priority language tag from the quality-weighted list.
     * Correctly handles complex subtags like script codes (e.g. zh-Hant-TW → language=zh, region=TW).
     * Example: "pt-BR,pt;q=0.9,en;q=0.8" → ['primary_language' => 'pt', 'language_region' => 'BR']
     *
     * @param  string|null  $raw  Raw Accept-Language header value
     * @return array{primary_language: ?string, language_region: ?string}
     */
    private function parseAcceptLanguage(?string $raw): array
    {
        if (! $raw) {
            return ['primary_language' => null, 'language_region' => null];
        }
        $first = trim(explode(';', explode(',', $raw)[0])[0]);
        $parts = explode('-', $first);
        $lang = strtolower($parts[0]) ?: null;
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

    /**
     * Determines the calendar season for the given ISO country code and datetime.
     *
     * Accounts for southern hemisphere inversion — BR, AR, AU, etc. experience
     * opposite seasons to northern-hemisphere countries at the same time of year.
     *
     * @param  string  $isoCode  ISO 3166-1 alpha-2 country code (e.g. 'BR', 'US')
     * @param  \DateTimeInterface  $clickedAt  Click timestamp (used for month extraction)
     * @return string One of: spring, summer, fall, winter
     */
    private function enrichSeason(string $isoCode, \DateTimeInterface $clickedAt): string
    {
        $month = (int) $clickedAt->format('n');
        $southern = ['BR', 'AR', 'CL', 'AU', 'NZ', 'ZA', 'PE', 'BO', 'PY', 'UY'];
        $isSouth = in_array(strtoupper($isoCode), $southern, true);

        $northSeason = match (true) {
            in_array($month, [12, 1, 2], true) => 'winter',
            in_array($month, [3, 4, 5], true) => 'spring',
            in_array($month, [6, 7, 8], true) => 'summer',
            default => 'fall',
        };

        if (! $isSouth) {
            return $northSeason;
        }

        return match ($northSeason) {
            'winter' => 'summer',
            'spring' => 'fall',
            'summer' => 'winter',
            'fall' => 'spring',
        };
    }

    /**
     * Resolve the autonomous-system organization name for an IP using the
     * optional GeoLite2-ASN database. Returns null when the database file is
     * not configured/present, the geoip2/geoip2 package is not installed, the IP
     * is not found, or the reader fails — callers then fall back to keyword/CIDR
     * classification.
     *
     * @param  string  $ip  Click IP address to look up.
     * @return string|null AS organization string (e.g. "DigitalOcean, LLC"), or null.
     */
    protected function lookupAsnOrganization(string $ip): ?string
    {
        $path = config('tracking.asn_database_path');

        if (! $path || ! is_file($path)) {
            return null;
        }

        // geoip2/geoip2 is an optional dependency; guard against environments
        // where it has not been installed via composer.
        if (! class_exists(\GeoIp2\Database\Reader::class)) {
            return null;
        }

        try {
            $this->asnReader ??= new \GeoIp2\Database\Reader($path);

            return $this->asnReader->asn($ip)->autonomousSystemOrganization;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Classifies the connection type into a named category.
     *
     * Resolution order:
     *   1. ISP string from GeoIP City (direct keyword match).
     *   2. AS organization from GeoLite2-ASN database (when present and geoip2/geoip2
     *      is installed) — feeds the same keyword classifier as step 1.
     *   3. DATACENTER_CIDR_PREFIXES prefix match against the click IP.
     *   4. Private/loopback ranges → 'residential'.
     *   5. No match → 'unknown'.
     *
     * Graceful degradation: if the GeoLite2-ASN.mmdb file is absent or the
     * geoip2/geoip2 package is not installed, step 2 is skipped and behavior
     * is identical to the legacy implementation.
     *
     * @param  string|null  $isp  ISP name from GeoIP lookup, or null
     * @param  string|null  $ip  Click IP address, used for ASN lookup and prefix-match fallback
     * @return string One of: datacenter, mobile, education, residential, unknown
     */
    private function classifyConnectionType(?string $isp, ?string $ip = null): string
    {
        // Prefer the real AS organization when GeoIP City lacks ISP data.
        if (! $isp && $ip) {
            $isp = $this->lookupAsnOrganization($ip);
        }

        if ($isp) {
            $lower = strtolower($isp);

            // Residential fiber ISPs whose org names contain datacenter keywords
            // (e.g. AS16591 "GOOGLE-FIBER") must not be penalized as datacenter
            // traffic by the quality score.
            if (str_contains($lower, 'fiber')) {
                return 'residential';
            }

            $datacenter = ['amazon', 'google', 'digitalocean', 'hetzner', 'ovh', 'vultr',
                'linode', 'azure', 'cloudflare', 'akamai', 'fastly', 'microsoft'];
            $mobile = ['claro', 'vivo', 'tim ', 'oi ', 'nextel', 't-mobile',
                'verizon', 'at&t', 'sprint', 'net mobile'];
            $education = ['university', 'universidade', 'instituto', 'college', 'escola'];

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

        // ISP unavailable — fall back to prefix-based datacenter detection.
        if ($ip) {
            // Localhost and private ranges are internal networks, not datacenters.
            foreach (['127.', '10.', '192.168.'] as $privatePrefix) {
                if (str_starts_with($ip, $privatePrefix)) {
                    return 'residential';
                }
            }
            // RFC 1918 172.16.0.0/12 covers 172.16.x.x–172.31.x.x
            if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip)) {
                return 'residential';
            }

            foreach (self::DATACENTER_CIDR_PREFIXES as $prefix) {
                if (str_starts_with($ip, $prefix)) {
                    return 'datacenter';
                }
            }
        }

        return 'unknown';
    }

    /**
     * Derives the browser rendering engine from the parsed browser name.
     *
     * Maps browser names (as returned by jenssegers/agent) to their underlying
     * rendering engine family.
     *
     * @param  string|null  $browser  Browser name from parseUserAgent(), e.g. 'Chrome', 'Safari'
     * @return string One of: blink, gecko, webkit, trident, unknown
     */
    private function deriveRenderingEngine(?string $browser): string
    {
        return match (true) {
            in_array($browser, ['Chrome', 'Edge', 'Opera', 'Brave', 'Vivaldi'], true) => 'blink',
            in_array($browser, ['Firefox'], true) => 'gecko',
            in_array($browser, ['Safari'], true) => 'webkit',
            in_array($browser, ['IE'], true) => 'trident',
            default => 'unknown',
        };
    }

    /**
     * Checks whether the click timestamp falls on a national holiday.
     *
     * Uses the azuyalabs/yasumi library. Returns null values for countries not
     * supported by yasumi or when the library throws. Requires `composer require
     * azuyalabs/yasumi` to have been run (Docker must be up for this step).
     *
     * @param  string  $isoCode  ISO 3166-1 alpha-2 country code
     * @param  \DateTimeInterface  $clickedAt  Click timestamp
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
            $year = (int) $clickedAt->format('Y');
            $holidays = \Yasumi\Yasumi::create($supported[$iso], $year);
            $date = new \DateTime($clickedAt->format('Y-m-d'));
            $isHoliday = $holidays->isHoliday($date);

            $holidayName = null;
            if ($isHoliday) {
                foreach ($holidays->on($date) as $holiday) {
                    $holidayName = $holiday->getName();
                    break;
                }
            }

            return [
                'is_holiday' => $isHoliday,
                'holiday_name' => $holidayName,
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

        $score = 100;
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
        $uaMobile = $fields['is_mobile'] ?? false;
        if ($chMobile !== null && (bool) $chMobile !== (bool) $uaMobile) {
            $fingerprint++;
            $score -= 10;
        }

        $browser = $fields['browser'] ?? null;
        $chPlatform = $fields['ch_platform'] ?? null;
        if (in_array($browser, ['Chrome', 'Edge'], true) && empty($chPlatform)) {
            $fingerprint++;
        }

        $score = max(0, $score);
        $qualityTier = match (true) {
            $score >= 80 => 'organic',
            $score >= 50 => 'suspicious',
            default => 'likely_fraud',
        };

        return [
            'quality_score' => $score,
            'quality_tier' => $qualityTier,
            'fingerprint_score' => $fingerprint,
        ];
    }
}
