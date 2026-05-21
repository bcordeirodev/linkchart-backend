<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds links and clicks with full temporal coverage for bcordeiro.001@gmail.com.
 *
 * Goals:
 *  - Every hour of the day (0–23) has a meaningful number of clicks.
 *  - Every day of the week (Mon–Sun) has a meaningful number of clicks.
 *  - Traffic pattern is realistic: peaks during business hours on weekdays,
 *    moderate evenings, low late-night, lighter weekends.
 *  - 8 varied links, ~2 500–5 000 clicks each (≈28 000 total).
 *  - Full geo + device + social + UTM + viral_rank enrichment.
 *
 * Run:
 *   php artisan db:seed --class=TemporalCoverageSeeder
 *
 * Idempotent by slug: existing slugs are reused; no duplicate click rows added.
 */
class TemporalCoverageSeeder extends Seeder
{
    use ClickEnrichmentTrait;

    private const USER_EMAIL = 'bcordeiro.001@gmail.com';

    /** Spread clicks over this many past days. 91 = 13 full weeks → every weekday appears 13 times. */
    private const DAYS_BACK = 91;

    // ── Geo data ─────────────────────────────────────────────────────────────

    /** @var list<array{name:string,iso:string,currency:string,timezone:string,continent:string}> */
    private array $countries = [
        ['name' => 'Brazil',         'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',    'continent' => 'SA'],
        ['name' => 'Brazil',         'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',    'continent' => 'SA'], // double weight
        ['name' => 'United States',  'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York',     'continent' => 'NA'],
        ['name' => 'Mexico',         'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City',  'continent' => 'NA'],
        ['name' => 'Argentina',      'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
        ['name' => 'Colombia',       'iso' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota',       'continent' => 'SA'],
        ['name' => 'Portugal',       'iso' => 'PT', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon',        'continent' => 'EU'],
        ['name' => 'United Kingdom', 'iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London',        'continent' => 'EU'],
        ['name' => 'Germany',        'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin',        'continent' => 'EU'],
        ['name' => 'India',          'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata',         'continent' => 'AS'],
    ];

    /** @var array<string,list<array{city:string,state:string,state_name:string,lat:float,lng:float,postal:string}>> */
    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo',      'state' => 'SP', 'state_name' => 'São Paulo',        'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro',   'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília',       'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Belo Horizonte', 'state' => 'MG', 'state_name' => 'Minas Gerais',     'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30112-020'],
            ['city' => 'Curitiba',       'state' => 'PR', 'state_name' => 'Paraná',           'lat' => -25.4290, 'lng' => -49.2671, 'postal' => '80010-000'],
        ],
        'US' => [
            ['city' => 'New York',    'state' => 'NY', 'state_name' => 'New York',   'lat' => 40.7128, 'lng' => -74.0060,  'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California', 'lat' => 34.0522, 'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Miami',       'state' => 'FL', 'state_name' => 'Florida',    'lat' => 25.7617, 'lng' => -80.1918,  'postal' => '33101'],
        ],
        'MX' => [['city' => 'Mexico City', 'state' => 'MC', 'state_name' => 'Mexico City',  'lat' => 19.4326,  'lng' => -99.1332, 'postal' => '06600']],
        'AR' => [['city' => 'Buenos Aires','state' => 'BA', 'state_name' => 'Buenos Aires', 'lat' => -34.6037, 'lng' => -58.3816, 'postal' => 'C1002']],
        'CO' => [['city' => 'Bogotá',      'state' => 'DC', 'state_name' => 'Cundinamarca', 'lat' => 4.7110,   'lng' => -74.0721, 'postal' => '110111']],
        'PT' => [['city' => 'Lisbon',      'state' => 'LIS','state_name' => 'Lisboa',       'lat' => 38.7223,  'lng' => -9.1393,  'postal' => '1100-148']],
        'GB' => [['city' => 'London',      'state' => 'ENG','state_name' => 'England',      'lat' => 51.5074,  'lng' => -0.1278,  'postal' => 'SW1A 1AA']],
        'DE' => [['city' => 'Berlin',      'state' => 'BE', 'state_name' => 'Berlin',       'lat' => 52.5200,  'lng' => 13.4050,  'postal' => '10115']],
        'IN' => [['city' => 'Mumbai',      'state' => 'MH', 'state_name' => 'Maharashtra',  'lat' => 19.0760,  'lng' => 72.8777,  'postal' => '400001']],
    ];

    /** IP prefix per country ISO (first two octets). */
    private array $ipPrefixes = [
        'BR' => '189.85.', 'US' => '173.252.', 'MX' => '200.34.',
        'AR' => '190.91.', 'CO' => '190.24.',  'PT' => '195.22.',
        'GB' => '86.1.',   'DE' => '85.25.',   'IN' => '103.21.',
    ];

    /** @var list<string> */
    private array $mobileUAs = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.6045.193 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 12; Redmi Note 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 15_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/19G82',
    ];

    /** @var list<string> */
    private array $desktopUAs = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 Edg/119.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.109 Safari/537.36',
    ];

    /** @var list<string> */
    private array $webviewUAs = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21A329',
        'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/112.0.0.0 Mobile Safari/537.36',
    ];

    // ── Link definitions ──────────────────────────────────────────────────────

    /**
     * @var list<array{slug:string,url:string,title:string,description:string,utm:string|null}>
     *
     * Clicks per link are derived at runtime from the temporal schedule
     * (roughly 2 500–5 000 depending on daily weights × DAYS_BACK).
     */
    private array $linkDefs = [
        ['slug' => 'yt-canal-bruno',    'url' => 'https://www.youtube.com/@brunofullstack',                         'title' => 'Canal YouTube — Bruno Dev',          'description' => 'Programação, projetos e dicas de carreira',      'utm' => 'social'],
        ['slug' => 'lp-mentoria-2025',  'url' => 'https://mentoria.brunodev.com.br/turma-2025',                     'title' => 'Mentoria Dev 2025 — Turma Aberta',   'description' => 'Programa de mentoria individual para devs',      'utm' => 'email'],
        ['slug' => 'gh-link-charts',    'url' => 'https://github.com/bcordeiro/link-charts',                        'title' => 'Link Charts — GitHub',               'description' => 'Repositório open source do encurtador',          'utm' => 'referral'],
        ['slug' => 'ig-perfil-bruno',   'url' => 'https://www.instagram.com/brunodev.br',                           'title' => 'Instagram @brunodev.br',             'description' => 'Bastidores do desenvolvimento e dicas rápidas',   'utm' => 'social'],
        ['slug' => 'blog-nextjs15',     'url' => 'https://brunodev.com.br/blog/nextjs-15-app-router-guia-completo', 'title' => 'Next.js 15 App Router — Guia Completo','description' => 'Tutorial passo a passo do App Router',           'utm' => 'organic'],
        ['slug' => 'shop-livros-dev',   'url' => 'https://amzn.to/livros-programacao-bruno',                        'title' => 'Lista de Livros Recomendados',        'description' => 'Livros de programação com affiliate',            'utm' => 'email'],
        ['slug' => 'discord-comunidade','url' => 'https://discord.gg/brunodev-comunidade',                          'title' => 'Discord — Comunidade Bruno Dev',      'description' => 'Servidor Discord para devs em crescimento',      'utm' => 'social'],
        ['slug' => 'li-perfil-bruno',   'url' => 'https://www.linkedin.com/in/brunocordeiro-dev',                   'title' => 'LinkedIn — Bruno Cordeiro',          'description' => 'Perfil profissional e conexões em tecnologia',   'utm' => 'referral'],
    ];

    // ── Temporal weight tables ────────────────────────────────────────────────

    /**
     * Click multiplier per hour-of-day (0 = midnight … 23 = 11pm).
     *
     * Base = 1 click per slot. Multiplier scales volume while keeping every
     * hour represented.
     *
     * @var array<int,int>  keys 0-23, values 1-10
     */
    private array $hourWeights = [
        0  => 1,   // midnight
        1  => 1,
        2  => 1,
        3  => 1,
        4  => 1,
        5  => 2,   // early morning
        6  => 3,
        7  => 5,
        8  => 7,   // morning rush
        9  => 9,
        10 => 10,  // business hours peak
        11 => 10,
        12 => 9,   // lunch dip
        13 => 8,
        14 => 10,  // afternoon peak
        15 => 10,
        16 => 9,
        17 => 8,   // end of business
        18 => 7,   // evening
        19 => 6,
        20 => 5,
        21 => 4,
        22 => 3,
        23 => 2,   // late night
    ];

    /**
     * Click multiplier per ISO day-of-week (1 = Monday … 7 = Sunday).
     *
     * @var array<int,float>
     */
    private array $dowWeights = [
        1 => 1.0,  // Monday
        2 => 1.1,  // Tuesday
        3 => 1.1,  // Wednesday
        4 => 1.0,  // Thursday
        5 => 0.9,  // Friday — slightly lower (post-lunch fatigue)
        6 => 0.6,  // Saturday
        7 => 0.5,  // Sunday
    ];

    // ── UTM pools ─────────────────────────────────────────────────────────────

    /** @var array<string,list<array{source:string,medium:string,campaign:string|null}>> */
    private array $utmPools = [
        'social'   => [
            ['source' => 'instagram', 'medium' => 'social',   'campaign' => 'bio_link'],
            ['source' => 'tiktok',    'medium' => 'social',   'campaign' => 'creator_bio'],
            ['source' => 'youtube',   'medium' => 'social',   'campaign' => 'description'],
            ['source' => 'twitter',   'medium' => 'social',   'campaign' => 'pinned_tweet'],
            ['source' => 'linkedin',  'medium' => 'social',   'campaign' => 'profile_link'],
        ],
        'email'    => [
            ['source' => 'newsletter','medium' => 'email',    'campaign' => 'weekly_digest'],
            ['source' => 'sendgrid',  'medium' => 'email',    'campaign' => 'promo_may25'],
            ['source' => 'mailchimp', 'medium' => 'email',    'campaign' => 'launch_announcement'],
        ],
        'referral' => [
            ['source' => 'devto',     'medium' => 'referral', 'campaign' => null],
            ['source' => 'medium',    'medium' => 'referral', 'campaign' => null],
            ['source' => 'hashnode',  'medium' => 'referral', 'campaign' => null],
        ],
        'organic'  => [
            ['source' => 'google',    'medium' => 'organic',  'campaign' => null],
            ['source' => 'bing',      'medium' => 'organic',  'campaign' => null],
            ['source' => 'duckduckgo','medium' => 'organic',  'campaign' => null],
        ],
    ];

    /** Social platform weighted pool (platform => share out of 100 mobile clicks). */
    private array $socialPlatformWeights = [
        'instagram' => 26,
        'tiktok'    => 20,
        'youtube'   => 12,
        'facebook'  => 8,
        'twitter'   => 5,
        'whatsapp'  => 4,
        'telegram'  => 3,
        null        => 22,
    ];

    // ── Entry point ───────────────────────────────────────────────────────────

    /**
     * Main seeder entry point.
     */
    public function run(): void
    {
        $user = DB::table('users')->where('email', self::USER_EMAIL)->first();

        if (! $user) {
            $this->command->error('User "'.self::USER_EMAIL.'" not found.');
            return;
        }

        $this->command->info("User found: ID {$user->id} ({$user->email})");
        $this->command->info('Generating links + temporally-distributed clicks…');
        $this->command->newLine();

        $platformPicker = $this->buildPlatformPicker();

        foreach ($this->linkDefs as $def) {
            $this->processLink($def, $user->id, $platformPicker);
        }

        $this->command->newLine();
        $this->command->info('━━━ Final Summary ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->printSummary($user->id);
    }

    // ── Per-link processing ───────────────────────────────────────────────────

    /**
     * Ensures the link row exists, then inserts temporally-distributed clicks.
     *
     * @param  array{slug:string,url:string,title:string,description:string,utm:string|null}  $def
     * @param  int  $userId
     * @param  list<string|null>  $platformPicker
     */
    private function processLink(array $def, int $userId, array $platformPicker): void
    {
        $now = Carbon::now()->toDateTimeString();

        $existing = DB::table('links')->where('slug', $def['slug'])->first();

        if ($existing) {
            $linkId = $existing->id;
            $deleted = DB::table('clicks')->where('link_id', $linkId)->count();
            // Delete existing UTM rows first (FK constraint), then clicks
            DB::table('link_utms')
                ->whereIn('click_id', DB::table('clicks')->where('link_id', $linkId)->pluck('id'))
                ->delete();
            DB::table('clicks')->where('link_id', $linkId)->delete();
            DB::table('links')->where('id', $linkId)->update(['clicks' => 0]);
            $this->command->warn("  [{$def['slug']}] already exists (ID {$linkId}) — wiped {$deleted} old clicks, re-seeding.");
        } else {
            $linkId = DB::table('links')->insertGetId([
                'slug'          => $def['slug'],
                'original_url'  => $def['url'],
                'title'         => $def['title'],
                'description'   => $def['description'],
                'user_id'       => $userId,
                'is_active'     => true,
                'is_demo'       => false,
                'clicks'        => 0,
                'health_status' => 'unknown',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $this->command->info("  [{$def['slug']}] created (ID {$linkId}).");
        }

        [$inserted, $utmCount] = $this->insertTemporalClicks($linkId, $def['utm'], $platformPicker);

        DB::table('links')->where('id', $linkId)
            ->update(['clicks' => DB::table('clicks')->where('link_id', $linkId)->count()]);

        $total = DB::table('clicks')->where('link_id', $linkId)->count();
        $this->command->info(sprintf(
            '  ✓ %s → %d clicks inserted (UTM: %d) — total in DB: %d',
            $def['slug'], $inserted, $utmCount, $total
        ));
    }

    // ── Click generation ──────────────────────────────────────────────────────

    /**
     * Builds and inserts clicks with guaranteed hour × day-of-week coverage.
     *
     * Strategy:
     *   For each of the last DAYS_BACK days, for each of the 24 hours, compute
     *   a per-slot count = round(hourWeight × dowWeight × baseFactor) + jitter.
     *   baseFactor ≈ 1-3 scales the overall volume per link.
     *
     * @param  int  $linkId
     * @param  string|null  $utmScenario
     * @param  list<string|null>  $platformPicker
     * @return array{int, int}  [clicksInserted, utmRowsInserted]
     */
    private function insertTemporalClicks(
        int $linkId,
        ?string $utmScenario,
        array $platformPicker
    ): array {
        $today = Carbon::today();

        // baseFactor: different per link to vary total volumes (1.2 – 3.5)
        $baseFactor = round(mt_rand(120, 350) / 100, 2);

        $clicks  = [];
        $utmRows = [];
        $utmPool = $utmScenario ? $this->utmPools[$utmScenario] : [];

        for ($daysAgo = 0; $daysAgo < self::DAYS_BACK; $daysAgo++) {
            $date   = $today->copy()->subDays($daysAgo);
            $dow    = (int) $date->format('N'); // 1=Mon … 7=Sun
            $dowW   = $this->dowWeights[$dow];

            for ($hour = 0; $hour < 24; $hour++) {
                $hourW = $this->hourWeights[$hour];

                // Expected clicks this slot, always at least 1
                $slotCount = max(1, (int) round($hourW * $dowW * $baseFactor) + mt_rand(-1, 2));

                for ($c = 0; $c < $slotCount; $c++) {
                    $clickDate = $date->copy()->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));

                    $country  = $this->countries[array_rand($this->countries)];
                    $iso      = $country['iso'];

                    $deviceRoll = mt_rand(1, 100);
                    $device = match (true) {
                        $deviceRoll <= 62 => 'mobile',
                        $deviceRoll <= 94 => 'desktop',
                        default           => 'tablet',
                    };

                    $ua = $device === 'desktop'
                        ? $this->desktopUAs[array_rand($this->desktopUAs)]
                        : $this->mobileUAs[array_rand($this->mobileUAs)];

                    // Navigation context & social platform
                    $socialPlatform = null;
                    $navContext     = 'direct';

                    if ($device === 'mobile') {
                        $socialPlatform = $platformPicker[mt_rand(0, count($platformPicker) - 1)];

                        if ($socialPlatform !== null) {
                            if (mt_rand(1, 100) <= 45) {
                                $navContext = 'in_app_webview';
                                $ua = $this->webviewUAs[array_rand($this->webviewUAs)];
                            } else {
                                $navContext = 'browser_referral';
                            }
                        } else {
                            $navContext = mt_rand(1, 100) <= 50 ? 'direct' : 'browser_referral';
                        }
                    } else {
                        $navContext = mt_rand(1, 100) <= 55 ? 'direct' : 'browser_referral';
                    }

                    // viral_rank: recency-biased
                    $viralRank = match (true) {
                        $daysAgo <= 7  => mt_rand(1, 100) <= 35 ? 'viral'    : 'trending',
                        $daysAgo <= 21 => mt_rand(1, 100) <= 40 ? 'trending' : 'warming',
                        $daysAgo <= 49 => mt_rand(1, 100) <= 45 ? 'warming'  : 'cold',
                        default        => 'cold',
                    };

                    // Geo
                    $cityOpts = $this->cities[$iso] ?? $this->cities['BR'];
                    $city     = $cityOpts[array_rand($cityOpts)];
                    $prefix   = $this->ipPrefixes[$iso] ?? '192.168.';
                    $ip       = $prefix.mt_rand(1, 254).'.'.mt_rand(1, 254);

                    $enriched = $this->enrichClickData($ua, $device, null, $clickDate, $iso);

                    $clicks[] = array_merge($enriched, [
                        'link_id'            => $linkId,
                        'ip'                 => $ip,
                        'user_agent'         => $ua,
                        'referer'            => null,
                        'country'            => $country['name'],
                        'iso_code'           => $iso,
                        'city'               => $city['city'],
                        'state'              => $city['state'],
                        'state_name'         => $city['state_name'],
                        'postal_code'        => $city['postal'],
                        'latitude'           => $city['lat'],
                        'longitude'          => $city['lng'],
                        'timezone'           => $country['timezone'],
                        'continent'          => $country['continent'],
                        'currency'           => $country['currency'],
                        'social_platform'    => $socialPlatform,
                        'navigation_context' => $navContext,
                        'viral_rank'         => $viralRank,
                        'created_at'         => $clickDate->toDateTimeString(),
                        'updated_at'         => $clickDate->toDateTimeString(),
                    ]);

                    // Flush to DB in batches to avoid memory buildup
                    if (count($clicks) >= 500) {
                        DB::table('clicks')->insert($clicks);
                        $clicks = [];
                    }
                }
            }
        }

        // Insert remaining
        if ($clicks) {
            DB::table('clicks')->insert($clicks);
        }

        // Count what we inserted
        $inserted = DB::table('clicks')->where('link_id', $linkId)->count();

        // Attach UTM rows to ~30 % of clicks
        $utmCount = 0;
        if ($utmPool) {
            $utmTarget  = (int) round($inserted * 0.30);
            $clickIds   = DB::table('clicks')
                ->where('link_id', $linkId)
                ->orderByDesc('id')
                ->limit($utmTarget)
                ->pluck('id')
                ->toArray();

            $nowStr = Carbon::now()->toDateTimeString();
            $utmBatch = [];

            foreach ($clickIds as $idx => $clickId) {
                $entry = $utmPool[$idx % count($utmPool)];
                $utmBatch[] = [
                    'click_id'     => $clickId,
                    'utm_source'   => $entry['source'],
                    'utm_medium'   => $entry['medium'],
                    'utm_campaign' => $entry['campaign'] ?? null,
                    'utm_term'     => null,
                    'utm_content'  => null,
                    'created_at'   => $nowStr,
                    'updated_at'   => $nowStr,
                ];

                if (count($utmBatch) >= 500) {
                    DB::table('link_utms')->insert($utmBatch);
                    $utmCount += count($utmBatch);
                    $utmBatch = [];
                }
            }

            if ($utmBatch) {
                DB::table('link_utms')->insert($utmBatch);
                $utmCount += count($utmBatch);
            }
        }

        return [$inserted, $utmCount];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Builds a flat weighted array for social_platform sampling.
     *
     * @return list<string|null>
     */
    private function buildPlatformPicker(): array
    {
        $pool = [];
        foreach ($this->socialPlatformWeights as $platform => $weight) {
            $value = ($platform === 0 || $platform === '') ? null : ($platform ?: null);
            for ($i = 0; $i < $weight; $i++) {
                $pool[] = $value;
            }
        }

        return $pool;
    }

    /**
     * Prints a summary table showing coverage across hours and days.
     *
     * @param  int  $userId
     */
    private function printSummary(int $userId): void
    {
        $links = DB::table('links')->where('user_id', $userId)->orderBy('id')->get();

        $this->command->info(sprintf(
            '  %-22s  %7s  %6s  %6s  %6s  %6s  %6s',
            'slug', 'clicks', 'mobile', 'social', 'iab', 'viral↑', 'utm'
        ));

        $totalAll = 0;
        foreach ($links as $link) {
            $total  = DB::table('clicks')->where('link_id', $link->id)->count();
            $mobile = DB::table('clicks')->where('link_id', $link->id)->where('is_mobile', 1)->count();
            $social = DB::table('clicks')->where('link_id', $link->id)->whereNotNull('social_platform')->count();
            $iab    = DB::table('clicks')->where('link_id', $link->id)->where('navigation_context', 'in_app_webview')->count();
            $viral  = DB::table('clicks')->where('link_id', $link->id)->whereIn('viral_rank', ['viral', 'trending'])->count();
            $utm    = DB::table('link_utms')
                ->join('clicks', 'link_utms.click_id', '=', 'clicks.id')
                ->where('clicks.link_id', $link->id)->count();

            $totalAll += $total;
            $this->command->line(sprintf(
                '  %-22s  %7d  %6d  %6d  %6d  %6d  %6d',
                $link->slug, $total, $mobile, $social, $iab, $viral, $utm
            ));
        }

        $this->command->newLine();
        $this->command->info("  Total clicks across all links: {$totalAll}");
        $this->command->newLine();

        // Hour-of-day coverage heatmap (all links combined)
        $this->command->info('  Hour-of-day coverage (all links):');
        $hourRows = DB::table('clicks')
            ->join('links', 'clicks.link_id', '=', 'links.id')
            ->where('links.user_id', $userId)
            ->selectRaw('hour_of_day, count(*) as cnt')
            ->groupBy('hour_of_day')
            ->orderBy('hour_of_day')
            ->get();

        foreach ($hourRows as $r) {
            $bar  = str_repeat('█', min(60, (int) round($r->cnt / max(1, $totalAll / 24 / 2))));
            $this->command->line(sprintf('    %02d:xx  %6d  %s', $r->hour_of_day ?? 0, $r->cnt, $bar));
        }

        // Day-of-week coverage
        $this->command->newLine();
        $this->command->info('  Day-of-week coverage (all links):');
        $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $dowRows = DB::table('clicks')
            ->join('links', 'clicks.link_id', '=', 'links.id')
            ->where('links.user_id', $userId)
            ->selectRaw('day_of_week, count(*) as cnt')
            ->groupBy('day_of_week')
            ->orderBy('day_of_week')
            ->get();

        foreach ($dowRows as $r) {
            $dow  = $r->day_of_week ?? 0;
            $name = $dayNames[$dow] ?? '???';
            $bar  = str_repeat('█', min(60, (int) round($r->cnt / max(1, $totalAll / 7 / 2))));
            $this->command->line(sprintf('    %s  %7d  %s', $name, $r->cnt, $bar));
        }
    }
}
