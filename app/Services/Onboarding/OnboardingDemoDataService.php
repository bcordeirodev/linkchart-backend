<?php

namespace App\Services\Onboarding;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds a realistic demo link with synthetic click data for new users.
 *
 * Dispatch chain: UserObserver::created → SeedDemoLinkJob::dispatch($user)
 * → OnboardingDemoDataService::run($user).
 *
 * The demo link is idempotent — run() is a no-op if the user already has an
 * is_demo=true link. It creates exactly one link and inserts TOTAL_CLICKS (1247)
 * clicks in batches of BATCH_SIZE (500) using Click::insert() for performance.
 *
 * Side effects:
 *   - Writes one row to links (is_demo=true).
 *   - Writes 1247 rows to clicks via bulk insert.
 *   - Updates links.clicks = 1247 after insert completes.
 *   - Does NOT dispatch ProcessLinkClickJob — clicks are inserted directly
 *     with pre-populated geographic and device fields, bypassing enrichment.
 */
class OnboardingDemoDataService
{
    private const TOTAL_CLICKS = 1247;

    private const BATCH_SIZE = 500;

    private const DAYS_BACK = 60;

    private array $countries = [
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York',      'continent' => 'NA'],
        ['name' => 'Brazil',        'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',     'continent' => 'SA'],
        ['name' => 'United Kingdom', 'iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London',         'continent' => 'EU'],
        ['name' => 'Germany',       'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin',         'continent' => 'EU'],
        ['name' => 'France',        'iso' => 'FR', 'currency' => 'EUR', 'timezone' => 'Europe/Paris',          'continent' => 'EU'],
        ['name' => 'Canada',        'iso' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto',       'continent' => 'NA'],
        ['name' => 'Australia',     'iso' => 'AU', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney',      'continent' => 'OC'],
        ['name' => 'Japan',         'iso' => 'JP', 'currency' => 'JPY', 'timezone' => 'Asia/Tokyo',            'continent' => 'AS'],
        ['name' => 'India',         'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata',          'continent' => 'AS'],
        ['name' => 'Mexico',        'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City',   'continent' => 'NA'],
        ['name' => 'Spain',         'iso' => 'ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid',         'continent' => 'EU'],
        ['name' => 'Italy',         'iso' => 'IT', 'currency' => 'EUR', 'timezone' => 'Europe/Rome',           'continent' => 'EU'],
        ['name' => 'Netherlands',   'iso' => 'NL', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam',      'continent' => 'EU'],
        ['name' => 'Argentina',     'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires',  'continent' => 'SA'],
        ['name' => 'South Korea',   'iso' => 'KR', 'currency' => 'KRW', 'timezone' => 'Asia/Seoul',            'continent' => 'AS'],
        ['name' => 'China',         'iso' => 'CN', 'currency' => 'CNY', 'timezone' => 'Asia/Shanghai',         'continent' => 'AS'],
        ['name' => 'Russia',        'iso' => 'RU', 'currency' => 'RUB', 'timezone' => 'Europe/Moscow',         'continent' => 'EU'],
        ['name' => 'Turkey',        'iso' => 'TR', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul',       'continent' => 'EU'],
        ['name' => 'Poland',        'iso' => 'PL', 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw',         'continent' => 'EU'],
        ['name' => 'Sweden',        'iso' => 'SE', 'currency' => 'SEK', 'timezone' => 'Europe/Stockholm',      'continent' => 'EU'],
    ];

    private array $cities = [
        'US' => [
            ['city' => 'New York',    'state' => 'NY', 'state_name' => 'New York',    'lat' => 40.7128,  'lng' => -74.0060,  'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California',  'lat' => 34.0522,  'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Chicago',     'state' => 'IL', 'state_name' => 'Illinois',    'lat' => 41.8781,  'lng' => -87.6298,  'postal' => '60601'],
            ['city' => 'Houston',     'state' => 'TX', 'state_name' => 'Texas',       'lat' => 29.7604,  'lng' => -95.3698,  'postal' => '77001'],
            ['city' => 'Miami',       'state' => 'FL', 'state_name' => 'Florida',     'lat' => 25.7617,  'lng' => -80.1918,  'postal' => '33101'],
        ],
        'BR' => [
            ['city' => 'São Paulo',       'state' => 'SP', 'state_name' => 'São Paulo',        'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro',  'state' => 'RJ', 'state_name' => 'Rio de Janeiro',   'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília',        'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Salvador',        'state' => 'BA', 'state_name' => 'Bahia',            'lat' => -12.9714, 'lng' => -38.5014, 'postal' => '40070-110'],
            ['city' => 'Belo Horizonte',  'state' => 'MG', 'state_name' => 'Minas Gerais',    'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30000-000'],
        ],
        'GB' => [
            ['city' => 'London',     'state' => 'ENG', 'state_name' => 'England', 'lat' => 51.5074, 'lng' => -0.1278, 'postal' => 'SW1A 1AA'],
            ['city' => 'Manchester', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 53.4808, 'lng' => -2.2426, 'postal' => 'M1 1AA'],
            ['city' => 'Birmingham', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 52.4862, 'lng' => -1.8904, 'postal' => 'B1 1AA'],
        ],
        'DE' => [
            ['city' => 'Berlin',    'state' => 'BE', 'state_name' => 'Berlin',  'lat' => 52.5200, 'lng' => 13.4050, 'postal' => '10115'],
            ['city' => 'Munich',    'state' => 'BY', 'state_name' => 'Bavaria', 'lat' => 48.1351, 'lng' => 11.5820, 'postal' => '80331'],
            ['city' => 'Hamburg',   'state' => 'HH', 'state_name' => 'Hamburg', 'lat' => 53.5511, 'lng' => 9.9937,  'postal' => '20095'],
        ],
        'FR' => [
            ['city' => 'Paris',     'state' => 'IDF', 'state_name' => 'Île-de-France',               'lat' => 48.8566, 'lng' => 2.3522, 'postal' => '75001'],
            ['city' => 'Lyon',      'state' => 'ARA', 'state_name' => 'Auvergne-Rhône-Alpes',        'lat' => 45.7640, 'lng' => 4.8357, 'postal' => '69001'],
            ['city' => 'Marseille', 'state' => 'PAC', 'state_name' => 'Provence-Alpes-Côte d\'Azur', 'lat' => 43.2965, 'lng' => 5.3698, 'postal' => '13001'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0.0, 'lng' => 0.0, 'postal' => '00000'],
        ],
    ];

    private array $userAgents = [
        'mobile' => [
            [
                'ua'               => 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1',
                'browser'          => 'Safari',   'browser_version' => '15.0',
                'os'               => 'iOS',       'os_version'      => '15.0',
                'rendering_engine' => 'webkit',    'ch_platform'     => 'iOS',
            ],
            [
                'ua'               => 'Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36',
                'browser'          => 'Chrome',    'browser_version' => '91.0',
                'os'               => 'Android',   'os_version'      => '11',
                'rendering_engine' => 'blink',     'ch_platform'     => 'Android',
            ],
            [
                'ua'               => 'Mozilla/5.0 (Linux; Android 10; Pixel 4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.91 Mobile Safari/537.36',
                'browser'          => 'Chrome',    'browser_version' => '90.0',
                'os'               => 'Android',   'os_version'      => '10',
                'rendering_engine' => 'blink',     'ch_platform'     => 'Android',
            ],
            [
                'ua'               => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
                'browser'          => 'Safari',    'browser_version' => '14.1',
                'os'               => 'iOS',       'os_version'      => '14.6',
                'rendering_engine' => 'webkit',    'ch_platform'     => 'iOS',
            ],
        ],
        'desktop' => [
            [
                'ua'               => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'browser'          => 'Chrome',    'browser_version' => '91.0',
                'os'               => 'Windows',   'os_version'      => '10',
                'rendering_engine' => 'blink',     'ch_platform'     => 'Windows',
            ],
            [
                'ua'               => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.114 Safari/537.36',
                'browser'          => 'Chrome',    'browser_version' => '91.0',
                'os'               => 'macOS',     'os_version'      => '10.15',
                'rendering_engine' => 'blink',     'ch_platform'     => 'macOS',
            ],
            [
                'ua'               => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
                'browser'          => 'Firefox',   'browser_version' => '89.0',
                'os'               => 'Windows',   'os_version'      => '10',
                'rendering_engine' => 'gecko',     'ch_platform'     => 'Windows',
            ],
            [
                'ua'               => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Safari/605.1.15',
                'browser'          => 'Safari',    'browser_version' => '14.1',
                'os'               => 'macOS',     'os_version'      => '10.15',
                'rendering_engine' => 'webkit',    'ch_platform'     => 'macOS',
            ],
        ],
        'tablet' => [
            [
                'ua'               => 'Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
                'browser'          => 'Safari',    'browser_version' => '14.1',
                'os'               => 'iPadOS',    'os_version'      => '14.6',
                'rendering_engine' => 'webkit',    'ch_platform'     => 'iOS',
            ],
            [
                'ua'               => 'Mozilla/5.0 (Linux; Android 11; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Safari/537.36',
                'browser'          => 'Chrome',    'browser_version' => '91.0',
                'os'               => 'Android',   'os_version'      => '11',
                'rendering_engine' => 'blink',     'ch_platform'     => 'Android',
            ],
        ],
        'bot' => [
            [
                'ua'               => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'browser'          => 'Googlebot', 'browser_version' => '2.1',
                'os'               => 'Linux',     'os_version'      => '',
                'rendering_engine' => 'unknown',   'ch_platform'     => '',
            ],
            [
                'ua'               => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
                'browser'          => 'Bingbot',   'browser_version' => '2.0',
                'os'               => 'Linux',     'os_version'      => '',
                'rendering_engine' => 'unknown',   'ch_platform'     => '',
            ],
        ],
    ];

    private array $referrers = [
        'social' => [
            ['url' => 'https://www.facebook.com/',  'platform' => 'facebook'],
            ['url' => 'https://twitter.com/',        'platform' => 'twitter'],
            ['url' => 'https://www.instagram.com/', 'platform' => 'instagram'],
            ['url' => 'https://www.linkedin.com/',  'platform' => 'linkedin'],
            ['url' => 'https://www.tiktok.com/',    'platform' => 'tiktok'],
        ],
        'search' => [
            ['url' => 'https://www.google.com/search?q=example', 'platform' => null],
            ['url' => 'https://www.bing.com/search?q=example',   'platform' => null],
            ['url' => 'https://duckduckgo.com/?q=example',       'platform' => null],
        ],
        'direct' => [
            ['url' => null, 'platform' => null],
            ['url' => null, 'platform' => null],
            ['url' => null, 'platform' => null],
        ],
        'referral' => [
            ['url' => 'https://news.ycombinator.com/', 'platform' => null],
            ['url' => 'https://www.reddit.com/',       'platform' => null],
            ['url' => 'https://medium.com/',           'platform' => null],
        ],
    ];

    /** Maps country ISO code to language data for primary_language, language_region, accept_language. */
    private array $languageByIso = [
        'US' => ['lang' => 'en', 'region' => 'en-US', 'accept' => 'en-US,en;q=0.9'],
        'BR' => ['lang' => 'pt', 'region' => 'pt-BR', 'accept' => 'pt-BR,pt;q=0.9,en;q=0.8'],
        'GB' => ['lang' => 'en', 'region' => 'en-GB', 'accept' => 'en-GB,en;q=0.9'],
        'DE' => ['lang' => 'de', 'region' => 'de-DE', 'accept' => 'de-DE,de;q=0.9,en;q=0.8'],
        'FR' => ['lang' => 'fr', 'region' => 'fr-FR', 'accept' => 'fr-FR,fr;q=0.9,en;q=0.8'],
        'CA' => ['lang' => 'en', 'region' => 'en-CA', 'accept' => 'en-CA,en;q=0.9,fr;q=0.8'],
        'AU' => ['lang' => 'en', 'region' => 'en-AU', 'accept' => 'en-AU,en;q=0.9'],
        'JP' => ['lang' => 'ja', 'region' => 'ja-JP', 'accept' => 'ja-JP,ja;q=0.9,en;q=0.8'],
        'IN' => ['lang' => 'hi', 'region' => 'hi-IN', 'accept' => 'hi-IN,hi;q=0.9,en;q=0.8'],
        'MX' => ['lang' => 'es', 'region' => 'es-MX', 'accept' => 'es-MX,es;q=0.9,en;q=0.8'],
        'ES' => ['lang' => 'es', 'region' => 'es-ES', 'accept' => 'es-ES,es;q=0.9,en;q=0.8'],
        'IT' => ['lang' => 'it', 'region' => 'it-IT', 'accept' => 'it-IT,it;q=0.9,en;q=0.8'],
        'NL' => ['lang' => 'nl', 'region' => 'nl-NL', 'accept' => 'nl-NL,nl;q=0.9,en;q=0.8'],
        'AR' => ['lang' => 'es', 'region' => 'es-AR', 'accept' => 'es-AR,es;q=0.9,en;q=0.8'],
        'KR' => ['lang' => 'ko', 'region' => 'ko-KR', 'accept' => 'ko-KR,ko;q=0.9,en;q=0.8'],
        'CN' => ['lang' => 'zh', 'region' => 'zh-CN', 'accept' => 'zh-CN,zh;q=0.9,en;q=0.8'],
        'RU' => ['lang' => 'ru', 'region' => 'ru-RU', 'accept' => 'ru-RU,ru;q=0.9,en;q=0.8'],
        'TR' => ['lang' => 'tr', 'region' => 'tr-TR', 'accept' => 'tr-TR,tr;q=0.9,en;q=0.8'],
        'PL' => ['lang' => 'pl', 'region' => 'pl-PL', 'accept' => 'pl-PL,pl;q=0.9,en;q=0.8'],
        'SE' => ['lang' => 'sv', 'region' => 'sv-SE', 'accept' => 'sv-SE,sv;q=0.9,en;q=0.8'],
    ];

    /**
     * Seeds the demo link and 1247 synthetic clicks for the given user.
     *
     * Idempotent: returns immediately if the user already has an is_demo link.
     * Creates the link, bulk-inserts clicks in batches of 500, then updates
     * the denormalized links.clicks counter.
     *
     * Side effects: writes to links and clicks tables.
     *
     * @param  User  $user  The newly created user to onboard.
     */
    public function run(User $user): void
    {
        if (Link::where('user_id', $user->id)->where('is_demo', true)->exists()) {
            return;
        }

        $slug = $this->generateUniqueSlug();

        $link = Link::create([
            'user_id' => $user->id,
            'slug' => $slug,
            'original_url' => 'https://linkcharts.com.br',
            'title' => '📊 Demo Link — See Analytics in Action',
            'description' => 'This is a sample link created automatically so you can explore the full power of Link Charts analytics. The '.self::TOTAL_CLICKS.' clicks shown here are simulated data spread across '.self::DAYS_BACK.' days, covering multiple countries, devices, and traffic sources — exactly what your real links will look like after your audience starts clicking. Feel free to delete this link whenever you\'re ready.',
            'is_active' => true,
            'is_demo' => true,
            'clicks' => 0,
        ]);

        $this->insertClicks($link->id);

        $link->update(['clicks' => self::TOTAL_CLICKS]);
    }

    private function generateUniqueSlug(): string
    {
        do {
            $slug = Str::random(8);
        } while (Link::where('slug', $slug)->exists());

        return $slug;
    }

    private function insertClicks(int $linkId): void
    {
        $batch = [];
        $start = Carbon::now()->subDays(self::DAYS_BACK);
        $end   = Carbon::now();

        for ($i = 0; $i < self::TOTAL_CLICKS; $i++) {
            $country  = $this->selectCountryByWeight();
            $cityData = $this->getCityData($country['iso']);
            $device   = $this->selectDeviceByWeight();
            $clickAt  = $this->generateRandomDate($start, $end);
            $uaInfo   = $this->getUserAgent($device);
            $refData  = $this->getReferer();
            $langData = $this->languageByIso[$country['iso']]
                ?? ['lang' => 'en', 'region' => 'en-US', 'accept' => 'en-US,en;q=0.9'];
            $quality  = $this->getQualityData($device === 'bot');
            $isBot    = $device === 'bot';
            $isMobile = $device === 'mobile';
            $isTablet = $device === 'tablet';
            $dow      = (int) $clickAt->format('N');

            $batch[] = [
                // Core
                'link_id'    => $linkId,
                'ip'         => $this->generateRealisticIp($country['iso']),
                'user_agent' => $uaInfo['ua'],
                'referer'    => $refData['referer'],
                // Geographic
                'country'     => $country['name'],
                'iso_code'    => $country['iso'],
                'state'       => $cityData['state'],
                'state_name'  => $cityData['state_name'],
                'city'        => $cityData['city'],
                'postal_code' => $cityData['postal'],
                'latitude'    => $cityData['lat'],
                'longitude'   => $cityData['lng'],
                'timezone'    => $country['timezone'],
                'continent'   => $country['continent'],
                'currency'    => $country['currency'],
                // Device
                'device'           => $device,
                'browser'          => $uaInfo['browser'],
                'browser_version'  => $uaInfo['browser_version'],
                'os'               => $uaInfo['os'],
                'os_version'       => $uaInfo['os_version'],
                'is_mobile'        => $isMobile ? 1 : 0,
                'is_tablet'        => $isTablet ? 1 : 0,
                'is_desktop'       => (! $isMobile && ! $isTablet && ! $isBot) ? 1 : 0,
                'is_bot'           => $isBot ? 1 : 0,
                'rendering_engine' => $uaInfo['rendering_engine'],
                'ch_platform'      => $uaInfo['ch_platform'],
                'ch_is_mobile'     => $isMobile ? 1 : 0,
                // Temporal
                'hour_of_day'       => $clickAt->hour,
                'day_of_week'       => $dow,
                'day_of_month'      => $clickAt->day,
                'month'             => $clickAt->month,
                'year'              => $clickAt->year,
                'local_time'        => $clickAt->format('Y-m-d H:i:s'),
                'is_weekend'        => in_array($dow, [6, 7]) ? 1 : 0,
                'is_business_hours' => ($clickAt->hour >= 9 && $clickAt->hour <= 17) ? 1 : 0,
                'season'            => $this->getSeasonForMonth($clickAt->month),
                // Traffic source
                'click_source'    => $refData['click_source'],
                'social_platform' => $refData['social_platform'],
                // Quality
                'quality_tier'      => $quality['quality_tier'],
                'quality_score'     => $quality['quality_score'],
                'fingerprint_score' => $quality['fingerprint_score'],
                // Behavior
                'is_return_visitor'        => mt_rand(0, 3) === 0 ? 1 : 0,
                'session_clicks'           => $this->weightedRandom([1 => 75, 2 => 18, 3 => 7]),
                'is_data_saver'            => mt_rand(0, 19) === 0 ? 1 : 0,
                'seconds_since_last_click' => mt_rand(1, 3600),
                // Context
                'navigation_context' => $this->getNavigationContext(),
                'fetch_dest'         => mt_rand(0, 9) === 0 ? 'empty' : 'document',
                'http_protocol'      => $this->getHttpProtocol(),
                'connection_type'    => $this->getConnectionType(),
                'viral_rank'         => $this->getViralRank(),
                'is_holiday'         => 0,
                // Language
                'primary_language' => $langData['lang'],
                'language_region'  => $langData['region'],
                'accept_language'  => $langData['accept'],
                // Performance
                'response_time' => round(mt_rand(50000, 300000) / 1000, 3),
                // Timestamps
                'created_at' => $clickAt,
                'updated_at' => $clickAt,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                Click::insert($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            Click::insert($batch);
        }
    }

    private function generateRandomDate(Carbon $start, Carbon $end): Carbon
    {
        $timestamp = mt_rand($start->timestamp, $end->timestamp);
        $hour = $this->getRealisticHour();

        return Carbon::createFromTimestamp($timestamp)->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
    }

    private function getRealisticHour(): int
    {
        $weights = [
            0 => 1,  1 => 1,  2 => 1,  3 => 1,  4 => 1,  5 => 2,
            6 => 3,  7 => 5,  8 => 8,  9 => 10, 10 => 12, 11 => 13,
            12 => 14, 13 => 15, 14 => 16, 15 => 17, 16 => 16,
            17 => 15, 18 => 14, 19 => 13, 20 => 12, 21 => 10,
            22 => 8,  23 => 5,
        ];

        return $this->weightedRandom($weights);
    }

    private function selectCountryByWeight(): array
    {
        $weights = [
            0 => 30, 1 => 20, 2 => 10, 3 => 8,  4 => 6,
            5 => 5,  6 => 4,  7 => 3,  8 => 3,  9 => 3,
            10 => 2, 11 => 2, 12 => 1, 13 => 1, 14 => 1,
            15 => 1, 16 => 1, 17 => 1, 18 => 1, 19 => 1,
        ];

        return $this->countries[$this->weightedRandom($weights)];
    }

    private function getCityData(string $iso): array
    {
        $cities = $this->cities[$iso] ?? $this->cities['DEFAULT'];

        return $cities[array_rand($cities)];
    }

    private function selectDeviceByWeight(): string
    {
        return $this->weightedRandom(['mobile' => 60, 'desktop' => 35, 'tablet' => 4, 'bot' => 1]);
    }

    /**
     * Returns the full UA metadata array for the given device type.
     *
     * @param  string  $device  One of: mobile, desktop, tablet, bot.
     * @return array{ua: string, browser: string, browser_version: string, os: string, os_version: string, rendering_engine: string, ch_platform: string}
     */
    private function getUserAgent(string $device): array
    {
        $agents = $this->userAgents[$device] ?? $this->userAgents['desktop'];

        return $agents[array_rand($agents)];
    }

    /**
     * Returns referer URL, click_source category, and social_platform (if social).
     *
     * @return array{referer: string|null, click_source: string, social_platform: string|null}
     */
    private function getReferer(): array
    {
        $type  = $this->weightedRandom(['direct' => 40, 'social' => 30, 'search' => 20, 'referral' => 10]);
        $entry = $this->referrers[$type][array_rand($this->referrers[$type])];

        return [
            'referer'         => $entry['url'],
            'click_source'    => $type,
            'social_platform' => $entry['platform'],
        ];
    }

    private function generateRealisticIp(string $iso): string
    {
        $ranges = [
            'US' => ['173.252.', '199.16.',  '204.15.'],
            'BR' => ['200.160.', '189.85.',  '177.67.'],
            'GB' => ['81.2.',    '86.1.',    '109.144.'],
            'DE' => ['85.25.',   '91.65.',   '178.25.'],
            'FR' => ['78.192.',  '90.84.',   '176.31.'],
            'CA' => ['142.11.',  '192.99.',  '198.50.'],
            'AU' => ['203.208.', '220.101.', '139.130.'],
            'JP' => ['202.216.', '210.248.', '126.19.'],
            'IN' => ['103.21.',  '103.22.',  '103.23.'],
            'MX' => ['189.203.', '187.141.', '201.144.'],
        ];

        $prefixes = $ranges[$iso] ?? ['192.168.', '10.0.', '203.0.'];
        $prefix = $prefixes[array_rand($prefixes)];

        return $prefix.mt_rand(1, 254).'.'.mt_rand(1, 254);
    }

    private function weightedRandom(array $weights): int|string
    {
        $total = array_sum($weights);
        $random = mt_rand(1, $total);
        $current = 0;

        foreach ($weights as $key => $weight) {
            $current += $weight;
            if ($random <= $current) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    /** Returns season name (northern hemisphere) for a given month number. */
    private function getSeasonForMonth(int $month): string
    {
        if (in_array($month, [3, 4, 5])) {
            return 'spring';
        }
        if (in_array($month, [6, 7, 8])) {
            return 'summer';
        }
        if (in_array($month, [9, 10, 11])) {
            return 'fall';
        }

        return 'winter';
    }

    /**
     * Returns quality_tier, quality_score, and fingerprint_score.
     * Bots always get likely_fraud tier.
     *
     * @return array{quality_tier: string, quality_score: int, fingerprint_score: int}
     */
    private function getQualityData(bool $isBot): array
    {
        if ($isBot) {
            return [
                'quality_tier'      => 'likely_fraud',
                'quality_score'     => mt_rand(0, 29),
                'fingerprint_score' => mt_rand(2, 3),
            ];
        }

        $tier = $this->weightedRandom(['organic' => 85, 'suspicious' => 10, 'likely_fraud' => 5]);

        return [
            'quality_tier'      => $tier,
            'quality_score'     => match ($tier) {
                'organic'    => mt_rand(70, 100),
                'suspicious' => mt_rand(30, 69),
                default      => mt_rand(0, 29),
            },
            'fingerprint_score' => $this->weightedRandom([0 => 70, 1 => 20, 2 => 8, 3 => 2]),
        ];
    }

    /** Returns a weighted random connection_type string. */
    private function getConnectionType(): string
    {
        return $this->weightedRandom([
            'residential' => 60,
            'mobile'      => 25,
            'datacenter'  => 10,
            'education'   => 5,
        ]);
    }

    /** Returns a weighted random viral_rank string. */
    private function getViralRank(): string
    {
        return $this->weightedRandom([
            'cold'     => 70,
            'warming'  => 20,
            'trending' => 8,
            'viral'    => 2,
        ]);
    }

    /** Returns a weighted random navigation_context string. */
    private function getNavigationContext(): string
    {
        return $this->weightedRandom([
            'cross-site'  => 40,
            'none'        => 40,
            'same-origin' => 20,
        ]);
    }

    /** Returns a weighted random HTTP protocol string. */
    private function getHttpProtocol(): string
    {
        return $this->weightedRandom([
            'HTTP/2'   => 70,
            'HTTP/1.1' => 25,
            'HTTP/3'   => 5,
        ]);
    }
}
