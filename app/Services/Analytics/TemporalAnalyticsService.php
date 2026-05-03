<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

class TemporalAnalyticsService implements \App\Contracts\Analytics\TemporalAnalyticsInterface
{
    public function getLinkTemporalAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);

        if (!Click::where('link_id', $linkId)->exists()) {
            return ['clicks_by_hour' => [], 'clicks_by_day_of_week' => []];
        }

        return [
            'clicks_by_hour'          => $this->getClicksByHour($linkId),
            'clicks_by_day_of_week'   => $this->getClicksByDayOfWeek($linkId),
            'hourly_patterns_local'   => $this->getHourlyPatternsLocal($linkId),
            'weekend_vs_weekday'      => $this->getWeekendVsWeekday($linkId),
            'business_hours_analysis' => $this->getBusinessHoursAnalysis($linkId),
        ];
    }

    public function getAdvancedTemporalAnalytics(int $linkId): array
    {
        $clicks = Click::where('link_id', $linkId)->get();

        return [
            'hourly_patterns'   => $this->getHourlyPatterns($clicks),
            'daily_patterns'    => $this->getDailyPatterns($clicks),
            'weekly_trends'     => $this->getWeeklyTrends($clicks),
            'monthly_trends'    => $this->getMonthlyTrends($clicks),
            'peak_analysis'     => $this->getPeakAnalysis($clicks),
            'timezone_analysis' => $this->getTimezoneAnalysis($clicks),
        ];
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    private function getClicksByHour(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $rows = DB::table('clicks')
            ->where('link_id', $linkId)
            ->selectRaw("{$expr} as hour, count(*) as clicks")
            ->groupByRaw($expr)->orderByRaw('1')
            ->get()->keyBy('hour');

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = ['hour' => $h, 'clicks' => (int) ($rows->get($h)?->clicks ?? 0)];
        }
        return $result;
    }

    private function getClicksByDayOfWeek(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : "COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)";

        $rows  = DB::table('clicks')->where('link_id', $linkId)
            ->selectRaw("{$expr} as dow, count(*) as clicks")
            ->groupByRaw($expr)->get()->keyBy('dow');

        $names  = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) {
            $result[] = ['day' => $d, 'name' => $names[$d], 'clicks' => (int) ($rows->get($d)?->clicks ?? 0)];
        }
        return $result;
    }

    private function getHourlyPatternsLocal(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('hour_of_day, COUNT(*) as clicks, AVG(response_time) as avg_response_time, COUNT(DISTINCT ip) as unique_visitors')
            ->where('link_id', $linkId)->whereNotNull('hour_of_day')
            ->groupBy('hour_of_day')->orderBy('hour_of_day')->get()
            ->map(fn($r) => ['hour' => (int) $r->hour_of_day, 'clicks' => (int) $r->clicks, 'avg_response_time' => round((float) $r->avg_response_time, 2), 'unique_visitors' => (int) $r->unique_visitors])
            ->toArray();
    }

    private function getWeekendVsWeekday(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
            : "COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)";

        $rows     = DB::table('clicks')->where('link_id', $linkId)->selectRaw("({$expr}) as dow, count(*) as clicks")->groupByRaw($expr)->get();
        $weekday  = $rows->whereIn('dow', [1, 2, 3, 4, 5])->sum('clicks');
        $weekend  = $rows->whereIn('dow', [6, 7])->sum('clicks');
        $total    = $weekday + $weekend;

        return [
            'weekday' => ['clicks' => $weekday, 'percentage' => $total > 0 ? round($weekday / $total * 100, 2) : 0],
            'weekend' => ['clicks' => $weekend, 'percentage' => $total > 0 ? round($weekend / $total * 100, 2) : 0],
        ];
    }

    private function getBusinessHoursAnalysis(int $linkId): array
    {
        $expr = $this->isSqlite()
            ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
            : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)';

        $rows     = DB::table('clicks')->where('link_id', $linkId)->selectRaw("{$expr} as h, count(*) as clicks")->groupByRaw($expr)->get();
        $business = $rows->whereBetween('h', [9, 17])->sum('clicks');
        $after    = $rows->sum('clicks') - $business;
        $total    = $business + $after;

        return [
            'business_hours' => ['clicks' => $business, 'percentage' => $total > 0 ? round($business / $total * 100, 2) : 0],
            'after_hours'    => ['clicks' => $after,    'percentage' => $total > 0 ? round($after / $total * 100, 2) : 0],
        ];
    }

    // Advanced methods migrated from UserAgentAnalyticsService

    private function getHourlyPatterns($clicks): array
    {
        $patterns = array_fill(0, 24, 0);
        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            if ($h >= 0 && $h <= 23) $patterns[$h]++;
        }
        $result = [];
        for ($h = 0; $h < 24; $h++) $result[] = ['hour' => $h, 'clicks' => $patterns[$h]];
        return $result;
    }

    private function getDailyPatterns($clicks): array
    {
        $patterns = array_fill(1, 7, 0);
        foreach ($clicks as $click) {
            $d = $click->day_of_week ?? (int) $click->created_at->format('N');
            if ($d >= 1 && $d <= 7) $patterns[$d] = ($patterns[$d] ?? 0) + 1;
        }
        $names  = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $result = [];
        for ($d = 1; $d <= 7; $d++) $result[] = ['day' => $d, 'name' => $names[$d], 'clicks' => $patterns[$d]];
        return $result;
    }

    private function getWeeklyTrends($clicks): array
    {
        $weekly = [];
        foreach ($clicks as $click) {
            $w = $click->created_at->startOfWeek()->format('Y-W');
            $weekly[$w] = ($weekly[$w] ?? 0) + 1;
        }
        ksort($weekly);
        return array_map(fn($w, $n) => ['week' => $w, 'clicks' => $n], array_keys($weekly), $weekly);
    }

    private function getMonthlyTrends($clicks): array
    {
        $monthly = [];
        foreach ($clicks as $click) {
            $m = $click->created_at->format('Y-m');
            $monthly[$m] = ($monthly[$m] ?? 0) + 1;
        }
        ksort($monthly);
        return array_map(fn($m, $n) => ['month' => $m, 'clicks' => $n], array_keys($monthly), $monthly);
    }

    private function getPeakAnalysis($clicks): array
    {
        if ($clicks->isEmpty()) {
            return ['peak_hour' => null, 'peak_day' => null, 'peak_day_name' => null, 'peak_hour_clicks' => 0, 'peak_day_clicks' => 0];
        }

        $hourly = array_fill(0, 24, 0);
        $daily  = array_fill(1, 7, 0);
        foreach ($clicks as $click) {
            $h = $click->hour_of_day ?? (int) $click->created_at->format('H');
            $d = $click->day_of_week ?? (int) $click->created_at->format('N');
            if ($h >= 0 && $h <= 23) $hourly[$h]++;
            if ($d >= 1 && $d <= 7)  $daily[$d] = ($daily[$d] ?? 0) + 1;
        }
        $peakHour = (int) array_search(max($hourly), $hourly);
        $peakDay  = (int) array_search(max($daily), $daily);
        $names    = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        return [
            'peak_hour'        => $peakHour,
            'peak_day'         => $peakDay,
            'peak_day_name'    => $names[$peakDay] ?? 'Desconhecido',
            'peak_hour_clicks' => $hourly[$peakHour] ?? 0,
            'peak_day_clicks'  => $daily[$peakDay]   ?? 0,
        ];
    }

    private function getTimezoneAnalysis($clicks): array
    {
        $tzs = [];
        foreach ($clicks as $click) {
            $tz = $click->timezone ?? 'Unknown';
            $tzs[$tz] = ($tzs[$tz] ?? 0) + 1;
        }
        arsort($tzs);
        return array_map(fn($tz, $n) => ['name' => $tz, 'clicks' => $n], array_keys($tzs), $tzs);
    }
}
