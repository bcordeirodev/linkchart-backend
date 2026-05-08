<?php

namespace Database\Seeders;

use Carbon\Carbon;

/**
 * Provides realistic enriched field generation for click seeders.
 *
 * Derives browser, OS, rendering engine, navigation context, quality tier,
 * language, connection type, and temporal fields from the inputs already
 * chosen by each seeder (user_agent, device, referer, date, country ISO).
 */
trait ClickEnrichmentTrait
{
    /**
     * Returns an array of enriched fields ready to merge into a click record.
     *
     * @param  string  $ua  User-Agent string
     * @param  string  $device  'mobile' | 'desktop' | 'tablet' | 'bot'
     * @param  string|null  $referer  Referer URL or null
     * @param  Carbon  $date  Click timestamp
     * @param  string  $iso  Country ISO code
     */
    private function enrichClickData(
        string $ua,
        string $device,
        ?string $referer,
        Carbon $date,
        string $iso
    ): array {
        $isBot = $device === 'bot';
        $isMobile = $device === 'mobile';
        $isTablet = $device === 'tablet';
        $isDesktop = $device === 'desktop';

        [$browser, $browserVersion, $os, $osVersion] = $this->parseBrowserOs($ua, $device);
        $engine = $this->deriveEngine($browser);
        $navContext = $this->deriveNavContext($device, $referer, $isBot);
        [$qualityTier, $qualityScore, $fingerprintScore] = $this->deriveQuality($isBot, $navContext);
        [$primaryLang, $langRegion] = $this->deriveLanguage($iso);
        $connType = $this->deriveConnectionType($device);
        $isReturn = ! $isBot && (mt_rand(1, 100) <= 28);
        $sessionClicks = $isReturn ? mt_rand(2, 5) : 1;

        $hour = (int) $date->format('H');
        $dow = (int) $date->format('N'); // 1=Mon … 7=Sun
        $isWeekend = $dow >= 6;
        $isBusinessHours = ! $isWeekend && $hour >= 9 && $hour < 18;

        return [
            'browser' => $browser,
            'browser_version' => $browserVersion,
            'os' => $os,
            'os_version' => $osVersion,
            'is_mobile' => $isMobile,
            'is_tablet' => $isTablet,
            'is_desktop' => $isDesktop,
            'is_bot' => $isBot,
            'rendering_engine' => $engine,
            'navigation_context' => $navContext,
            'quality_tier' => $qualityTier,
            'quality_score' => $qualityScore,
            'fingerprint_score' => $fingerprintScore,
            'primary_language' => $primaryLang,
            'language_region' => $langRegion,
            'connection_type' => $connType,
            'is_return_visitor' => $isReturn,
            'session_clicks' => $sessionClicks,
            'hour_of_day' => $hour,
            'day_of_week' => $dow,
            'day_of_month' => (int) $date->format('j'),
            'month' => (int) $date->format('n'),
            'year' => (int) $date->format('Y'),
            'is_weekend' => $isWeekend,
            'is_business_hours' => $isBusinessHours,
        ];
    }

    /** @return array{string, string|null, string, string|null} [browser, version, os, osVersion] */
    private function parseBrowserOs(string $ua, string $device): array
    {
        if ($device === 'bot') {
            if (str_contains($ua, 'Googlebot')) {
                return ['Googlebot', '2.1', 'Unknown', null];
            }
            if (str_contains($ua, 'bingbot')) {
                return ['Bingbot', '2.0', 'Unknown', null];
            }

            return ['Bot', null, 'Unknown', null];
        }

        // OS
        $os = 'Unknown';
        $osVersion = null;
        if (str_contains($ua, 'iPhone') || (str_contains($ua, 'iPad') && ! str_contains($ua, 'Android'))) {
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
            $map = ['10.0' => '10', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
            if (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
                $osVersion = $map[$m[1]] ?? $m[1];
            }
        } elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X')) {
            $os = 'OS X';
            if (preg_match('/Mac OS X ([\d_]+)/', $ua, $m)) {
                $osVersion = str_replace('_', '.', $m[1]);
            }
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        }

        // Browser
        $browser = 'Unknown';
        $version = null;
        if (str_contains($ua, 'Edg/') || str_contains($ua, 'Edge/')) {
            $browser = 'Edge';
            if (preg_match('/Edg(?:e)?[\/ ]([\d.]+)/', $ua, $m)) {
                $version = $m[1];
            }
        } elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera/')) {
            $browser = 'Opera';
            if (preg_match('/OPR\/([\d.]+)/', $ua, $m)) {
                $version = $m[1];
            }
        } elseif (str_contains($ua, 'Firefox/')) {
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

    private function deriveEngine(string $browser): string
    {
        return match (true) {
            in_array($browser, ['Chrome', 'Edge', 'Opera', 'Brave'], true) => 'blink',
            $browser === 'Firefox' => 'gecko',
            $browser === 'Safari' => 'webkit',
            $browser === 'IE' => 'trident',
            default => 'unknown',
        };
    }

    private function deriveNavContext(string $device, ?string $referer, bool $isBot): string
    {
        if ($isBot) {
            return 'api_programmatic';
        }

        if (empty($referer) || $referer === '-') {
            return 'browser_direct';
        }

        $socialDomains = ['instagram.com', 'facebook.com', 'tiktok.com', 'wa.me', 'whatsapp.com', 'youtube.com', 'youtu.be', 'm.youtube.com'];
        foreach ($socialDomains as $domain) {
            if (str_contains((string) $referer, $domain)) {
                return $device === 'mobile' ? 'in_app_webview' : 'browser_referral';
            }
        }

        return 'browser_referral';
    }

    /** @return array{string, int, int} [tier, score, fingerprint] */
    private function deriveQuality(bool $isBot, string $navContext): array
    {
        if ($isBot || $navContext === 'api_programmatic') {
            return ['likely_fraud', mt_rand(0, 20), mt_rand(2, 3)];
        }

        $roll = mt_rand(1, 100);
        if ($roll <= 78) {
            return ['organic', mt_rand(70, 100), mt_rand(0, 1)];
        }
        if ($roll <= 94) {
            return ['suspicious', mt_rand(30, 69), mt_rand(1, 2)];
        }

        return ['likely_fraud', mt_rand(0, 29), mt_rand(2, 3)];
    }

    /** @return array{string, string} [primaryLanguage, languageRegion] */
    private function deriveLanguage(string $iso): array
    {
        $map = [
            'BR' => ['pt', 'pt-BR'],
            'PT' => ['pt', 'pt-PT'],
            'US' => ['en', 'en-US'],
            'GB' => ['en', 'en-GB'],
            'AU' => ['en', 'en-AU'],
            'CA' => ['en', 'en-CA'],
            'DE' => ['de', 'de-DE'],
            'AT' => ['de', 'de-AT'],
            'FR' => ['fr', 'fr-FR'],
            'ES' => ['es', 'es-ES'],
            'MX' => ['es', 'es-MX'],
            'AR' => ['es', 'es-AR'],
            'CO' => ['es', 'es-CO'],
            'CL' => ['es', 'es-CL'],
            'IT' => ['it', 'it-IT'],
            'NL' => ['nl', 'nl-NL'],
            'PL' => ['pl', 'pl-PL'],
            'SE' => ['sv', 'sv-SE'],
            'RU' => ['ru', 'ru-RU'],
            'TR' => ['tr', 'tr-TR'],
            'JP' => ['ja', 'ja-JP'],
            'KR' => ['ko', 'ko-KR'],
            'CN' => ['zh', 'zh-CN'],
            'IN' => ['hi', 'hi-IN'],
        ];

        return $map[$iso] ?? ['en', 'en-US'];
    }

    private function deriveConnectionType(string $device): string
    {
        if ($device === 'mobile') {
            return mt_rand(1, 10) <= 6 ? 'cellular' : 'wifi';
        }

        return mt_rand(1, 10) <= 8 ? 'broadband' : 'wifi';
    }
}
