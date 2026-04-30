<?php

namespace App\Services\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Services\Analytics\Support\UserAgentParser;
use Illuminate\Support\Facades\DB;

class AudienceAnalyticsService
{
    public function __construct(private readonly UserAgentParser $uaParser) {}

    public function getLinkAudienceAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);

        if (!Click::where('link_id', $linkId)->exists()) {
            return [
                'device_breakdown'   => [],
                'browser_breakdown'  => [],
                'os_breakdown'       => [],
                'browsers'           => [],
                'operating_systems'  => [],
                'device_performance' => [],
                'languages'          => [],
            ];
        }

        return [
            'device_breakdown'   => $this->getDeviceBreakdown($linkId),
            'browser_breakdown'  => $this->getBrowserBreakdown($linkId),
            'os_breakdown'       => $this->getOSBreakdown($linkId),
            'browsers'           => $this->getBrowserDistribution($linkId),
            'operating_systems'  => $this->getOSDistribution($linkId),
            'device_performance' => $this->getDevicePerformance($linkId),
            'languages'          => $this->getLanguageDistribution($linkId),
        ];
    }

    private function getDeviceBreakdown(int $linkId): array
    {
        $total = Click::where('link_id', $linkId)->count();

        return DB::table('clicks')
            ->selectRaw('device, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('device')
            ->groupBy('device')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'device'     => $r->device,
                'clicks'     => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    private function getBrowserBreakdown(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw("COALESCE(browser, 'Unknown') as browser, COUNT(*) as clicks")
            ->where('link_id', $linkId)
            ->groupBy('browser')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'browser' => $r->browser,
                'clicks'  => (int) $r->clicks,
            ])
            ->toArray();
    }

    private function getOSBreakdown(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw("COALESCE(os, 'Unknown') as os, COUNT(*) as clicks")
            ->where('link_id', $linkId)
            ->groupBy('os')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'os'     => $r->os,
                'clicks' => (int) $r->clicks,
            ])
            ->toArray();
    }

    private function getBrowserDistribution(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('browser, browser_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->where('link_id', $linkId)
            ->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')
            ->orderBy('clicks', 'desc')
            ->limit(15)
            ->get()
            ->map(fn($r) => [
                'browser'    => $r->browser,
                'version'    => $r->browser_version,
                'clicks'     => (int) $r->clicks,
                'percentage' => (float) $r->percentage,
            ])
            ->toArray();
    }

    private function getOSDistribution(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('os, os_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->where('link_id', $linkId)
            ->whereNotNull('os')
            ->groupBy('os', 'os_version')
            ->orderBy('clicks', 'desc')
            ->limit(15)
            ->get()
            ->map(fn($r) => [
                'os'         => $r->os,
                'version'    => $r->os_version,
                'clicks'     => (int) $r->clicks,
                'percentage' => (float) $r->percentage,
            ])
            ->toArray();
    }

    private function getDevicePerformance(int $linkId): array
    {
        return DB::table('clicks')
            ->selectRaw('device, AVG(response_time) as avg_response_time, MIN(response_time) as min_response_time, MAX(response_time) as max_response_time, COUNT(*) as total_clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('device')
            ->whereNotNull('response_time')
            ->groupBy('device')
            ->orderBy('avg_response_time', 'asc')
            ->get()
            ->map(fn($r) => [
                'device'            => $r->device,
                'avg_response_time' => round((float) $r->avg_response_time, 2),
                'min_response_time' => round((float) $r->min_response_time, 2),
                'max_response_time' => round((float) $r->max_response_time, 2),
                'total_clicks'      => (int) $r->total_clicks,
            ])
            ->toArray();
    }

    private function getLanguageDistribution(int $linkId): array
    {
        $clicks = DB::table('clicks')
            ->select('accept_language')
            ->where('link_id', $linkId)
            ->whereNotNull('accept_language')
            ->get();

        $counts = [];
        foreach ($clicks as $click) {
            $lang = $this->uaParser->extractPrimaryLanguage($click->accept_language);
            if ($lang) {
                $counts[$lang] = ($counts[$lang] ?? 0) + 1;
            }
        }

        arsort($counts);
        $total = array_sum($counts);

        return array_slice(
            array_map(
                fn($lang, $n) => [
                    'language'   => $lang,
                    'clicks'     => $n,
                    'percentage' => $total > 0 ? round($n / $total * 100, 2) : 0,
                ],
                array_keys($counts),
                $counts
            ),
            0,
            10
        );
    }
}
