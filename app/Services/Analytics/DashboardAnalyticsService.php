<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsService
{
    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array
    {
        $since = $hours > 0 ? now()->subHours($hours) : null;
        $link  = Link::find($linkId);

        if (!$link) {
            return $this->emptyDashboard();
        }

        $totalClicks = $this->countClicks($linkId, $since);
        $unique      = $this->countUnique($linkId, $since);
        $countries   = $this->countCountries($linkId, $since);

        return [
            'summary' => [
                'total_clicks'        => $totalClicks,
                'total_links'         => 1,
                'active_links'        => $link->is_active ? 1 : 0,
                'unique_visitors'     => $unique,
                'success_rate'        => $this->estimateSuccessRate($linkId, $since),
                'avg_response_time'   => $this->estimateResponseTime($linkId, $since),
                'countries_reached'   => $countries,
                'links_with_traffic'  => $totalClicks > 0 ? 1 : 0,
            ],
            'link_info' => [
                'id'           => $link->id,
                'title'        => $link->title,
                'short_url'    => $link->short_url,
                'original_url' => $link->original_url,
                'clicks'       => $totalClicks,
                'is_active'    => $link->is_active,
                'created_at'   => $link->created_at,
            ],
            'temporal_data' => [
                'clicks_by_hour'        => $this->getClicksByHour($linkId, $since),
                'clicks_by_day_of_week' => $this->getClicksByDayOfWeek($linkId, $since),
                'hourly_patterns_local' => $this->getHourlyPatternsLocal($linkId, $since),
                'weekend_vs_weekday'    => $this->getWeekendVsWeekday($linkId, $since),
                'business_hours_analysis' => $this->getBusinessHoursAnalysis($linkId, $since),
            ],
            'geographic_data' => [
                'heatmap_data'  => $this->getHeatmapData($linkId, $since),
                'top_countries' => $this->getTopCountries($linkId, $since),
                'top_states'    => $this->getTopStates($linkId, $since),
                'top_cities'    => $this->getTopCities($linkId, $since),
            ],
            'audience_data' => [
                'device_breakdown'   => $this->getDeviceBreakdown($linkId, $since),
                'browser_breakdown'  => $this->getBrowserBreakdown($linkId, $since),
                'os_breakdown'       => $this->getOsBreakdown($linkId, $since),
                'browsers'           => $this->getBrowserDistribution($linkId, $since),
                'operating_systems'  => $this->getOsDistribution($linkId, $since),
                'device_performance' => $this->getDevicePerformance($linkId, $since),
                'languages'          => $this->getLanguageDistribution($linkId, $since),
            ],
        ];
    }

    private function emptyDashboard(): array
    {
        return [
            'summary' => [
                'total_clicks'       => 0,
                'total_links'        => 1,
                'active_links'       => 0,
                'unique_visitors'    => 0,
                'success_rate'       => 0,
                'avg_response_time'  => 0,
                'countries_reached'  => 0,
                'links_with_traffic' => 0,
            ],
            'link_info'       => null,
            'temporal_data'   => [
                'clicks_by_hour'          => [],
                'clicks_by_day_of_week'   => [],
                'hourly_patterns_local'   => [],
                'weekend_vs_weekday'      => [],
                'business_hours_analysis' => [],
            ],
            'geographic_data' => [
                'heatmap_data'  => [],
                'top_countries' => [],
                'top_states'    => [],
                'top_cities'    => [],
            ],
            'audience_data'   => [
                'device_breakdown'   => [],
                'browser_breakdown'  => [],
                'os_breakdown'       => [],
                'browsers'           => [],
                'operating_systems'  => [],
                'device_performance' => [],
                'languages'          => [],
            ],
        ];
    }

    private function countClicks(int $linkId, ?Carbon $since): int
    {
        return Click::where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->count();
    }

    private function countUnique(int $linkId, ?Carbon $since): int
    {
        return Click::where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->distinct('ip')->count();
    }

    private function countCountries(int $linkId, ?Carbon $since): int
    {
        return Click::where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->distinct('country')->count();
    }

    private function estimateSuccessRate(int $linkId, ?Carbon $since): float
    {
        $total = $this->countClicks($linkId, $since);
        if ($total === 0) {
            return 100.0;
        }

        $ok = DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('response_time')->where('response_time', '<', 5000)
            ->count();

        return round(($ok / $total) * 100, 2);
    }

    private function estimateResponseTime(int $linkId, ?Carbon $since): float
    {
        return (float) (DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('response_time')
            ->avg('response_time') ?? 0);
    }

    private function getClicksByHour(int $linkId, ?Carbon $since): array
    {
        $sqlite   = DB::connection()->getDriverName() === 'sqlite';
        $hourExpr = $sqlite
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $rows = DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("{$hourExpr} as hour, count(*) as clicks")
            ->groupByRaw($hourExpr)
            ->orderByRaw('1')
            ->get()->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = [
                'hour'   => $h,
                'clicks' => (int) ($rows->get($h)?->clicks ?? 0),
                'label'  => sprintf('%02d:00', $h),
            ];
        }
        return $result;
    }

    private function getClicksByDayOfWeek(int $linkId, ?Carbon $since): array
    {
        $sqlite  = DB::connection()->getDriverName() === 'sqlite';
        $dowExpr = $sqlite
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : "COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)";

        $rows = DB::table('clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->selectRaw("{$dowExpr} as day, count(*) as clicks")
            ->groupByRaw($dowExpr)->get()->keyBy('day');

        $names  = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = [
                'day'      => $d,
                'day_name' => $names[$d],
                'clicks'   => (int) ($rows->get($d)?->clicks ?? 0),
            ];
        }
        return $result;
    }

    private function getHeatmapData(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone, COUNT(*) as clicks, MAX(created_at) as last_click')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereNotNull('country')->where('country', '!=', 'localhost')->where('country', '!=', '')
            ->groupBy('latitude', 'longitude', 'city', 'country', 'iso_code', 'currency', 'state_name', 'continent', 'timezone')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn($r) => [
                'lat'        => (float) $r->latitude,
                'lng'        => (float) $r->longitude,
                'city'       => $r->city ?: 'Cidade Desconhecida',
                'country'    => $r->country,
                'clicks'     => (int) $r->clicks,
                'iso_code'   => $r->iso_code,
                'currency'   => $r->currency,
                'state_name' => $r->state_name,
                'continent'  => $r->continent,
                'timezone'   => $r->timezone,
                'last_click' => $r->last_click,
            ])
            ->toArray();
    }

    private function getTopCountries(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('country, iso_code, currency, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('country')->where('country', '!=', 'localhost')
            ->groupBy('country', 'iso_code', 'currency')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['country' => $r->country, 'iso_code' => $r->iso_code, 'clicks' => (int) $r->clicks, 'currency' => $r->currency])
            ->toArray();
    }

    private function getTopStates(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('country, state, state_name, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('state')
            ->groupBy('country', 'state', 'state_name')
            ->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['country' => $r->country, 'state' => $r->state, 'state_name' => $r->state_name, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getTopCities(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('city, state, country, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('city')
            ->groupBy('city', 'state', 'country')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['city' => $r->city, 'state' => $r->state, 'country' => $r->country, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getDeviceBreakdown(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('device, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('device')
            ->groupBy('device')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['device' => $r->device, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getBrowserBreakdown(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw("COALESCE(browser, 'Unknown') as browser, COUNT(*) as clicks")
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->groupBy('browser')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['browser' => $r->browser, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getOsBreakdown(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw("COALESCE(os, 'Unknown') as os, COUNT(*) as clicks")
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->groupBy('os')->orderBy('clicks', 'desc')->limit(10)->get()
            ->map(fn($r) => ['os' => $r->os, 'clicks' => (int) $r->clicks])
            ->toArray();
    }

    private function getBrowserDistribution(int $linkId, ?Carbon $since): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            return DB::table('clicks')
                ->selectRaw('browser, browser_version, COUNT(*) as clicks')
                ->where('link_id', $linkId)
                ->when($since, fn($q) => $q->where('created_at', '>=', $since))
                ->whereNotNull('browser')
                ->groupBy('browser', 'browser_version')
                ->orderBy('clicks', 'desc')->limit(15)->get()
                ->map(fn($r) => ['browser' => $r->browser, 'version' => $r->browser_version, 'clicks' => (int) $r->clicks, 'percentage' => 0.0])
                ->toArray();
        }

        return DB::table('clicks')
            ->selectRaw('browser, browser_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')
            ->orderBy('clicks', 'desc')->limit(15)->get()
            ->map(fn($r) => ['browser' => $r->browser, 'version' => $r->browser_version, 'clicks' => (int) $r->clicks, 'percentage' => (float) $r->percentage])
            ->toArray();
    }

    private function getOsDistribution(int $linkId, ?Carbon $since): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($sqlite) {
            return DB::table('clicks')
                ->selectRaw('os, os_version, COUNT(*) as clicks')
                ->where('link_id', $linkId)
                ->when($since, fn($q) => $q->where('created_at', '>=', $since))
                ->whereNotNull('os')
                ->groupBy('os', 'os_version')
                ->orderBy('clicks', 'desc')->limit(15)->get()
                ->map(fn($r) => ['os' => $r->os, 'version' => $r->os_version, 'clicks' => (int) $r->clicks, 'percentage' => 0.0])
                ->toArray();
        }

        return DB::table('clicks')
            ->selectRaw('os, os_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('os')
            ->groupBy('os', 'os_version')
            ->orderBy('clicks', 'desc')->limit(15)->get()
            ->map(fn($r) => ['os' => $r->os, 'version' => $r->os_version, 'clicks' => (int) $r->clicks, 'percentage' => (float) $r->percentage])
            ->toArray();
    }

    private function getDevicePerformance(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('device, AVG(response_time) as avg_response_time, MIN(response_time) as min_response_time, MAX(response_time) as max_response_time, COUNT(*) as total_clicks')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('device')->whereNotNull('response_time')
            ->groupBy('device')->orderBy('avg_response_time', 'asc')->get()
            ->map(fn($r) => [
                'device'           => $r->device,
                'avg_response_time' => round((float) $r->avg_response_time, 2),
                'min_response_time' => round((float) $r->min_response_time, 2),
                'max_response_time' => round((float) $r->max_response_time, 2),
                'total_clicks'     => (int) $r->total_clicks,
            ])
            ->toArray();
    }

    private function getLanguageDistribution(int $linkId, ?Carbon $since): array
    {
        $clicks = DB::table('clicks')
            ->select('accept_language')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('accept_language')
            ->get();

        $languageCounts = [];
        foreach ($clicks as $click) {
            $language = $this->extractPrimaryLanguage($click->accept_language);
            if ($language) {
                $languageCounts[$language] = ($languageCounts[$language] ?? 0) + 1;
            }
        }

        arsort($languageCounts);
        $total = array_sum($languageCounts);
        if ($total === 0) {
            return [];
        }

        return array_slice(array_map(
            fn($lang, $cnt) => ['language' => $lang, 'clicks' => $cnt, 'percentage' => round(($cnt / $total) * 100, 2)],
            array_keys($languageCounts),
            $languageCounts
        ), 0, 10);
    }

    private function extractPrimaryLanguage(?string $acceptLanguage): ?string
    {
        if (!$acceptLanguage) {
            return null;
        }

        $languages   = explode(',', $acceptLanguage);
        $primaryLang = trim(explode(';', $languages[0])[0]);

        $languageMap = [
            'pt-BR' => 'Português (Brasil)',
            'pt'    => 'Português',
            'en'    => 'English',
            'en-US' => 'English (US)',
            'es'    => 'Español',
            'fr'    => 'Français',
            'de'    => 'Deutsch',
            'it'    => 'Italiano',
            'zh'    => '中文',
            'ja'    => '日本語',
            'ko'    => '한국어',
            'ar'    => 'العربية',
            'ru'    => 'Русский',
        ];

        return $languageMap[$primaryLang] ?? $primaryLang;
    }

    private function getHourlyPatternsLocal(int $linkId, ?Carbon $since): array
    {
        return DB::table('clicks')
            ->selectRaw('hour_of_day, COUNT(*) as clicks, AVG(response_time) as avg_response_time, COUNT(DISTINCT ip) as unique_visitors')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->whereNotNull('hour_of_day')
            ->groupBy('hour_of_day')->orderBy('hour_of_day')->get()
            ->map(fn($r) => [
                'hour'             => (int) $r->hour_of_day,
                'clicks'           => (int) $r->clicks,
                'avg_response_time' => round((float) $r->avg_response_time, 2),
                'unique_visitors'  => (int) $r->unique_visitors,
            ])
            ->toArray();
    }

    private function getWeekendVsWeekday(int $linkId, ?Carbon $since): array
    {
        $weekend = DB::table('clicks')
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->where('is_weekend', true)->first();

        $weekday = DB::table('clicks')
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->where('is_weekend', false)->first();

        return [
            'weekend' => [
                'clicks'           => (int) ($weekend->clicks ?? 0),
                'unique_visitors'  => (int) ($weekend->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($weekend->avg_response_time ?? 0), 2),
                'percentage'       => 0,
            ],
            'weekday' => [
                'clicks'           => (int) ($weekday->clicks ?? 0),
                'unique_visitors'  => (int) ($weekday->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($weekday->avg_response_time ?? 0), 2),
                'percentage'       => 0,
            ],
        ];
    }

    private function getBusinessHoursAnalysis(int $linkId, ?Carbon $since): array
    {
        $biz = DB::table('clicks')
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time, AVG(session_clicks) as avg_session_depth')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->where('is_business_hours', true)->first();

        $nonBiz = DB::table('clicks')
            ->selectRaw('COUNT(*) as clicks, COUNT(DISTINCT ip) as unique_visitors, AVG(response_time) as avg_response_time, AVG(session_clicks) as avg_session_depth')
            ->where('link_id', $linkId)
            ->when($since, fn($q) => $q->where('created_at', '>=', $since))
            ->where('is_business_hours', false)->first();

        return [
            'business_hours' => [
                'clicks'            => (int) ($biz->clicks ?? 0),
                'unique_visitors'   => (int) ($biz->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($biz->avg_response_time ?? 0), 2),
                'avg_session_depth' => round((float) ($biz->avg_session_depth ?? 0), 1),
                'time_range'        => '09:00-17:00',
            ],
            'non_business_hours' => [
                'clicks'            => (int) ($nonBiz->clicks ?? 0),
                'unique_visitors'   => (int) ($nonBiz->unique_visitors ?? 0),
                'avg_response_time' => round((float) ($nonBiz->avg_response_time ?? 0), 2),
                'avg_session_depth' => round((float) ($nonBiz->avg_session_depth ?? 0), 1),
                'time_range'        => '17:01-08:59',
            ],
        ];
    }
}
