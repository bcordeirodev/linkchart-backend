<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds multiple links with thousands of realistic clicks for the user bcordeiro.001@gmail.com.
 *
 * Creates 8 varied links across different niches and injects between 1,000–5,000
 * clicks per link, spread over the last 120 days, with full enrichment (geo, device,
 * UTM, viral_rank, social_platform, navigation_context).
 *
 * Run:
 *   php artisan db:seed --class=BcordeiroLinksSeeder
 *
 * Idempotent by slug: if a slug already exists in `links`, the seeder skips
 * creating that link (but will NOT add more clicks to it). Truncate clicks for the
 * relevant link IDs manually if you want a fresh run.
 */
class BcordeiroLinksSeeder extends Seeder
{
    use ClickEnrichmentTrait;

    /** Target user email. */
    private const USER_EMAIL = 'bcordeiro.001@gmail.com';

    /** @var array<int, array{name: string, iso: string, currency: string, timezone: string, continent: string}> */
    private array $countries = [
        ['name' => 'Brazil',         'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',   'continent' => 'SA'],
        ['name' => 'United States',  'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York',    'continent' => 'NA'],
        ['name' => 'Mexico',         'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'continent' => 'NA'],
        ['name' => 'Argentina',      'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
        ['name' => 'Colombia',       'iso' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota',      'continent' => 'SA'],
        ['name' => 'India',          'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata',        'continent' => 'AS'],
        ['name' => 'United Kingdom', 'iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London',       'continent' => 'EU'],
        ['name' => 'Germany',        'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin',       'continent' => 'EU'],
        ['name' => 'Portugal',       'iso' => 'PT', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon',       'continent' => 'EU'],
        ['name' => 'Spain',          'iso' => 'ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid',       'continent' => 'EU'],
    ];

    /** @var array<string, list<array{city: string, state: string, state_name: string, lat: float, lng: float, postal: string}>> */
    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo',        'state' => 'SP', 'state_name' => 'São Paulo',        'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro',   'state' => 'RJ', 'state_name' => 'Rio de Janeiro',   'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília',         'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Belo Horizonte',   'state' => 'MG', 'state_name' => 'Minas Gerais',     'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30112-020'],
        ],
        'US' => [
            ['city' => 'New York',         'state' => 'NY', 'state_name' => 'New York',         'lat' => 40.7128,  'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Los Angeles',      'state' => 'CA', 'state_name' => 'California',       'lat' => 34.0522,  'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Miami',            'state' => 'FL', 'state_name' => 'Florida',          'lat' => 25.7617,  'lng' => -80.1918, 'postal' => '33101'],
        ],
        'MX' => [['city' => 'Mexico City', 'state' => 'MC', 'state_name' => 'Mexico City',     'lat' => 19.4326,  'lng' => -99.1332, 'postal' => '06600']],
        'AR' => [['city' => 'Buenos Aires', 'state' => 'BA', 'state_name' => 'Buenos Aires',    'lat' => -34.6037, 'lng' => -58.3816, 'postal' => 'C1002']],
        'CO' => [['city' => 'Bogotá',      'state' => 'DC', 'state_name' => 'Cundinamarca',    'lat' => 4.7110,   'lng' => -74.0721, 'postal' => '110111']],
        'IN' => [['city' => 'Mumbai',      'state' => 'MH', 'state_name' => 'Maharashtra',     'lat' => 19.0760,  'lng' => 72.8777,  'postal' => '400001']],
        'GB' => [['city' => 'London',      'state' => 'ENG', 'state_name' => 'England',         'lat' => 51.5074,  'lng' => -0.1278,  'postal' => 'SW1A 1AA']],
        'DE' => [['city' => 'Berlin',      'state' => 'BE', 'state_name' => 'Berlin',          'lat' => 52.5200,  'lng' => 13.4050,  'postal' => '10115']],
        'PT' => [['city' => 'Lisbon',      'state' => 'LIS', 'state_name' => 'Lisboa',          'lat' => 38.7223,  'lng' => -9.1393,  'postal' => '1100-148']],
        'ES' => [['city' => 'Madrid',      'state' => 'MD', 'state_name' => 'Comunidad de Madrid', 'lat' => 40.4168, 'lng' => -3.7038, 'postal' => '28001']],
    ];

    /** @var list<string> */
    private array $mobileUAs = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.6045.193 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 12; Redmi Note 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 15_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/19G82 Instagram/263.0',
        'Mozilla/5.0 (Linux; Android 13; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/114.0.5735.57 Mobile Safari/537.36',
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

    /**
     * Link definitions: slug, original_url, title, description, click target range, UTM scenario.
     *
     * @var list<array{slug: string, url: string, title: string, description: string, min: int, max: int, utm_scenario: string|null}>
     */
    private array $linkDefs = [
        [
            'slug' => 'yt-curso-dev',
            'url' => 'https://www.youtube.com/@brunofullstack',
            'title' => 'Canal YouTube — Bruno Dev',
            'description' => 'Conteúdo de programação, dicas de carreira e projetos ao vivo',
            'min' => 3200,
            'max' => 4800,
            'utm_scenario' => 'social',
        ],
        [
            'slug' => 'lp-mentoria-2025',
            'url' => 'https://mentoria.brunodev.com.br/turma-2025',
            'title' => 'Mentoria Dev 2025 — Turma Aberta',
            'description' => 'Programa de mentoria individual para devs que querem acelerar a carreira',
            'min' => 2100,
            'max' => 3500,
            'utm_scenario' => 'email',
        ],
        [
            'slug' => 'gh-link-charts',
            'url' => 'https://github.com/bcordeiro/link-charts',
            'title' => 'Link Charts — GitHub',
            'description' => 'Repositório open source do encurtador de URLs com analytics avançado',
            'min' => 1400,
            'max' => 2200,
            'utm_scenario' => 'referral',
        ],
        [
            'slug' => 'ig-perfil-bruno',
            'url' => 'https://www.instagram.com/brunodev.br',
            'title' => 'Instagram @brunodev.br',
            'description' => 'Bastidores do desenvolvimento e dicas rápidas de código',
            'min' => 4200,
            'max' => 5800,
            'utm_scenario' => 'social',
        ],
        [
            'slug' => 'blog-nextjs15',
            'url' => 'https://brunodev.com.br/blog/nextjs-15-app-router-guia-completo',
            'title' => 'Next.js 15 App Router — Guia Completo',
            'description' => 'Tutorial passo a passo do App Router no Next.js 15 com TypeScript',
            'min' => 1800,
            'max' => 2900,
            'utm_scenario' => 'organic',
        ],
        [
            'slug' => 'li-perfil',
            'url' => 'https://www.linkedin.com/in/brunocordeiro-dev',
            'title' => 'LinkedIn — Bruno Cordeiro',
            'description' => 'Perfil profissional e conexões na área de tecnologia',
            'min' => 900,
            'max' => 1600,
            'utm_scenario' => 'referral',
        ],
        [
            'slug' => 'shop-livros-dev',
            'url' => 'https://amzn.to/livros-programacao-bruno',
            'title' => 'Lista de Livros Recomendados',
            'description' => 'Livros de programação que mudaram minha carreira (com affiliate)',
            'min' => 2600,
            'max' => 3800,
            'utm_scenario' => 'email',
        ],
        [
            'slug' => 'discord-comunidade',
            'url' => 'https://discord.gg/brunodev-comunidade',
            'title' => 'Discord — Comunidade Bruno Dev',
            'description' => 'Servidor Discord para devs em crescimento — tire dúvidas e networking',
            'min' => 3500,
            'max' => 4900,
            'utm_scenario' => 'social',
        ],
    ];

    /** UTM scenario pools. */
    private array $utmScenarios = [
        'social' => [
            ['source' => 'instagram',  'medium' => 'social',   'campaign' => 'bio_link'],
            ['source' => 'tiktok',     'medium' => 'social',   'campaign' => 'creator_bio'],
            ['source' => 'youtube',    'medium' => 'social',   'campaign' => 'description'],
            ['source' => 'twitter',    'medium' => 'social',   'campaign' => 'pinned_tweet'],
            ['source' => 'linkedin',   'medium' => 'social',   'campaign' => 'profile_link'],
        ],
        'email' => [
            ['source' => 'newsletter', 'medium' => 'email',    'campaign' => 'weekly_digest'],
            ['source' => 'sendgrid',   'medium' => 'email',    'campaign' => 'promo_may25'],
            ['source' => 'mailchimp',  'medium' => 'email',    'campaign' => 'launch_announcement'],
        ],
        'referral' => [
            ['source' => 'devto',      'medium' => 'referral', 'campaign' => null],
            ['source' => 'medium',     'medium' => 'referral', 'campaign' => null],
            ['source' => 'hashnode',   'medium' => 'referral', 'campaign' => null],
        ],
        'organic' => [
            ['source' => 'google',     'medium' => 'organic',  'campaign' => null],
            ['source' => 'bing',       'medium' => 'organic',  'campaign' => null],
            ['source' => 'duckduckgo', 'medium' => 'organic',  'campaign' => null],
        ],
    ];

    /** Social platform weights (platform => % of mobile clicks). */
    private array $socialPlatformWeights = [
        'instagram' => 28,
        'tiktok' => 22,
        'youtube' => 12,
        'facebook' => 8,
        'twitter' => 5,
        'whatsapp' => 4,
        'telegram' => 3,
        null => 18, // no platform (direct / browser referral)
    ];

    /**
     * Main entry point.
     */
    public function run(): void
    {
        $user = DB::table('users')->where('email', self::USER_EMAIL)->first();

        if (! $user) {
            $this->command->error('User "'.self::USER_EMAIL.'" not found. Create the account first (register via frontend or artisan tinker).');

            return;
        }

        $this->command->info("Found user ID {$user->id} ({$user->email}). Starting seeder…");

        foreach ($this->linkDefs as $def) {
            $this->processLink($def, $user->id);
        }

        $this->command->info('');
        $this->command->info('All done! Summary per link:');
        $this->printGlobalSummary($user->id);
    }

    /**
     * Creates (or reuses) a link and injects clicks for it.
     *
     * @param  array{slug: string, url: string, title: string, description: string, min: int, max: int, utm_scenario: string|null}  $def
     */
    private function processLink(array $def, int $userId): void
    {
        $now = Carbon::now()->toDateTimeString();

        // Check if slug already exists for this user
        $existing = DB::table('links')->where('slug', $def['slug'])->first();

        if ($existing) {
            $linkId = $existing->id;
            $this->command->warn("  Link slug '{$def['slug']}' already exists (ID {$linkId}). Skipping creation, adding clicks…");
        } else {
            $linkId = DB::table('links')->insertGetId([
                'slug' => $def['slug'],
                'original_url' => $def['url'],
                'title' => $def['title'],
                'description' => $def['description'],
                'user_id' => $userId,
                'is_active' => true,
                'is_demo' => false,
                'clicks' => 0,
                'health_status' => 'unknown',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->command->info("  Created link '{$def['slug']}' (ID {$linkId}).");
        }

        $targetClicks = mt_rand($def['min'], $def['max']);
        $this->command->info("  Generating {$targetClicks} clicks for '{$def['slug']}'…");

        $this->insertClicks($linkId, $targetClicks, $def['utm_scenario']);

        // Update denormalised counter
        $total = DB::table('clicks')->where('link_id', $linkId)->count();
        DB::table('links')->where('id', $linkId)->update(['clicks' => $total]);

        $this->command->info("  ✓ Link '{$def['slug']}' → {$total} total clicks.");
    }

    /**
     * Generates and batch-inserts realistic clicks for a link.
     *
     * @param  int  $count  Target total number of clicks to insert
     * @param  string|null  $utmScenario  Key in $this->utmScenarios or null
     */
    private function insertClicks(int $linkId, int $count, ?string $utmScenario): void
    {
        $today = Carbon::now();
        $clicks = [];
        $utmRows = [];

        // Decide how many clicks get UTM tags (~35 % of traffic)
        $utmCount = $utmScenario ? (int) round($count * 0.35) : 0;
        $utmPool = $utmScenario ? $this->utmScenarios[$utmScenario] : [];

        // Build social-platform picker based on weights
        $platformPicker = $this->buildPlatformPicker();

        for ($i = 0; $i < $count; $i++) {
            // Spread clicks over the last 120 days with a realistic recency bias:
            // 60 % of clicks within the last 30 days, 40 % in 31–120.
            $daysAgo = mt_rand(1, 100) <= 60
                ? mt_rand(0, 29)
                : mt_rand(30, 119);

            $date = $today->copy()->subDays($daysAgo)->setTime(
                mt_rand(7, 23),
                mt_rand(0, 59),
                mt_rand(0, 59)
            );

            $country = $this->countries[array_rand($this->countries)];
            $iso = $country['iso'];

            // Device distribution: 62 % mobile, 32 % desktop, 6 % tablet
            $deviceRoll = mt_rand(1, 100);
            $device = match (true) {
                $deviceRoll <= 62 => 'mobile',
                $deviceRoll <= 94 => 'desktop',
                default => 'tablet',
            };

            $ua = match ($device) {
                'mobile', 'tablet' => $this->mobileUAs[array_rand($this->mobileUAs)],
                default => $this->desktopUAs[array_rand($this->desktopUAs)],
            };

            $socialPlatform = null;
            $navContext = 'direct';

            if ($device === 'mobile') {
                $socialPlatform = $platformPicker[mt_rand(0, count($platformPicker) - 1)];

                if ($socialPlatform !== null) {
                    // Social platform clicks often arrive via in-app webview
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
                $navContext = match (mt_rand(1, 100)) {
                    default => mt_rand(1, 100) <= 55 ? 'direct' : 'browser_referral',
                };
            }

            // viral_rank based on recency (mirrors AnalyticsDemoSeeder logic)
            $viralRank = match (true) {
                $daysAgo <= 7 => (mt_rand(1, 100) <= 30 ? 'viral' : 'trending'),
                $daysAgo <= 20 => (mt_rand(1, 100) <= 40 ? 'trending' : 'warming'),
                $daysAgo <= 50 => (mt_rand(1, 100) <= 50 ? 'warming' : 'cold'),
                default => 'cold',
            };

            // Geo
            $cityOptions = $this->cities[$iso] ?? [['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.55, 'lng' => -46.63, 'postal' => '01310']];
            $cityData = $cityOptions[array_rand($cityOptions)];

            $ipPrefixes = [
                'BR' => '189.85.', 'US' => '173.252.', 'MX' => '200.34.',
                'AR' => '190.91.', 'CO' => '190.24.',  'IN' => '103.21.',
                'GB' => '86.1.',   'DE' => '85.25.',   'PT' => '195.22.',
                'ES' => '83.37.',
            ];
            $ip = ($ipPrefixes[$iso] ?? '192.168.').mt_rand(1, 254).'.'.mt_rand(1, 254);

            $enriched = $this->enrichClickData($ua, $device, null, $date, $iso);

            $click = array_merge($enriched, [
                'link_id' => $linkId,
                'ip' => $ip,
                'user_agent' => $ua,
                'referer' => null,
                'country' => $country['name'],
                'iso_code' => $iso,
                'city' => $cityData['city'],
                'state' => $cityData['state'],
                'state_name' => $cityData['state_name'],
                'postal_code' => $cityData['postal'],
                'latitude' => $cityData['lat'],
                'longitude' => $cityData['lng'],
                'timezone' => $country['timezone'],
                'continent' => $country['continent'],
                'currency' => $country['currency'],
                'social_platform' => $socialPlatform,
                'navigation_context' => $navContext,
                'viral_rank' => $viralRank,
                'created_at' => $date->toDateTimeString(),
                'updated_at' => $date->toDateTimeString(),
            ]);

            $clicks[] = $click;
        }

        // Batch insert clicks (500 rows per chunk to avoid packet size issues)
        foreach (array_chunk($clicks, 500) as $chunk) {
            DB::table('clicks')->insert($chunk);
        }

        // Fetch the IDs we just inserted so we can attach UTM rows
        if ($utmCount > 0 && $utmPool) {
            $insertedIds = DB::table('clicks')
                ->where('link_id', $linkId)
                ->orderByDesc('id')
                ->limit($count)
                ->pluck('id')
                ->toArray();

            $pickedIds = array_slice($insertedIds, 0, min($utmCount, count($insertedIds)));
            $nowStr = Carbon::now()->toDateTimeString();

            foreach ($pickedIds as $idx => $clickId) {
                $entry = $utmPool[$idx % count($utmPool)];
                $utmRows[] = [
                    'click_id' => $clickId,
                    'utm_source' => $entry['source'],
                    'utm_medium' => $entry['medium'],
                    'utm_campaign' => $entry['campaign'] ?? null,
                    'utm_term' => null,
                    'utm_content' => null,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }

            foreach (array_chunk($utmRows, 500) as $chunk) {
                DB::table('link_utms')->insert($chunk);
            }

            $this->command->info('    → '.count($utmRows).' UTM rows attached.');
        }
    }

    /**
     * Builds a flat array used as a weighted picker for social_platform.
     *
     * Each entry in the array is a platform slug (or null), repeated proportionally
     * to its weight. mt_rand(0, count - 1) then picks from this pool.
     *
     * @return list<string|null>
     */
    private function buildPlatformPicker(): array
    {
        $pool = [];
        foreach ($this->socialPlatformWeights as $platform => $weight) {
            for ($i = 0; $i < $weight; $i++) {
                $pool[] = $platform === 0 ? null : ($platform ?: null);
            }
        }

        return $pool;
    }

    /**
     * Prints a per-link summary table to the console after all seeding is done.
     */
    private function printGlobalSummary(int $userId): void
    {
        $links = DB::table('links')->where('user_id', $userId)->orderBy('id')->get();

        foreach ($links as $link) {
            $total = DB::table('clicks')->where('link_id', $link->id)->count();
            $mobile = DB::table('clicks')->where('link_id', $link->id)->where('is_mobile', 1)->count();
            $social = DB::table('clicks')->where('link_id', $link->id)->whereNotNull('social_platform')->count();
            $iab = DB::table('clicks')->where('link_id', $link->id)->where('navigation_context', 'in_app_webview')->count();
            $viral = DB::table('clicks')->where('link_id', $link->id)->whereIn('viral_rank', ['viral', 'trending'])->count();
            $withUtm = DB::table('link_utms')
                ->join('clicks', 'link_utms.click_id', '=', 'clicks.id')
                ->where('clicks.link_id', $link->id)
                ->count();

            $this->command->line(sprintf(
                '  %-20s  clicks=%5d  mobile=%4d  social=%4d  iab=%4d  viral/trending=%4d  utm=%4d',
                $link->slug,
                $total,
                $mobile,
                $social,
                $iab,
                $viral,
                $withUtm
            ));
        }
    }
}
