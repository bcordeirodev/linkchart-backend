<?php

namespace App\Services;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Serviço para análises avançadas específicas
 * Funcionalidades que estavam sendo perdidas
 */
class AdvancedAnalyticsService
{
    /**
     * Análise de User-Agent detalhada
     */
    public function getBrowserAnalytics(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)->get();
        $browsers = [];
        $os = [];

        foreach ($clicks as $click) {
            $userAgent = $click->user_agent;

            // Extrair browser
            $browser = $this->extractBrowser($userAgent);
            if (!isset($browsers[$browser])) {
                $browsers[$browser] = 0;
            }
            $browsers[$browser]++;

            // Extrair OS
            $operatingSystem = $this->extractOS($userAgent);
            if (!isset($os[$operatingSystem])) {
                $os[$operatingSystem] = 0;
            }
            $os[$operatingSystem]++;
        }

        // Ordenar por quantidade
        arsort($browsers);
        arsort($os);

        return [
            'browsers' => array_map(function($count, $browser) {
                return ['browser' => $browser, 'clicks' => $count];
            }, $browsers, array_keys($browsers)),
            'operating_systems' => array_map(function($count, $os) {
                return ['os' => $os, 'clicks' => $count];
            }, $os, array_keys($os))
        ];
    }

    /**
     * Análise de referrers detalhada com categorização
     */
    public function getRefererAnalytics(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)->get();
        $referrers = [];
        $socialMedia = [];
        $searchEngines = [];
        $direct = 0;

        foreach ($clicks as $click) {
            $referer = $click->referer;

            if (empty($referer) || $referer === '-') {
                $direct++;
                continue;
            }

            $domain = parse_url($referer, PHP_URL_HOST);
            if (!$domain) {
                $direct++;
                continue;
            }

            // Categorizar
            if ($this->isSocialMedia($domain)) {
                $socialName = $this->getSocialMediaName($domain);
                if (!isset($socialMedia[$socialName])) {
                    $socialMedia[$socialName] = 0;
                }
                $socialMedia[$socialName]++;
            } elseif ($this->isSearchEngine($domain)) {
                $searchName = $this->getSearchEngineName($domain);
                if (!isset($searchEngines[$searchName])) {
                    $searchEngines[$searchName] = 0;
                }
                $searchEngines[$searchName]++;
            } else {
                if (!isset($referrers[$domain])) {
                    $referrers[$domain] = 0;
                }
                $referrers[$domain]++;
            }
        }

        return [
            'direct_traffic' => $direct,
            'social_media' => $this->formatArray($socialMedia),
            'search_engines' => $this->formatArray($searchEngines),
            'other_referrers' => $this->formatArray($referrers),
            'total_clicks' => $clicks->count()
        ];
    }

    /**
     * Análise de padrões temporais avançados
     */
    public function getAdvancedTemporalAnalytics(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)->get();

        return [
            'hourly_patterns' => $this->getHourlyPatterns($clicks),
            'daily_patterns' => $this->getDailyPatterns($clicks),
            'weekly_trends' => $this->getWeeklyTrends($clicks),
            'monthly_trends' => $this->getMonthlyTrends($clicks),
            'peak_analysis' => $this->getPeakAnalysis($clicks),
            'timezone_analysis' => $this->getTimezoneAnalysis($clicks)
        ];
    }

    /**
     * Análise de conversão e engajamento
     */
    public function getEngagementAnalytics(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)->get();
        $link = Link::find($linkId);

        $uniqueIPs = $clicks->unique('ip')->count();
        $totalClicks = $clicks->count();

        // Análise de visitantes recorrentes
        $ipClickCounts = $clicks->groupBy('ip')->map->count();
        $returningVisitors = $ipClickCounts->filter(function($count) {
            return $count > 1;
        })->count();

        // Análise de sessões (cliques dentro de 30 minutos)
        $sessions = $this->calculateSessions($clicks);

        return [
            'total_clicks' => $totalClicks,
            'unique_visitors' => $uniqueIPs,
            'returning_visitors' => $returningVisitors,
            'new_visitors' => $uniqueIPs - $returningVisitors,
            'click_through_rate' => $uniqueIPs > 0 ? round($totalClicks / $uniqueIPs, 2) : 0,
            'return_rate' => $uniqueIPs > 0 ? round(($returningVisitors / $uniqueIPs) * 100, 2) : 0,
            'sessions' => $sessions,
            'avg_clicks_per_session' => count($sessions) > 0 ? round($totalClicks / count($sessions), 2) : 0,
            'link_age_days' => $link ? now()->diffInDays($link->created_at) : 0
        ];
    }

    /**
     * Análise de performance por região
     */
    public function getPerformanceByRegion(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)
            ->whereNotNull('country')
            ->where('country', '!=', 'localhost')
            ->get();

        $regions = [];

        foreach ($clicks as $click) {
            $key = $click->country;
            if (!isset($regions[$key])) {
                $regions[$key] = [
                    'country' => $click->country,
                    'iso_code' => $click->iso_code,
                    'currency' => $click->currency,
                    'clicks' => 0,
                    'unique_visitors' => [],
                    'devices' => [],
                    'peak_hours' => []
                ];
            }

            $regions[$key]['clicks']++;
            $regions[$key]['unique_visitors'][$click->ip] = true;
            $regions[$key]['devices'][$click->device] = ($regions[$key]['devices'][$click->device] ?? 0) + 1;

            $hour = $click->created_at->format('H');
            $regions[$key]['peak_hours'][$hour] = ($regions[$key]['peak_hours'][$hour] ?? 0) + 1;
        }

        // Processar dados
        foreach ($regions as $key => $region) {
            $regions[$key]['unique_visitors'] = count($region['unique_visitors']);
            $regions[$key]['top_device'] = $this->getTopItem($region['devices']);
            $regions[$key]['peak_hour'] = $this->getTopItem($region['peak_hours']);
            unset($regions[$key]['devices']);
            unset($regions[$key]['peak_hours']);
        }

        // Ordenar por cliques
        uasort($regions, function($a, $b) {
            return $b['clicks'] <=> $a['clicks'];
        });

        return array_values($regions);
    }

    /**
     * Relatório de qualidade de tráfego
     */
    public function getTrafficQualityReport(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)->get();

        $botClicks = $clicks->where('device', 'bot')->count();
        $humanClicks = $clicks->where('device', '!=', 'bot')->count();

        $suspiciousIPs = $clicks->groupBy('ip')
            ->filter(function($group) {
                return $group->count() > 10; // Mais de 10 cliques do mesmo IP
            })
            ->count();

        $rapidClicks = $this->detectRapidClicks($clicks);

        return [
            'total_clicks' => $clicks->count(),
            'human_clicks' => $humanClicks,
            'bot_clicks' => $botClicks,
            'quality_score' => $this->calculateQualityScore($clicks),
            'suspicious_ips' => $suspiciousIPs,
            'rapid_clicks_detected' => count($rapidClicks),
            'geographic_diversity' => $clicks->whereNotNull('country')->unique('country')->count(),
            'device_diversity' => $clicks->whereNotNull('device')->unique('device')->count(),
            'recommendations' => $this->generateQualityRecommendations($clicks)
        ];
    }

    // Métodos auxiliares privados

    private function extractBrowser(string $userAgent): string
    {
        $browsers = [
            'Chrome' => '/Chrome\/[\d\.]+/',
            'Firefox' => '/Firefox\/[\d\.]+/',
            'Safari' => '/Safari\/[\d\.]+/',
            'Edge' => '/Edge\/[\d\.]+/',
            'Opera' => '/Opera\/[\d\.]+/',
            'Internet Explorer' => '/MSIE [\d\.]+/',
        ];

        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return 'Unknown';
    }

    private function extractOS(string $userAgent): string
    {
        $os = [
            'Windows' => '/Windows NT/',
            'macOS' => '/Mac OS X/',
            'Linux' => '/Linux/',
            'Android' => '/Android/',
            'iOS' => '/iPhone|iPad/',
        ];

        foreach ($os as $name => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return 'Unknown';
    }

    private function isSocialMedia(string $domain): bool
    {
        $socialDomains = [
            'facebook.com', 'twitter.com', 'instagram.com', 'linkedin.com',
            'youtube.com', 'tiktok.com', 'whatsapp.com', 'telegram.org'
        ];

        return in_array($domain, $socialDomains);
    }

    private function getSocialMediaName(string $domain): string
    {
        $mapping = [
            'facebook.com' => 'Facebook',
            'twitter.com' => 'Twitter',
            'instagram.com' => 'Instagram',
            'linkedin.com' => 'LinkedIn',
            'youtube.com' => 'YouTube',
            'tiktok.com' => 'TikTok',
            'whatsapp.com' => 'WhatsApp',
            'telegram.org' => 'Telegram'
        ];

        return $mapping[$domain] ?? $domain;
    }

    private function isSearchEngine(string $domain): bool
    {
        $searchDomains = ['google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com'];
        return in_array($domain, $searchDomains);
    }

    private function getSearchEngineName(string $domain): string
    {
        $mapping = [
            'google.com' => 'Google',
            'bing.com' => 'Bing',
            'yahoo.com' => 'Yahoo',
            'duckduckgo.com' => 'DuckDuckGo'
        ];

        return $mapping[$domain] ?? $domain;
    }

    private function formatArray(array $data): array
    {
        arsort($data);
        return array_map(function($count, $name) {
            return ['name' => $name, 'clicks' => $count];
        }, $data, array_keys($data));
    }

    private function getHourlyPatterns($clicks): array
    {
        $patterns = array_fill(0, 24, 0);

        foreach ($clicks as $click) {
            $hour = (int) $click->created_at->format('H');
            $patterns[$hour]++;
        }

        return $patterns;
    }

    private function getDailyPatterns($clicks): array
    {
        $patterns = array_fill(0, 7, 0);

        foreach ($clicks as $click) {
            $day = (int) $click->created_at->format('w');
            $patterns[$day]++;
        }

        return $patterns;
    }

    private function getWeeklyTrends($clicks): array
    {
        $weeks = [];

        foreach ($clicks as $click) {
            $week = $click->created_at->format('Y-W');
            $weeks[$week] = ($weeks[$week] ?? 0) + 1;
        }

        ksort($weeks);
        return $weeks;
    }

    private function getMonthlyTrends($clicks): array
    {
        $months = [];

        foreach ($clicks as $click) {
            $month = $click->created_at->format('Y-m');
            $months[$month] = ($months[$month] ?? 0) + 1;
        }

        ksort($months);
        return $months;
    }

    private function getPeakAnalysis($clicks): array
    {
        $hourly = $this->getHourlyPatterns($clicks);
        $daily = $this->getDailyPatterns($clicks);

        $peakHour = array_keys($hourly, max($hourly))[0];
        $peakDay = array_keys($daily, max($daily))[0];

        $dayNames = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

        return [
            'peak_hour' => $peakHour,
            'peak_day' => $dayNames[$peakDay],
            'peak_hour_clicks' => max($hourly),
            'peak_day_clicks' => max($daily)
        ];
    }

    private function getTimezoneAnalysis($clicks): array
    {
        $timezones = [];

        foreach ($clicks as $click) {
            if ($click->timezone) {
                $timezones[$click->timezone] = ($timezones[$click->timezone] ?? 0) + 1;
            }
        }

        arsort($timezones);
        return $this->formatArray($timezones);
    }

    private function calculateSessions($clicks): array
    {
        $sessions = [];
        $clicksByIP = $clicks->groupBy('ip');

        foreach ($clicksByIP as $ip => $ipClicks) {
            $ipClicks = $ipClicks->sortBy('created_at');
            $currentSession = [];
            $lastClickTime = null;

            foreach ($ipClicks as $click) {
                $clickTime = $click->created_at;

                if ($lastClickTime && $clickTime->diffInMinutes($lastClickTime) > 30) {
                    // Nova sessão
                    if (count($currentSession) > 0) {
                        $sessions[] = $currentSession;
                    }
                    $currentSession = [$click];
                } else {
                    $currentSession[] = $click;
                }

                $lastClickTime = $clickTime;
            }

            if (count($currentSession) > 0) {
                $sessions[] = $currentSession;
            }
        }

        return $sessions;
    }

    private function getTopItem(array $items): string
    {
        if (empty($items)) {
            return 'N/A';
        }

        arsort($items);
        return array_keys($items)[0];
    }

    private function detectRapidClicks($clicks): array
    {
        $rapidClicks = [];
        $clicksByIP = $clicks->groupBy('ip');

        foreach ($clicksByIP as $ip => $ipClicks) {
            $ipClicks = $ipClicks->sortBy('created_at');
            $previousClick = null;

            foreach ($ipClicks as $click) {
                if ($previousClick && $click->created_at->diffInSeconds($previousClick->created_at) < 2) {
                    $rapidClicks[] = [
                        'ip' => $ip,
                        'time_diff' => $click->created_at->diffInSeconds($previousClick->created_at),
                        'clicks' => [$previousClick, $click]
                    ];
                }
                $previousClick = $click;
            }
        }

        return $rapidClicks;
    }

    private function calculateQualityScore($clicks): float
    {
        $total = $clicks->count();
        if ($total === 0) return 0;

        $humanClicks = $clicks->where('device', '!=', 'bot')->count();
        $uniqueIPs = $clicks->unique('ip')->count();
        $geographicDiversity = $clicks->whereNotNull('country')->unique('country')->count();

        $humanRatio = $humanClicks / $total;
        $uniqueRatio = $uniqueIPs / $total;
        $geoScore = min($geographicDiversity / 5, 1); // Max score at 5+ countries

        return round(($humanRatio * 0.5 + $uniqueRatio * 0.3 + $geoScore * 0.2) * 100, 1);
    }

    private function generateQualityRecommendations($clicks): array
    {
        $recommendations = [];

        $botPercentage = ($clicks->where('device', 'bot')->count() / $clicks->count()) * 100;
        if ($botPercentage > 20) {
            $recommendations[] = "Alto volume de tráfego de bots ({$botPercentage}%). Considere implementar proteção anti-bot.";
        }

        $uniqueRatio = $clicks->unique('ip')->count() / $clicks->count();
        if ($uniqueRatio < 0.3) {
            $recommendations[] = "Baixa diversidade de visitantes. Foque em atrair novos usuários.";
        }

        $countries = $clicks->whereNotNull('country')->unique('country')->count();
        if ($countries < 3) {
            $recommendations[] = "Tráfego concentrado geograficamente. Considere campanhas internacionais.";
        }

        return $recommendations;
    }
}
