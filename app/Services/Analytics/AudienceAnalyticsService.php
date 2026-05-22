<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use App\Services\Analytics\Support\UserAgentParser;
use Illuminate\Support\Facades\DB;

/**
 * Computes audience analytics (device, browser, OS, language, quality) for a link.
 *
 * @see \App\Contracts\Analytics\AudienceAnalyticsInterface
 *
 * Aggregates data from the clicks table using raw SQL queries for performance.
 * Phase-aware: several breakdowns (platform_breakdown, connection_type_breakdown,
 * rendering_engine, navigation_context_breakdown, return_visitor_stats, quality_breakdown)
 * return partial or empty results for clicks recorded before the corresponding
 * Phase 1/2/3 schema migrations.
 *
 * All queries respect the supplied `AnalyticsFilters` (date range + bot exclusion).
 * The total denominator used for percentage calculations is always derived from the
 * same filtered dataset so that percentages stay internally consistent.
 *
 * Side effects: read-only queries on clicks. No cache, no queue, no log calls.
 *
 * Language distribution uses two strategies:
 *   - languages: real-time parsing via UserAgentParser::extractPrimaryLanguage
 *     (maps accept_language raw string to a display name).
 *   - language_breakdown: uses pre-parsed primary_language + language_region columns
 *     (Phase 1), which is faster on large datasets.
 */
class AudienceAnalyticsService implements \App\Contracts\Analytics\AudienceAnalyticsInterface
{
    public function __construct(private readonly UserAgentParser $uaParser) {}

    /**
     * Returns a comprehensive audience analytics payload for the given link,
     * filtered by the supplied `AnalyticsFilters`.
     *
     * Returns empty arrays for every breakdown when no clicks match the filters
     * (link must exist or ModelNotFoundException is thrown via Link::findOrFail).
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Date-range and bot-exclusion constraints. Null = no filter.
     * @return array<string, mixed> Keyed by breakdown name.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If link does not exist.
     */
    public function getLinkAudienceAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        Link::findOrFail($linkId);
        $filters ??= new AnalyticsFilters;

        if (! $this->baseQuery($linkId, $filters)->exists()) {
            return [
                'device_breakdown' => [],
                'browser_breakdown' => [],
                'os_breakdown' => [],
                'browsers' => [],
                'operating_systems' => [],
                'device_performance' => [],
                'languages' => [],
                'language_breakdown' => [],
                'platform_breakdown' => [],
                'data_saver' => ['clicks' => 0, 'total' => 0, 'percentage' => 0.0],
                'connection_type_breakdown' => [],
                'rendering_engine' => [],
                'navigation_context_breakdown' => [],
                'social_platform_breakdown' => [],
                'return_visitor_stats' => ['return_rate' => 0.0, 'new_rate' => 0.0, 'avg_session_clicks' => 0.0],
                'quality_breakdown' => ['tiers' => [], 'bot_clicks' => 0, 'bot_percentage' => 0.0, 'avg_fingerprint_score' => 0.0],
            ];
        }

        return [
            'device_breakdown' => $this->getDeviceBreakdown($linkId, $filters),
            'browser_breakdown' => $this->getBrowserBreakdown($linkId, $filters),
            'os_breakdown' => $this->getOSBreakdown($linkId, $filters),
            'browsers' => $this->getBrowserDistribution($linkId, $filters),
            'operating_systems' => $this->getOSDistribution($linkId, $filters),
            'device_performance' => $this->getDevicePerformance($linkId, $filters),
            'languages' => $this->getLanguageDistribution($linkId, $filters),
            'language_breakdown' => $this->getLanguageBreakdown($linkId, $filters),
            'platform_breakdown' => $this->getPlatformBreakdown($linkId, $filters),
            'data_saver' => $this->getDataSaverStats($linkId, $filters),
            'connection_type_breakdown' => $this->getConnectionTypeBreakdown($linkId, $filters),
            'rendering_engine' => $this->getRenderingEngineBreakdown($linkId, $filters),
            'navigation_context_breakdown' => $this->getNavigationContextBreakdown($linkId, $filters),
            'social_platform_breakdown' => $this->getSocialPlatformBreakdown($linkId, $filters),
            'return_visitor_stats' => $this->getReturnVisitorStats($linkId, $filters),
            'quality_breakdown' => $this->getQualityBreakdown($linkId, $filters),
        ];
    }

    /**
     * Returns a filtered Eloquent builder scoped to the given link's clicks.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     */
    private function baseQuery(int $linkId, AnalyticsFilters $filters): \Illuminate\Database\Eloquent\Builder
    {
        return $filters->applyToQuery(Click::where('link_id', $linkId));
    }

    /**
     * Returns a filtered raw query builder scoped to the given link's clicks.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Date-range and bot-exclusion constraints.
     */
    private function rawQuery(int $linkId, AnalyticsFilters $filters): \Illuminate\Database\Query\Builder
    {
        return $filters->applyToQuery(DB::table('clicks')->where('link_id', $linkId));
    }

    /**
     * @return array<int, array{device: string, clicks: int, percentage: float}>
     */
    private function getDeviceBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $this->rawQuery($linkId, $filters)
            ->selectRaw('device, COUNT(*) as clicks')
            ->whereNotNull('device')
            ->groupBy('device')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'device' => $r->device,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array{browser: string, clicks: int}>
     */
    private function getBrowserBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->rawQuery($linkId, $filters)
            ->selectRaw("COALESCE(browser, 'Unknown') as browser, COUNT(*) as clicks")
            ->groupBy('browser')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'browser' => $r->browser,
                'clicks' => (int) $r->clicks,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array{os: string, clicks: int}>
     */
    private function getOSBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->rawQuery($linkId, $filters)
            ->selectRaw("COALESCE(os, 'Unknown') as os, COUNT(*) as clicks")
            ->groupBy('os')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'os' => $r->os,
                'clicks' => (int) $r->clicks,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array{browser: string, version: ?string, clicks: int, percentage: float}>
     */
    private function getBrowserDistribution(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->rawQuery($linkId, $filters)
            ->selectRaw('browser, browser_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->whereNotNull('browser')
            ->groupBy('browser', 'browser_version')
            ->orderBy('clicks', 'desc')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'browser' => $r->browser,
                'version' => $r->browser_version,
                'clicks' => (int) $r->clicks,
                'percentage' => (float) $r->percentage,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array{os: string, version: ?string, clicks: int, percentage: float}>
     */
    private function getOSDistribution(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->rawQuery($linkId, $filters)
            ->selectRaw('os, os_version, COUNT(*) as clicks, ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 2) as percentage')
            ->whereNotNull('os')
            ->groupBy('os', 'os_version')
            ->orderBy('clicks', 'desc')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'os' => $r->os,
                'version' => $r->os_version,
                'clicks' => (int) $r->clicks,
                'percentage' => (float) $r->percentage,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array{device: string, avg_response_time: float, min_response_time: float, max_response_time: float, total_clicks: int}>
     */
    private function getDevicePerformance(int $linkId, AnalyticsFilters $filters): array
    {
        return $this->rawQuery($linkId, $filters)
            ->selectRaw('device, AVG(response_time) as avg_response_time, MIN(response_time) as min_response_time, MAX(response_time) as max_response_time, COUNT(*) as total_clicks')
            ->whereNotNull('device')
            ->whereNotNull('response_time')
            ->groupBy('device')
            ->orderBy('avg_response_time', 'asc')
            ->get()
            ->map(fn ($r) => [
                'device' => $r->device,
                'avg_response_time' => round((float) $r->avg_response_time, 2),
                'min_response_time' => round((float) $r->min_response_time, 2),
                'max_response_time' => round((float) $r->max_response_time, 2),
                'total_clicks' => (int) $r->total_clicks,
            ])
            ->toArray();
    }

    /**
     * @return array<int, array{language: string, clicks: int, percentage: float}>
     */
    private function getLanguageDistribution(int $linkId, AnalyticsFilters $filters): array
    {
        $clicks = $this->rawQuery($linkId, $filters)
            ->select('accept_language')
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
                fn ($lang, $n) => [
                    'language' => $lang,
                    'clicks' => $n,
                    'percentage' => $total > 0 ? round($n / $total * 100, 2) : 0,
                ],
                array_keys($counts),
                $counts
            ),
            0,
            10
        );
    }

    /**
     * Returns a breakdown of clicks grouped by parsed primary language and region.
     *
     * Uses the pre-parsed `primary_language` and `language_region` columns (Phase 1),
     * so results only include clicks recorded after the Phase 1 migration.
     *
     * @return array<int, array{language: string, region: ?string, clicks: int, percentage: float}>
     */
    private function getLanguageBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $this->rawQuery($linkId, $filters)
            ->selectRaw("COALESCE(primary_language, 'unknown') as language, language_region, COUNT(*) as clicks")
            ->whereNotNull('primary_language')
            ->groupBy('primary_language', 'language_region')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'language' => $r->language,
                'region' => $r->language_region,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Returns a breakdown of clicks by Client Hints platform (ch_platform column).
     *
     * The ch_platform column is populated from the Sec-CH-UA-Platform header (Phase 1).
     * Results only include clicks from Chromium-based browsers that send this header.
     *
     * @return array<int, array{platform: string, clicks: int, percentage: float}>
     */
    private function getPlatformBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $this->rawQuery($linkId, $filters)
            ->selectRaw('ch_platform as platform, COUNT(*) as clicks')
            ->whereNotNull('ch_platform')
            ->groupBy('ch_platform')
            ->orderBy('clicks', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'platform' => $r->platform,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Returns click distribution grouped by ISP connection type.
     *
     * Populated from Phase 2 ISP keyword classification. Clicks before Phase 2
     * have null connection_type and are coalesced to 'unknown'.
     *
     * @return array<int, array{type: string, clicks: int, percentage: float}>
     */
    private function getConnectionTypeBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $this->rawQuery($linkId, $filters)
            ->selectRaw("COALESCE(connection_type, 'unknown') as type, COUNT(*) as clicks")
            ->groupBy('connection_type')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'type' => $r->type,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Returns click distribution grouped by browser rendering engine.
     *
     * Derived from browser name in Phase 2. Clicks before Phase 2 have null
     * rendering_engine and are coalesced to 'unknown'.
     *
     * @return array<int, array{engine: string, clicks: int, percentage: float}>
     */
    private function getRenderingEngineBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $this->rawQuery($linkId, $filters)
            ->selectRaw("COALESCE(rendering_engine, 'unknown') as engine, COUNT(*) as clicks")
            ->groupBy('rendering_engine')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'engine' => $r->engine,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Returns click distribution grouped by navigation context.
     *
     * navigation_context is derived from Sec-Fetch-Site + Sec-Fetch-Mode headers (Phase 1).
     * NULL entries (clicks before Phase 1) are grouped as 'unknown' only if they
     * represent more than 1 % of total clicks.
     *
     * @return array<int, array{context: string, clicks: int, percentage: float}>
     */
    private function getNavigationContextBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        $rows = $this->rawQuery($linkId, $filters)
            ->selectRaw("COALESCE(navigation_context, 'unknown') as context, COUNT(*) as clicks")
            ->groupBy('navigation_context')
            ->orderBy('clicks', 'desc')
            ->get();

        return $rows
            ->filter(function ($r) use ($total) {
                if ($r->context !== 'unknown') {
                    return true;
                }

                return $total > 0 && ($r->clicks / $total) > 0.01;
            })
            ->map(fn ($r) => [
                'context' => $r->context,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0.0,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Returns click distribution grouped by social platform (referer-detected).
     *
     * Only includes clicks where social_platform IS NOT NULL (i.e., clicks after
     * the 2026-05-19 migration with an identifiable social referer). Returns empty
     * array when no such clicks exist.
     *
     * @return array<int, array{platform: string, clicks: int, percentage: float}>
     */
    private function getSocialPlatformBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $this->rawQuery($linkId, $filters)
            ->selectRaw('social_platform as platform, COUNT(*) as clicks')
            ->whereNotNull('social_platform')
            ->groupBy('social_platform')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'platform' => $r->platform,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0.0,
            ])
            ->toArray();
    }

    /**
     * Returns the return visitor rate and average session depth for a link.
     *
     * is_return_visitor is set when the same IP+UA fingerprint was seen before (Phase 2).
     * session_clicks counts how many clicks occurred in the visitor's session.
     *
     * @return array{return_rate: float, new_rate: float, avg_session_clicks: float}
     */
    private function getReturnVisitorStats(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        if ($total === 0) {
            return ['return_rate' => 0.0, 'new_rate' => 0.0, 'avg_session_clicks' => 0.0];
        }

        $returnCount = $this->baseQuery($linkId, $filters)->where('is_return_visitor', true)->count();
        $avgSession = $this->rawQuery($linkId, $filters)
            ->whereNotNull('session_clicks')
            ->avg('session_clicks');

        return [
            'return_rate' => round($returnCount / $total * 100, 2),
            'new_rate' => round(($total - $returnCount) / $total * 100, 2),
            'avg_session_clicks' => round((float) ($avgSession ?? 0), 2),
        ];
    }

    /**
     * Returns quality tier distribution, bot rate and average fingerprint score.
     *
     * Clicks with null quality_tier (before Phase 3) are excluded from the tiers array
     * but still counted in the bot_percentage denominator.
     *
     * @return array{tiers: array, bot_clicks: int, bot_percentage: float, avg_fingerprint_score: float}
     */
    private function getQualityBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        $tiers = $this->rawQuery($linkId, $filters)
            ->selectRaw('quality_tier, COUNT(*) as clicks')
            ->whereNotNull('quality_tier')
            ->groupBy('quality_tier')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'tier' => $r->quality_tier,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0.0,
            ])
            ->toArray();

        $botClicks = $this->baseQuery($linkId, $filters)->where('is_bot', true)->count();
        $avgFingerprint = $this->rawQuery($linkId, $filters)->avg('fingerprint_score');

        return [
            'tiers' => $tiers,
            'bot_clicks' => $botClicks,
            'bot_percentage' => $total > 0 ? round($botClicks / $total * 100, 2) : 0.0,
            'avg_fingerprint_score' => round((float) ($avgFingerprint ?? 0), 2),
        ];
    }

    /**
     * Returns the count and percentage of clicks where the visitor had data-saver mode active.
     *
     * The is_data_saver flag is derived from the Save-Data: on HTTP header (Phase 1).
     *
     * @return array{clicks: int, total: int, percentage: float}
     */
    private function getDataSaverStats(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();
        $dataSaver = $this->baseQuery($linkId, $filters)->where('is_data_saver', true)->count();

        return [
            'clicks' => $dataSaver,
            'total' => $total,
            'percentage' => $total > 0 ? round($dataSaver / $total * 100, 2) : 0,
        ];
    }
}
