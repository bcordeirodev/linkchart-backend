<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds realistic demo click data to exercise all four frontend chart scenarios:
 *
 *  1. ViralRankMiniChart (Temporal tab)   — viral_rank spread across 30 days
 *  2. SocialPlatformSection (Audience tab) — social_platform distribution
 *  3. UtmSourceCard (Dashboard)           — link_utms rows with utm_source
 *  4. SocialAppCard (Dashboard)           — navigation_context='in_app_webview' + is_mobile=1
 *
 * Run: php artisan db:seed --class=AnalyticsDemoSeeder
 * To start fresh: truncate the clicks and link_utms tables first.
 */
class AnalyticsDemoSeeder extends Seeder
{
    /** @var array<int, array{name: string, iso: string, currency: string, timezone: string, continent: string}> */
    private array $countries = [
        ['name' => 'Brazil',         'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo',   'continent' => 'SA'],
        ['name' => 'United States',  'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York',    'continent' => 'NA'],
        ['name' => 'Mexico',         'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'continent' => 'NA'],
        ['name' => 'Argentina',      'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires','continent' => 'SA'],
        ['name' => 'Colombia',       'iso' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota',      'continent' => 'SA'],
        ['name' => 'India',          'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata',        'continent' => 'AS'],
        ['name' => 'United Kingdom', 'iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London',       'continent' => 'EU'],
        ['name' => 'Germany',        'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin',       'continent' => 'EU'],
    ];

    /** @var array<string, array{city: string, state: string, lat: float, lng: float}> */
    private array $cityMap = [
        'BR' => ['city' => 'São Paulo',  'state' => 'SP', 'state_name' => 'São Paulo',       'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
        'US' => ['city' => 'Miami',      'state' => 'FL', 'state_name' => 'Florida',         'lat' => 25.7617,  'lng' => -80.1918, 'postal' => '33101'],
        'MX' => ['city' => 'Mexico City','state' => 'MC', 'state_name' => 'Mexico City',     'lat' => 19.4326,  'lng' => -99.1332, 'postal' => '06600'],
        'AR' => ['city' => 'Buenos Aires','state' => 'BA', 'state_name' => 'Buenos Aires',   'lat' => -34.6037, 'lng' => -58.3816, 'postal' => 'C1002'],
        'CO' => ['city' => 'Bogotá',     'state' => 'DC', 'state_name' => 'Cundinamarca',    'lat' => 4.7110,   'lng' => -74.0721, 'postal' => '110111'],
        'IN' => ['city' => 'Mumbai',     'state' => 'MH', 'state_name' => 'Maharashtra',     'lat' => 19.0760,  'lng' => 72.8777,  'postal' => '400001'],
        'GB' => ['city' => 'London',     'state' => 'ENG','state_name' => 'England',         'lat' => 51.5074,  'lng' => -0.1278,  'postal' => 'SW1A 1AA'],
        'DE' => ['city' => 'Berlin',     'state' => 'BE', 'state_name' => 'Berlin',          'lat' => 52.5200,  'lng' => 13.4050,  'postal' => '10115'],
    ];

    /** @var array<string, string> iOS in-app webview user agents */
    private array $iosWebviewUAs = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/20A362',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21A329',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 15_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/19G82',
    ];

    /** @var array<string, string> Android in-app webview user agents */
    private array $androidWebviewUAs = [
        'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/109.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 12; SM-G998B) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/108.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 11; Redmi Note 10) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Mobile Safari/537.36',
    ];

    /** @var array<string, string> Regular mobile browser user agents */
    private array $mobileUAs = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36',
    ];

    /** @var array<string, string> Desktop user agents */
    private array $desktopUAs = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.1 Safari/605.1.15',
    ];

    /**
     * Main seeder entry point.
     *
     * Inserts demo clicks for every link in the database so all analytics charts
     * show data regardless of which link is being viewed in the UI.
     * Each run appends new rows — TRUNCATE clicks, link_utms first for a clean slate.
     */
    public function run(): void
    {
        $links = DB::table('links')->get();

        if ($links->isEmpty()) {
            $this->command->error('No links found. Create at least one link first.');

            return;
        }

        foreach ($links as $link) {
            $this->seedLink($link);
        }
    }

    /**
     * Seeds all demo data for a single link.
     *
     * @param  object  $link
     */
    private function seedLink(object $link): void
    {
        $linkId = $link->id;
        $this->command->info("Seeding demo analytics for link ID {$linkId} (slug: {$link->slug})...");

        $clicks = [];
        $utmRows = [];

        $today = Carbon::now();

        // ── Scenario 1: ViralRankMiniChart ────────────────────────────────────
        // 30 days of clicks, each day assigned a viral_rank based on a simulated
        // spike pattern: days 1-10 cold, 11-18 warming, 19-25 trending, 26-30 viral.
        $viralRankDays = $this->buildViralRankSchedule();

        foreach ($viralRankDays as $daysAgo => $rank) {
            $dayClicks = match ($rank) {
                'viral'    => mt_rand(15, 20),
                'trending' => mt_rand(8, 14),
                'warming'  => mt_rand(3, 7),
                default    => mt_rand(1, 3), // cold
            };

            for ($i = 0; $i < $dayClicks; $i++) {
                $date = $today->copy()->subDays($daysAgo)->setTime(
                    mt_rand(8, 22),
                    mt_rand(0, 59),
                    mt_rand(0, 59)
                );

                $country = $this->countries[array_rand($this->countries)];
                $ua = $this->desktopUAs[array_rand($this->desktopUAs)];

                $clicks[] = $this->buildClick($linkId, $ua, $date, $country, 'desktop', 'browser_referral', null, $rank);
            }
        }

        // ── Scenario 2: SocialPlatformSection ────────────────────────────────
        // 60 clicks with social_platform set, weighted by platform popularity.
        $platformWeights = [
            'instagram' => 24,
            'tiktok'    => 21,
            'youtube'   => 9,
            'facebook'  => 3,
            'twitter'   => 1,
            'whatsapp'  => 1,
            'telegram'  => 1,
        ];

        foreach ($platformWeights as $platform => $count) {
            for ($i = 0; $i < $count; $i++) {
                $daysAgo = mt_rand(0, 29);
                $date = $today->copy()->subDays($daysAgo)->setTime(mt_rand(9, 21), mt_rand(0, 59), mt_rand(0, 59));
                $country = $this->countries[array_rand($this->countries)];
                $ua = $this->mobileUAs[array_rand($this->mobileUAs)];

                $clicks[] = $this->buildClick($linkId, $ua, $date, $country, 'mobile', 'browser_referral', $platform, null);
            }
        }

        // ── Scenario 3: SocialAppCard ─────────────────────────────────────────
        // 60 clicks with navigation_context=in_app_webview and is_mobile=1.
        // ~55% iOS, ~45% Android.
        $iabTotal = 60;
        $iosCount  = (int) round($iabTotal * 0.55);
        $droidCount = $iabTotal - $iosCount;

        for ($i = 0; $i < $iosCount; $i++) {
            $daysAgo = mt_rand(0, 29);
            $date = $today->copy()->subDays($daysAgo)->setTime(mt_rand(9, 22), mt_rand(0, 59), mt_rand(0, 59));
            $country = $this->countries[array_rand($this->countries)];
            $ua = $this->iosWebviewUAs[array_rand($this->iosWebviewUAs)];

            $clicks[] = $this->buildClick($linkId, $ua, $date, $country, 'mobile', 'in_app_webview', null, null, 'iOS', '16.0');
        }

        for ($i = 0; $i < $droidCount; $i++) {
            $daysAgo = mt_rand(0, 29);
            $date = $today->copy()->subDays($daysAgo)->setTime(mt_rand(9, 22), mt_rand(0, 59), mt_rand(0, 59));
            $country = $this->countries[array_rand($this->countries)];
            $ua = $this->androidWebviewUAs[array_rand($this->androidWebviewUAs)];

            $clicks[] = $this->buildClick($linkId, $ua, $date, $country, 'mobile', 'in_app_webview', null, null, 'Android', '13');
        }

        // ── Insert all clicks in batches ──────────────────────────────────────
        $insertedIds = [];
        foreach (array_chunk($clicks, 200) as $chunk) {
            DB::table('clicks')->insert($chunk);
        }

        // Retrieve the IDs of the just-inserted rows (last N rows for this link)
        $totalInserted = count($clicks);
        $insertedIds = DB::table('clicks')
            ->where('link_id', $linkId)
            ->orderByDesc('id')
            ->limit($totalInserted)
            ->pluck('id')
            ->toArray();

        $this->command->info("Inserted {$totalInserted} clicks.");

        // ── Scenario 4: UtmSourceCard ─────────────────────────────────────────
        // Pick 40 random click IDs from the pool and attach UTM rows to them.
        $utmSources = [
            ['source' => 'instagram',   'medium' => 'social',  'campaign' => 'bio_link'],
            ['source' => 'tiktok',      'medium' => 'social',  'campaign' => 'creator_bio'],
            ['source' => 'linktree',    'medium' => 'referral','campaign' => null],
            ['source' => 'newsletter',  'medium' => 'email',   'campaign' => 'weekly_digest'],
            ['source' => 'google',      'medium' => 'cpc',     'campaign' => 'brand_search'],
        ];

        $utmClickIds = array_slice($insertedIds, 0, min(40, count($insertedIds)));
        $now = Carbon::now()->toDateTimeString();

        foreach ($utmClickIds as $idx => $clickId) {
            $entry = $utmSources[$idx % count($utmSources)];
            $utmRows[] = [
                'click_id'     => $clickId,
                'utm_source'   => $entry['source'],
                'utm_medium'   => $entry['medium'],
                'utm_campaign' => $entry['campaign'],
                'utm_term'     => null,
                'utm_content'  => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        DB::table('link_utms')->insert($utmRows);
        $this->command->info('Inserted '.count($utmRows).' UTM rows.');

        // ── Update denormalised click counter on the link ─────────────────────
        DB::table('links')
            ->where('id', $linkId)
            ->update(['clicks' => DB::table('clicks')->where('link_id', $linkId)->count()]);

        $this->command->info('Done. Summary:');
        $this->printSummary($linkId);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns a day-index => viral_rank map for 30 days.
     *
     * Days 0-9 cold, 10-16 warming, 17-23 trending, 24-29 viral
     * (index 0 = today, 29 = 29 days ago — reversed for recent spike story).
     *
     * @return array<int, string>
     */
    private function buildViralRankSchedule(): array
    {
        $schedule = [];
        for ($d = 0; $d <= 29; $d++) {
            $schedule[$d] = match (true) {
                $d <= 9  => 'viral',
                $d <= 16 => 'trending',
                $d <= 23 => 'warming',
                default  => 'cold',
            };
        }

        return $schedule;
    }

    /**
     * Builds a single click record array ready for DB::table()->insert().
     *
     * @param  int  $linkId
     * @param  string  $ua  User-Agent string
     * @param  Carbon  $date  Timestamp for the click
     * @param  array<string, string>  $country  Country data from $this->countries
     * @param  string  $device  'mobile' | 'desktop' | 'tablet'
     * @param  string  $navContext  navigation_context value
     * @param  string|null  $socialPlatform  social_platform value or null
     * @param  string|null  $viralRank  viral_rank value or null
     * @param  string|null  $osOverride  OS override (used for IAB clicks)
     * @param  string|null  $osVersionOverride  OS version override
     * @return array<string, mixed>
     */
    private function buildClick(
        int $linkId,
        string $ua,
        Carbon $date,
        array $country,
        string $device,
        string $navContext,
        ?string $socialPlatform,
        ?string $viralRank,
        ?string $osOverride = null,
        ?string $osVersionOverride = null,
    ): array {
        $isMobile  = $device === 'mobile';
        $isTablet  = $device === 'tablet';
        $isDesktop = $device === 'desktop';

        $iso = $country['iso'];
        $cityData = $this->cityMap[$iso] ?? $this->cityMap['BR'];

        [$browser, $browserVersion, $osDetected, $osVersionDetected] = $this->parseBrowserOs($ua, $device);
        $os        = $osOverride        ?? $osDetected;
        $osVersion = $osVersionOverride ?? $osVersionDetected;

        $engine = $this->deriveEngine($browser);
        [$lang, $langRegion] = $this->deriveLanguage($iso);
        $connType = $isMobile ? (mt_rand(1, 10) <= 6 ? 'cellular' : 'wifi') : 'broadband';

        $hour = (int) $date->format('H');
        $dow  = (int) $date->format('N'); // 1=Mon … 7=Sun
        $isWeekend = $dow >= 6;

        $ipRanges = [
            'BR' => '189.85.', 'US' => '173.252.', 'MX' => '200.34.',
            'AR' => '190.91.', 'CO' => '190.24.',  'IN' => '103.21.',
            'GB' => '86.1.',   'DE' => '85.25.',
        ];
        $ipPrefix = $ipRanges[$iso] ?? '192.168.';
        $ip = $ipPrefix.mt_rand(1, 254).'.'.mt_rand(1, 254);

        return [
            'link_id'           => $linkId,
            'ip'                => $ip,
            'user_agent'        => $ua,
            'referer'           => null,
            'country'           => $country['name'],
            'iso_code'          => $iso,
            'city'              => $cityData['city'],
            'state'             => $cityData['state'],
            'state_name'        => $cityData['state_name'],
            'postal_code'       => $cityData['postal'],
            'latitude'          => $cityData['lat'],
            'longitude'         => $cityData['lng'],
            'timezone'          => $country['timezone'],
            'continent'         => $country['continent'],
            'currency'          => $country['currency'],
            'device'            => $device,
            'browser'           => $browser,
            'browser_version'   => $browserVersion,
            'os'                => $os,
            'os_version'        => $osVersion,
            'is_mobile'         => $isMobile,
            'is_tablet'         => $isTablet,
            'is_desktop'        => $isDesktop,
            'is_bot'            => false,
            'navigation_context'=> $navContext,
            'social_platform'   => $socialPlatform,
            'viral_rank'        => $viralRank,
            'rendering_engine'  => $engine,
            'primary_language'  => $lang,
            'language_region'   => $langRegion,
            'connection_type'   => $connType,
            'quality_tier'      => 'organic',
            'quality_score'     => mt_rand(70, 100),
            'fingerprint_score' => 0,
            'is_return_visitor' => false,
            'session_clicks'    => 1,
            'hour_of_day'       => $hour,
            'day_of_week'       => $dow,
            'day_of_month'      => (int) $date->format('j'),
            'month'             => (int) $date->format('n'),
            'year'              => (int) $date->format('Y'),
            'is_weekend'        => $isWeekend,
            'is_business_hours' => ! $isWeekend && $hour >= 9 && $hour < 18,
            'created_at'        => $date->toDateTimeString(),
            'updated_at'        => $date->toDateTimeString(),
        ];
    }

    /** @return array{string, string|null, string, string|null} [browser, version, os, osVersion] */
    private function parseBrowserOs(string $ua, string $device): array
    {
        $os = 'Unknown';
        $osVersion = null;

        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $os = 'iOS';
            if (preg_match('/CPU (?:iPhone )?OS ([\d_]+)/', $ua, $m)) {
                $osVersion = str_replace('_', '.', $m[1]);
            }
        } elseif (str_contains($ua, 'Android')) {
            $os = 'Android';
            if (preg_match('/Android ([\d.]+)/', $ua, $m)) {
                $osVersion = $m[1];
            }
        } elseif (str_contains($ua, 'Windows NT')) {
            $os = 'Windows';
            if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
                $osVersion = $m[1];
            }
        } elseif (str_contains($ua, 'Macintosh')) {
            $os = 'OS X';
            if (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) {
                $osVersion = str_replace('_', '.', $m[1]);
            }
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        }

        $browser = 'Chrome';
        $version = null;

        if (str_contains($ua, 'Firefox/')) {
            $browser = 'Firefox';
            if (preg_match('/Firefox\/([\d.]+)/', $ua, $m)) {
                $version = $m[1];
            }
        } elseif (str_contains($ua, 'Chrome/') && ! str_contains($ua, 'Chromium')) {
            $browser = 'Chrome';
            if (preg_match('/Chrome\/([\d.]+)/', $ua, $m)) {
                $version = $m[1];
            }
        } elseif (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/')) {
            $browser = 'Safari';
            if (preg_match('/Version\/([\d.]+)/', $ua, $m)) {
                $version = $m[1];
            }
        }

        return [$browser, $version, $os, $osVersion];
    }

    /** Derive rendering engine name from browser. */
    private function deriveEngine(string $browser): string
    {
        return match ($browser) {
            'Chrome', 'Edge', 'Opera' => 'blink',
            'Firefox'                 => 'gecko',
            'Safari'                  => 'webkit',
            default                   => 'unknown',
        };
    }

    /**
     * Returns primary language and region tag for a country ISO code.
     *
     * @return array{string, string} [primaryLanguage, languageRegion]
     */
    private function deriveLanguage(string $iso): array
    {
        $map = [
            'BR' => ['pt', 'pt-BR'], 'US' => ['en', 'en-US'],
            'MX' => ['es', 'es-MX'], 'AR' => ['es', 'es-AR'],
            'CO' => ['es', 'es-CO'], 'IN' => ['hi', 'hi-IN'],
            'GB' => ['en', 'en-GB'], 'DE' => ['de', 'de-DE'],
        ];

        return $map[$iso] ?? ['en', 'en-US'];
    }

    /** Print a brief post-seed summary to the console. */
    private function printSummary(int $linkId): void
    {
        $total = DB::table('clicks')->where('link_id', $linkId)->count();
        $withViralRank = DB::table('clicks')->where('link_id', $linkId)
            ->whereNotNull('viral_rank')->whereIn('viral_rank', ['warming', 'trending', 'viral'])->count();
        $withSocialPlatform = DB::table('clicks')->where('link_id', $linkId)
            ->whereNotNull('social_platform')->count();
        $withUtm = DB::table('clicks')->where('link_id', $linkId)
            ->join('link_utms', 'clicks.id', '=', 'link_utms.click_id')
            ->whereNotNull('link_utms.utm_source')->count();
        $withIab = DB::table('clicks')->where('link_id', $linkId)
            ->where('navigation_context', 'in_app_webview')->where('is_mobile', 1)->count();
        $iosPct = DB::table('clicks')->where('link_id', $linkId)
            ->where('navigation_context', 'in_app_webview')->where('os', 'iOS')->count();
        $droidPct = DB::table('clicks')->where('link_id', $linkId)
            ->where('navigation_context', 'in_app_webview')->where('os', 'Android')->count();

        $this->command->info("  Total clicks for link {$linkId}: {$total}");
        $this->command->info("  ViralRankMiniChart  — non-cold viral_rank rows: {$withViralRank}");
        $this->command->info("  SocialPlatformSection — social_platform rows: {$withSocialPlatform}");
        $this->command->info("  UtmSourceCard       — UTM-linked clicks: {$withUtm}");
        $this->command->info("  SocialAppCard       — IAB clicks: {$withIab} (iOS: {$iosPct}, Android: {$droidPct})");
    }
}
