<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds realistic multi-link demo data for the `/reports` module.
 *
 * Targets a single real user account (by email, falling back to the first
 * user in the table) and creates ~12 non-demo links plus a few thousand click
 * rows spread over the last 90 days. Every seeded link uses `is_demo = false`
 * because {@see \App\Services\Analytics\ReportsAnalyticsService} excludes
 * `is_demo = true` links from every aggregation — the opposite of what the
 * `is_demo` flag name might suggest for a "demo data" seeder.
 *
 * Idempotent: re-running deletes any previously seeded links (matched by the
 * `demo-rl-` slug prefix) for the target user — and their clicks, which cascade
 * via the `clicks.link_id` foreign key — before inserting fresh rows.
 *
 * Run:
 *   php artisan db:seed --class=ReportsDemoSeeder
 */
class ReportsDemoSeeder extends Seeder
{
    use ClickEnrichmentTrait;

    /** Email of the target account; falls back to the first user if not found. */
    private const TARGET_EMAIL = 'bcordeiro.dev@gmail.com';

    /** Slug prefix used to identify (and safely re-seed) demo links. */
    private const SLUG_PREFIX = 'demo-rl-';

    /** How many days back the clicks are spread over. */
    private const DAYS_BACK = 90;

    /** Fraction of clicks flagged as bots (is_bot = true). */
    private const BOT_RATE = 0.08;

    /** Fraction of clicks that reuse a previously seen IP (returning visitor). */
    private const IP_REPEAT_RATE = 0.40;

    /**
     * Link definitions: slug suffix, title, description, destination URL, and
     * the [min, max] click-count range used to size that link's traffic.
     *
     * @var list<array{suffix: string, title: string, description: string, url: string, min: int, max: int}>
     */
    private array $linkDefs = [
        ['suffix' => '1',  'title' => 'Lançamento Produto X',           'description' => 'Página de lançamento da nova linha de produtos',        'url' => 'https://loja.example.com/lancamento-produto-x',      'min' => 250, 'max' => 350],
        ['suffix' => '2',  'title' => 'Webinar Marketing Digital',      'description' => 'Inscrição para o webinar ao vivo sobre marketing digital', 'url' => 'https://webinar.example.com/marketing-digital',      'min' => 230, 'max' => 330],
        ['suffix' => '3',  'title' => 'E-book Gratuito — Guia de SEO',  'description' => 'Download do material gratuito sobre otimização SEO',    'url' => 'https://materiais.example.com/ebook-guia-seo',        'min' => 210, 'max' => 310],
        ['suffix' => '4',  'title' => 'Campanha Black Friday',          'description' => 'Landing page da campanha promocional de Black Friday',  'url' => 'https://loja.example.com/black-friday',               'min' => 290, 'max' => 390],
        ['suffix' => '5',  'title' => 'Podcast — Episódio 42',          'description' => 'Player do episódio mais recente do podcast',            'url' => 'https://podcast.example.com/episodio-42',             'min' => 180, 'max' => 260],
        ['suffix' => '6',  'title' => 'Curso Online — Data Analytics',  'description' => 'Página de vendas do curso de análise de dados',         'url' => 'https://cursos.example.com/data-analytics',           'min' => 250, 'max' => 350],
        ['suffix' => '7',  'title' => 'Newsletter — Edição 128',        'description' => 'Edição semanal da newsletter enviada por e-mail',        'url' => 'https://newsletter.example.com/edicao-128',           'min' => 140, 'max' => 220],
        ['suffix' => '8',  'title' => 'Landing Page — Beta Test',       'description' => 'Cadastro para o programa fechado de testes beta',       'url' => 'https://beta.example.com/cadastro',                   'min' => 200, 'max' => 280],
        ['suffix' => '9',  'title' => 'Vídeo Institucional',            'description' => 'Vídeo institucional publicado no canal do YouTube',     'url' => 'https://www.youtube.com/watch?v=demoRL9institucional', 'min' => 160, 'max' => 240],
        ['suffix' => '10', 'title' => 'Perfil LinkedIn — Empresa',      'description' => 'Página da empresa no LinkedIn',                          'url' => 'https://www.linkedin.com/company/example-demo',      'min' => 120, 'max' => 200],
        ['suffix' => '11', 'title' => 'Promoção Aplicativo Mobile',     'description' => 'Página de download promocional do aplicativo mobile',   'url' => 'https://apps.example.com/promo',                      'min' => 180, 'max' => 260],
        ['suffix' => '12', 'title' => 'Guia de Preços 2026',            'description' => 'Tabela de preços e planos vigentes em 2026',             'url' => 'https://example.com/precos',                          'min' => 100, 'max' => 180],
    ];

    /**
     * Country pool: weight (relative %), ISO code, display name, currency,
     * timezone, and continent code. BR is intentionally dominant.
     *
     * @var list<array{weight: int, iso: string, name: string, currency: string, timezone: string, continent: string}>
     */
    private array $countries = [
        ['weight' => 45, 'iso' => 'BR', 'name' => 'Brazil',        'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',    'continent' => 'SA'],
        ['weight' => 15, 'iso' => 'US', 'name' => 'United States', 'currency' => 'USD', 'timezone' => 'America/New_York',     'continent' => 'NA'],
        ['weight' => 10, 'iso' => 'PT', 'name' => 'Portugal',      'currency' => 'EUR', 'timezone' => 'Europe/Lisbon',        'continent' => 'EU'],
        ['weight' => 8,  'iso' => 'DE', 'name' => 'Germany',       'currency' => 'EUR', 'timezone' => 'Europe/Berlin',        'continent' => 'EU'],
        ['weight' => 7,  'iso' => 'FR', 'name' => 'France',        'currency' => 'EUR', 'timezone' => 'Europe/Paris',         'continent' => 'EU'],
        ['weight' => 8,  'iso' => 'ES', 'name' => 'Spain',         'currency' => 'EUR', 'timezone' => 'Europe/Madrid',        'continent' => 'EU'],
        ['weight' => 7,  'iso' => 'AR', 'name' => 'Argentina',     'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
    ];

    /**
     * Cities per ISO code, used to populate city/state/postal/lat/lng.
     *
     * @var array<string, list<array{city: string, state: string, state_name: string, lat: float, lng: float, postal: string}>>
     */
    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo',      'state' => 'SP', 'state_name' => 'São Paulo',        'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro',   'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília',       'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Belo Horizonte', 'state' => 'MG', 'state_name' => 'Minas Gerais',     'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30112-020'],
            ['city' => 'Curitiba',       'state' => 'PR', 'state_name' => 'Paraná',           'lat' => -25.4284, 'lng' => -49.2733, 'postal' => '80010-000'],
        ],
        'US' => [
            ['city' => 'New York',    'state' => 'NY', 'state_name' => 'New York',   'lat' => 40.7128, 'lng' => -74.0060,  'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California', 'lat' => 34.0522, 'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Miami',       'state' => 'FL', 'state_name' => 'Florida',    'lat' => 25.7617, 'lng' => -80.1918,  'postal' => '33101'],
        ],
        'PT' => [
            ['city' => 'Lisbon', 'state' => 'LIS', 'state_name' => 'Lisboa', 'lat' => 38.7223, 'lng' => -9.1393, 'postal' => '1100-148'],
            ['city' => 'Porto',  'state' => 'POR', 'state_name' => 'Porto',  'lat' => 41.1579, 'lng' => -8.6291, 'postal' => '4000-001'],
        ],
        'DE' => [
            ['city' => 'Berlin', 'state' => 'BE', 'state_name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050, 'postal' => '10115'],
            ['city' => 'Munich', 'state' => 'BY', 'state_name' => 'Bavaria', 'lat' => 48.1351, 'lng' => 11.5820, 'postal' => '80331'],
        ],
        'FR' => [
            ['city' => 'Paris', 'state' => 'IDF', 'state_name' => 'Île-de-France', 'lat' => 48.8566, 'lng' => 2.3522, 'postal' => '75001'],
            ['city' => 'Lyon',  'state' => 'ARA', 'state_name' => 'Auvergne-Rhône-Alpes', 'lat' => 45.7640, 'lng' => 4.8357, 'postal' => '69001'],
        ],
        'ES' => [
            ['city' => 'Madrid',    'state' => 'MD', 'state_name' => 'Comunidad de Madrid', 'lat' => 40.4168, 'lng' => -3.7038, 'postal' => '28001'],
            ['city' => 'Barcelona', 'state' => 'CT', 'state_name' => 'Catalonia',            'lat' => 41.3851, 'lng' => 2.1734,  'postal' => '08001'],
        ],
        'AR' => [
            ['city' => 'Buenos Aires', 'state' => 'BA', 'state_name' => 'Buenos Aires', 'lat' => -34.6037, 'lng' => -58.3816, 'postal' => 'C1002'],
            ['city' => 'Córdoba',      'state' => 'CB', 'state_name' => 'Córdoba',      'lat' => -31.4201, 'lng' => -64.1888, 'postal' => 'X5000'],
        ],
    ];

    /** IPv4 prefixes per ISO code, used to build synthetic (non-real) visitor IPs. */
    private array $ipPrefixes = [
        'BR' => '189.85.', 'US' => '173.252.', 'PT' => '195.22.',
        'DE' => '85.25.',  'FR' => '90.5.',     'ES' => '83.37.', 'AR' => '190.91.',
    ];

    /** @var list<string> */
    private array $mobileUAs = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.144 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.6045.193 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 12; Redmi Note 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36',
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
    private array $tabletUAs = [
        'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    /** @var list<string> Crawler/bot user agents used for the small is_bot=true slice. */
    private array $botUAs = [
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    ];

    /**
     * Referer pool per traffic-source bucket, mirroring the domain patterns
     * that {@see \App\Services\Links\LinkTrackingService::categorizeClickSource()}
     * and {@see \App\Services\Links\LinkTrackingService::detectSocialPlatform()}
     * recognise, so `click_source` / `social_platform` derived here stay
     * consistent with production categorisation rules.
     *
     * @var array<string, list<string>>
     */
    private array $refererPool = [
        'social' => [
            'https://www.instagram.com/',
            'https://www.tiktok.com/@example',
            'https://www.facebook.com/',
            'https://www.linkedin.com/feed/',
            'https://www.youtube.com/',
            'https://twitter.com/home',
            'https://web.whatsapp.com/',
        ],
        'search' => [
            'https://www.google.com/search?q=link+encurtado',
            'https://www.bing.com/search?q=link+encurtado',
            'https://duckduckgo.com/?q=link+encurtado',
        ],
        'referral' => [
            'https://dev.to/example',
            'https://medium.com/example',
            'https://news.ycombinator.com/',
            'https://www.reddit.com/r/example',
        ],
        'email' => [
            'https://mail.google.com/mail/u/0/',
            'https://outlook.live.com/mail/0/',
        ],
    ];

    /** Weighted traffic-source buckets: bucket => relative weight (direct = no referer). */
    private array $channelWeights = [
        'direct' => 35,
        'social' => 28,
        'search' => 17,
        'referral' => 15,
        'email' => 5,
    ];

    /** Pool of previously used IPs per ISO code, used to simulate returning visitors. */
    private array $ipPool = [];

    /**
     * Main entry point. Resolves the target user, wipes any previously seeded
     * demo-rl-* links/clicks for that user, then (re)creates them.
     */
    public function run(): void
    {
        $user = DB::table('users')->where('email', self::TARGET_EMAIL)->first();

        if (! $user) {
            $user = DB::table('users')->orderBy('id')->first();

            if (! $user) {
                $this->command->error('No users found in the database — cannot seed reports demo data.');

                return;
            }

            $this->command->warn(self::TARGET_EMAIL." not found. Falling back to first user: id={$user->id} email={$user->email}");
        } else {
            $this->command->info("Target user: id={$user->id} email={$user->email}");
        }

        DB::transaction(function () use ($user) {
            $this->purgePreviousDemoData($user->id);

            $totalLinks = 0;
            $totalClicks = 0;

            foreach ($this->linkDefs as $def) {
                [$linkId, $clickCount] = $this->seedLink($def, $user->id);
                $totalLinks++;
                $totalClicks += $clickCount;
            }

            $this->command->info("Seeded {$totalLinks} links and {$totalClicks} clicks for user {$user->id}.");
        });
    }

    /**
     * Deletes links previously seeded by this class (matched by slug prefix)
     * for the target user. Their `clicks` rows cascade-delete via the
     * `clicks_link_id_foreign` DB constraint (`ON DELETE CASCADE`), which in
     * turn cascades `link_utms` rows via `link_utms_click_id_foreign`.
     *
     * @param  int  $userId  Target user id.
     */
    private function purgePreviousDemoData(int $userId): void
    {
        $existingIds = DB::table('links')
            ->where('user_id', $userId)
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        if ($existingIds->isEmpty()) {
            return;
        }

        DB::table('links')->whereIn('id', $existingIds)->delete();

        $this->command->info('Purged '.$existingIds->count().' previously seeded demo-rl-* links (clicks cascaded).');
    }

    /**
     * Creates one demo link and inserts its click rows.
     *
     * @param  array{suffix: string, title: string, description: string, url: string, min: int, max: int}  $def
     * @return array{0: int, 1: int} [link_id, click_count]
     */
    private function seedLink(array $def, int $userId): array
    {
        $now = Carbon::now()->toDateTimeString();
        $slug = self::SLUG_PREFIX.$def['suffix'];

        $linkId = DB::table('links')->insertGetId([
            'slug' => $slug,
            'original_url' => $def['url'],
            'title' => $def['title'],
            'description' => $def['description'],
            'user_id' => $userId,
            'is_active' => true,
            'is_demo' => false,
            'clicks' => 0,
            'health_status' => 'unknown',
            'created_at' => Carbon::now()->subDays(self::DAYS_BACK + 5)->toDateTimeString(),
            'updated_at' => $now,
        ]);

        $target = mt_rand($def['min'], $def['max']);
        $dailyCounts = $this->buildDailyCounts($target);

        $rows = [];
        foreach ($dailyCounts as $daysAgo => $dayCount) {
            for ($i = 0; $i < $dayCount; $i++) {
                $rows[] = $this->buildClickRow($linkId, $daysAgo);
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('clicks')->insert($chunk);
        }

        $actualCount = count($rows);
        DB::table('links')->where('id', $linkId)->update(['clicks' => $actualCount]);

        $this->command->info("  {$slug} — {$actualCount} clicks (id={$linkId}).");

        return [$linkId, $actualCount];
    }

    /**
     * Distributes `$total` clicks across the last {@see self::DAYS_BACK} days.
     *
     * Combines an exponential recency bias (more clicks closer to today), a
     * mild weekday/weekend multiplier, per-day random noise, and occasional
     * "spike days" (2x-3.5x) so the resulting timeseries has visible shape
     * instead of a flat line. The weighted allocation is rounded down per day
     * and the rounding remainder is handed to the highest-weighted days so
     * the returned counts always sum to exactly `$total`.
     *
     * @param  int  $total  Total click count to distribute.
     * @return array<int, int> Map of daysAgo (0 = today .. DAYS_BACK-1) => click count.
     */
    private function buildDailyCounts(int $total): array
    {
        $weights = [];

        for ($daysAgo = 0; $daysAgo < self::DAYS_BACK; $daysAgo++) {
            $recency = 0.35 + 0.65 * exp(-$daysAgo / 40);
            $dowIso = Carbon::now()->subDays($daysAgo)->dayOfWeekIso; // 1=Mon .. 7=Sun
            $weekdayFactor = in_array($dowIso, [6, 7], true) ? 0.75 : 1.1;
            $noise = mt_rand(60, 140) / 100;
            $spike = mt_rand(1, 100) <= 6 ? mt_rand(20, 35) / 10 : 1.0;

            $weights[$daysAgo] = $recency * $weekdayFactor * $noise * $spike;
        }

        $sum = array_sum($weights);
        $counts = [];
        $allocated = 0;

        foreach ($weights as $daysAgo => $weight) {
            $counts[$daysAgo] = (int) floor($total * $weight / $sum);
            $allocated += $counts[$daysAgo];
        }

        $remainder = $total - $allocated;

        if ($remainder > 0) {
            arsort($weights);
            $orderedDays = array_keys($weights);
            $i = 0;
            while ($remainder > 0) {
                $counts[$orderedDays[$i % count($orderedDays)]]++;
                $remainder--;
                $i++;
            }
        }

        return $counts;
    }

    /**
     * Builds one fully-enriched click row for the given link and day offset.
     *
     * Merges {@see ClickEnrichmentTrait::enrichClickData()} output (device
     * parsing, quality scoring, temporal fields, language, connection type)
     * with fields the trait does not compute: link/ip/referer/geo, the
     * traffic-source pair (click_source/social_platform/navigation_context),
     * viral_rank, and the remaining Phase 1/2 columns.
     *
     * @return array<string, mixed> Row ready for `DB::table('clicks')->insert()`.
     */
    private function buildClickRow(int $linkId, int $daysAgo): array
    {
        $date = Carbon::now()->subDays($daysAgo)->setTime(
            mt_rand(0, 23),
            mt_rand(0, 59),
            mt_rand(0, 59)
        );

        $isBot = mt_rand(1, 100) <= (int) round(self::BOT_RATE * 100);

        $deviceRoll = mt_rand(1, 100);
        $device = match (true) {
            $isBot => 'bot',
            $deviceRoll <= 55 => 'mobile',
            $deviceRoll <= 90 => 'desktop',
            default => 'tablet',
        };

        $ua = match ($device) {
            'bot' => $this->botUAs[array_rand($this->botUAs)],
            'mobile' => $this->mobileUAs[array_rand($this->mobileUAs)],
            'tablet' => $this->tabletUAs[array_rand($this->tabletUAs)],
            default => $this->desktopUAs[array_rand($this->desktopUAs)],
        };

        $country = $this->pickWeightedCountry();
        $iso = $country['iso'];
        $cityOptions = $this->cities[$iso];
        $cityData = $cityOptions[array_rand($cityOptions)];

        $ip = $this->pickIp($iso);

        [$channel, $referer] = $this->pickChannel();
        $clickSource = $isBot ? 'unknown' : $channel;
        $socialPlatform = (! $isBot && $channel === 'social') ? $this->detectSocialPlatform($referer) : null;

        $enriched = $this->enrichClickData($ua, $device, $referer, $date, $iso);

        $viralRank = match (true) {
            $daysAgo <= 7 => (mt_rand(1, 100) <= 30 ? 'viral' : 'trending'),
            $daysAgo <= 20 => (mt_rand(1, 100) <= 40 ? 'trending' : 'warming'),
            $daysAgo <= 50 => (mt_rand(1, 100) <= 50 ? 'warming' : 'cold'),
            default => 'cold',
        };

        $isReturn = (bool) $enriched['is_return_visitor'];

        return array_merge($enriched, [
            'link_id' => $linkId,
            'ip' => $ip,
            'user_agent' => $ua,
            'referer' => $referer,
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
            'click_source' => $clickSource,
            'social_platform' => $socialPlatform,
            'viral_rank' => $viralRank,
            'seconds_since_last_click' => $isReturn ? mt_rand(5, 3600) : null,
            'ch_is_mobile' => $enriched['ch_platform'] !== null ? ($device === 'mobile') : null,
            'is_data_saver' => ! $isBot && $device === 'mobile' && mt_rand(1, 100) <= 3,
            'http_protocol' => mt_rand(1, 100) <= 85 ? 'HTTP/2' : 'HTTP/1.1',
            'fetch_dest' => $isBot ? null : 'document',
            'response_time' => round(mt_rand(300, 25000) / 100, 3),
            'accept_language' => "{$enriched['primary_language']}-{$enriched['language_region']},{$enriched['primary_language']};q=0.9,en;q=0.8",
            'dedup_key' => null,
            'ip_anonymized' => false,
            'created_at' => $date->toDateTimeString(),
            'updated_at' => $date->toDateTimeString(),
        ]);
    }

    /**
     * Picks a country using the relative weights in {@see self::$countries}.
     *
     * @return array{weight: int, iso: string, name: string, currency: string, timezone: string, continent: string}
     */
    private function pickWeightedCountry(): array
    {
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($this->countries as $country) {
            $cumulative += $country['weight'];
            if ($roll <= $cumulative) {
                return $country;
            }
        }

        return $this->countries[0];
    }

    /**
     * Returns a synthetic IP for the given country, reusing a previously
     * seen IP {@see self::IP_REPEAT_RATE} of the time to simulate returning
     * visitors (so `COUNT(DISTINCT ip)` comes out lower than total clicks).
     *
     * @param  string  $iso  Country ISO code.
     * @return string Synthetic (non-real) IPv4 address.
     */
    private function pickIp(string $iso): string
    {
        $pool = $this->ipPool[$iso] ?? [];

        if ($pool && mt_rand(1, 100) <= (int) round(self::IP_REPEAT_RATE * 100)) {
            return $pool[array_rand($pool)];
        }

        $prefix = $this->ipPrefixes[$iso] ?? '198.51.';
        $ip = $prefix.mt_rand(1, 254).'.'.mt_rand(1, 254);

        $this->ipPool[$iso][] = $ip;

        return $ip;
    }

    /**
     * Picks a traffic-source bucket using {@see self::$channelWeights} and
     * returns the bucket name plus a matching referer URL (null for direct).
     *
     * @return array{0: string, 1: string|null} [channel, referer]
     */
    private function pickChannel(): array
    {
        $roll = mt_rand(1, 100);
        $cumulative = 0;

        foreach ($this->channelWeights as $channel => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                if ($channel === 'direct') {
                    return ['direct', null];
                }

                $pool = $this->refererPool[$channel];

                return [$channel, $pool[array_rand($pool)]];
            }
        }

        return ['direct', null];
    }

    /**
     * Mirrors {@see \App\Services\Links\LinkTrackingService::detectSocialPlatform()}
     * closely enough for the fixed referer pool used by this seeder.
     *
     * @param  string|null  $referer  Referer URL chosen from {@see self::$refererPool}.
     * @return string|null Platform slug or null when the domain isn't recognised.
     */
    private function detectSocialPlatform(?string $referer): ?string
    {
        if (! $referer) {
            return null;
        }

        $domain = strtolower(parse_url($referer, PHP_URL_HOST) ?? '');

        return match (true) {
            str_contains($domain, 'instagram.com') => 'instagram',
            str_contains($domain, 'tiktok.com') => 'tiktok',
            str_contains($domain, 'facebook.com') => 'facebook',
            str_contains($domain, 'youtube.com') => 'youtube',
            str_contains($domain, 'twitter.com') => 'twitter',
            str_contains($domain, 'whatsapp.com') => 'whatsapp',
            str_contains($domain, 'linkedin.com') => 'linkedin',
            default => null,
        };
    }
}
