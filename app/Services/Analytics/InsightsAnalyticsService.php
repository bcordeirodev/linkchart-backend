<?php

namespace App\Services\Analytics;

use App\DTOs\Analytics\AnalyticsFilters;
use App\Models\Click;
use App\Models\Link;
use App\Services\Analytics\Insights\Generators\DeviceInsightGenerator;
use App\Services\Analytics\Insights\Generators\DiversityInsightGenerator;
use App\Services\Analytics\Insights\Generators\EngagementInsightGenerator;
use App\Services\Analytics\Insights\Generators\GeographicInsightGenerator;
use App\Services\Analytics\Insights\Generators\PerformanceInsightGenerator;
use App\Services\Analytics\Insights\Generators\RetentionInsightGenerator;
use App\Services\Analytics\Insights\Generators\SecurityInsightGenerator;
use App\Services\Analytics\Insights\Generators\TemporalInsightGenerator;
use App\Services\Analytics\Insights\InsightGeneratorRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Produces AI-flavoured insights and supporting analytics data for a link.
 *
 * @see \App\Contracts\Analytics\InsightsAnalyticsInterface
 *
 * Owns the InsightGeneratorRegistry and instantiates all 8 generators inline
 * (GeographicInsightGenerator, DeviceInsightGenerator, TemporalInsightGenerator,
 * PerformanceInsightGenerator, DiversityInsightGenerator, SecurityInsightGenerator,
 * EngagementInsightGenerator, RetentionInsightGenerator).
 *
 * Note (deferred R-15 from audit): generators are currently newed-up in __construct
 * rather than injected, making this service harder to test in isolation. Future
 * hardening should inject the registry via the service container.
 *
 * Side effects: read-only queries. No cache, no queue, no log calls.
 */
class InsightsAnalyticsService implements \App\Contracts\Analytics\InsightsAnalyticsInterface
{
    private InsightGeneratorRegistry $registry;

    public function __construct()
    {
        $this->registry = new InsightGeneratorRegistry;
        foreach ([
            new GeographicInsightGenerator,
            new DeviceInsightGenerator,
            new TemporalInsightGenerator,
            new PerformanceInsightGenerator,
            new DiversityInsightGenerator,
            new SecurityInsightGenerator,
            new EngagementInsightGenerator,
            new RetentionInsightGenerator,
        ] as $gen) {
            $this->registry->register($gen);
        }
    }

    /**
     * Returns insights and supporting analytics for the given link.
     *
     * When no clicks exist returns an empty insights array with zero-value summary.
     * Otherwise delegates to InsightGeneratorRegistry::generate(), collects all
     * non-null insight payloads, and computes a summary over them.
     *
     * `generated_at` reflects the MAX(created_at) of the clicks in scope,
     * not the current wall-clock time, so callers know how fresh the data is.
     * Returns null when there are no clicks at all.
     *
     * @param  int  $linkId  Link primary key.
     * @param  ?AnalyticsFilters  $filters  Filter state (date range, bot exclusion). Null = no filter applied.
     * @return array{insights: array, summary: array{total_insights: int, high_priority: int, actionable_insights: int, avg_confidence: float}, analytics_data: array, generated_at: string|null}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If link does not exist.
     */
    public function getLinkInsightsAnalytics(int $linkId, ?AnalyticsFilters $filters = null): array
    {
        $filters ??= new AnalyticsFilters;
        Link::findOrFail($linkId);
        $totalClicks = $this->baseQuery($linkId, $filters)->count();

        $lastClickAt = $filters->applyToQuery(
            DB::table('clicks')->where('link_id', $linkId)
        )->max('created_at');

        $generatedAt = $lastClickAt ? Carbon::parse($lastClickAt)->toISOString() : null;

        $analyticsData = [
            'retention' => $this->getReturnVisitorRate($linkId, $filters),
            'session_depth' => $this->getSessionDepthAnalysis($linkId, $filters),
            'traffic_sources' => $this->getTrafficSourceAnalysis($linkId, $filters),
            'navigation_context' => $this->getNavigationContextBreakdown($linkId, $filters),
            'http_protocol' => $this->getHttpProtocolBreakdown($linkId, $filters),
            'quality' => $this->getQualityBreakdown($linkId, $filters),
        ];

        if ($totalClicks === 0) {
            $analyticsData['quality'] = ['total_clicks' => 0, 'avg_quality_score' => null, 'tier_breakdown' => [], 'organic_percentage' => 0];

            return [
                'insights' => [],
                'summary' => [
                    'total_insights' => 0,
                    'high_priority' => 0,
                    'actionable_insights' => 0,
                    'avg_confidence' => 0,
                ],
                'analytics_data' => $analyticsData,
                'generated_at' => $generatedAt,
            ];
        }

        $insights = $this->registry->generate($linkId, $totalClicks, $filters);

        return [
            'insights' => $insights,
            'summary' => [
                'total_insights' => count($insights),
                'high_priority' => count(array_filter($insights, fn ($i) => $i['priority'] === 'high')),
                'actionable_insights' => count(array_filter($insights, fn ($i) => $i['actionable'])),
                'avg_confidence' => count($insights) > 0
                    ? round(array_sum(array_column($insights, 'confidence')) / count($insights), 2)
                    : 0,
            ],
            'analytics_data' => $analyticsData,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * Compute return-visitor retention metrics for a link.
     *
     * Removed fields (were fabricated):
     *   - `retention_score`: was `min(100, return_rate * 1.5)` — arbitrary scaling.
     *   - `benchmark_comparison`: used hardcoded thresholds unrelated to real benchmarks.
     *
     * Rates are returned as decimals in [0.0, 1.0] so callers control formatting.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter state.
     * @return array{return_visitor_rate: float, new_visitor_rate: float, total_visitors: int, return_visitors: int, new_visitors: int}
     */
    private function getReturnVisitorRate(int $linkId, AnalyticsFilters $filters): array
    {
        $totalVisitors = $this->baseQuery($linkId, $filters)->distinct('ip')->count('ip');

        if ($totalVisitors === 0) {
            return [
                'return_visitor_rate' => 0.0,
                'new_visitor_rate' => 0.0,
                'total_visitors' => 0,
                'return_visitors' => 0,
                'new_visitors' => 0,
            ];
        }

        $returnVisitors = $this->baseQuery($linkId, $filters)
            ->where('is_return_visitor', true)
            ->distinct('ip')
            ->count('ip');

        $newVisitors = max(0, $totalVisitors - $returnVisitors);
        $returnRate = $returnVisitors / $totalVisitors;

        return [
            'return_visitor_rate' => round($returnRate, 4),
            'new_visitor_rate' => round(1 - $returnRate, 4),
            'total_visitors' => $totalVisitors,
            'return_visitors' => $returnVisitors,
            'new_visitors' => $newVisitors,
        ];
    }

    /**
     * Analyse session depth (how many clicks per session) for a link.
     *
     * Removed fields (were fabricated):
     *   - `engagement_score`: was `min(100, avg_session_depth * 20)` — arbitrary scaling.
     *   - `session_quality`: label derived from hardcoded thresholds (4+ = excellent, etc.).
     *
     * `power_users_count` counts sessions with 3 or more clicks (high-engagement threshold).
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter state.
     * @return array{avg_session_clicks: float, max_session_depth: int, session_distribution: array, power_users_count: int}
     */
    private function getSessionDepthAnalysis(int $linkId, AnalyticsFilters $filters): array
    {
        $sessionData = $filters->applyToQuery(
            DB::table('clicks')
                ->selectRaw('
                    session_clicks,
                    COUNT(*) as users,
                    COUNT(DISTINCT ip) as unique_ips,
                    AVG(CAST(response_time as DECIMAL)) as avg_response_time
                ')
                ->where('link_id', $linkId)
                ->whereNotNull('session_clicks')
                ->where('session_clicks', '>', 0)
        )
            ->groupBy('session_clicks')
            ->orderBy('session_clicks', 'asc')
            ->get();

        if ($sessionData->isEmpty()) {
            return [
                'avg_session_clicks' => 0,
                'max_session_depth' => 0,
                'session_distribution' => [],
                'power_users_count' => 0,
            ];
        }

        $totalUsers = $sessionData->sum('users');

        if ($totalUsers === 0) {
            return [
                'avg_session_clicks' => 0,
                'max_session_depth' => 0,
                'session_distribution' => [],
                'power_users_count' => 0,
            ];
        }

        $weightedSum = $sessionData->sum(function ($item) {
            return $item->session_clicks * $item->users;
        });

        $avgDepth = round($weightedSum / $totalUsers, 2);
        $maxDepth = $sessionData->max('session_clicks');

        // Power users = sessions with 3+ clicks (meaningful engagement threshold)
        $powerUsers = $sessionData->where('session_clicks', '>=', 3)->sum('users');

        $distribution = $sessionData->map(function ($item) use ($totalUsers) {
            return [
                'clicks_count' => $item->session_clicks,
                'frequency' => $item->users,
                'percentage' => round(($item->users / $totalUsers) * 100, 1),
                'avg_response_time' => round($item->avg_response_time ?? 0, 3),
            ];
        })->toArray();

        return [
            'avg_session_clicks' => $avgDepth,
            'max_session_depth' => $maxDepth,
            'session_distribution' => $distribution,
            'power_users_count' => $powerUsers,
        ];
    }

    /**
     * Analyse traffic sources and channel distribution for a link.
     *
     * Recommendations use `message_key` (i18n key string) instead of hardcoded text so
     * the frontend can translate them via `t(recommendation.message_key)`.
     *
     * @param  int  $linkId  Link primary key.
     * @param  AnalyticsFilters  $filters  Active filter state.
     * @return array{sources: array, channels: array, top_source: array|null, source_diversity: int, total_clicks: int, recommendations: array<int, array{type: string, message_key: string, priority: string}>}
     */
    private function getTrafficSourceAnalysis(int $linkId, AnalyticsFilters $filters): array
    {
        $sourceData = $filters->applyToQuery(
            DB::table('clicks')
                ->selectRaw('
                    COALESCE(click_source, \'direct\') as source,
                    COUNT(*) as clicks,
                    COUNT(DISTINCT ip) as unique_visitors,
                    AVG(CAST(response_time as DECIMAL)) as avg_response_time,
                    AVG(session_clicks) as avg_session_depth
                ')
                ->where('link_id', $linkId)
        )
            ->groupByRaw("COALESCE(click_source, 'direct')")
            ->orderBy('clicks', 'desc')
            ->get();

        if ($sourceData->isEmpty()) {
            return [
                'sources' => [],
                'top_source' => null,
                'source_diversity' => 0,
                'channels' => [],
                'recommendations' => [],
            ];
        }

        $totalClicks = $sourceData->sum('clicks');

        $channelData = [];

        foreach ($sourceData as $source) {
            $channel = match ($source->source) {
                'social', 'search', 'direct', 'email', 'referral' => $source->source,
                default => 'other',
            };

            if (! isset($channelData[$channel])) {
                $channelData[$channel] = [
                    'channel' => $channel,
                    'clicks' => 0,
                    'unique_visitors' => 0,
                    'sources' => [],
                    'avg_response_time' => 0,
                    'avg_session_depth' => 0,
                ];
            }

            $channelData[$channel]['clicks'] += $source->clicks;
            $channelData[$channel]['unique_visitors'] += $source->unique_visitors;
            $channelData[$channel]['sources'][] = [
                'source' => $source->source,
                'clicks' => $source->clicks,
                'percentage' => round(($source->clicks / $totalClicks) * 100, 1),
                'avg_response_time' => round($source->avg_response_time ?? 0, 3),
                'avg_session_depth' => round($source->avg_session_depth ?? 1, 2),
            ];
        }

        foreach ($channelData as &$channel) {
            $channel['percentage'] = round(($channel['clicks'] / $totalClicks) * 100, 1);
            $channel['avg_response_time'] = round(
                array_sum(array_column($channel['sources'], 'avg_response_time')) / count($channel['sources']),
                3
            );
            $channel['avg_session_depth'] = round(
                array_sum(array_column($channel['sources'], 'avg_session_depth')) / count($channel['sources']),
                2
            );
        }
        unset($channel);

        uasort($channelData, function ($a, $b) {
            return $b['clicks'] <=> $a['clicks'];
        });

        $topSource = $sourceData->first();
        $sourceDiversity = count($sourceData);

        $recommendations = [];

        if (isset($channelData['social']) && $channelData['social']['percentage'] > 50) {
            $recommendations[] = [
                'type' => 'optimization',
                'message_key' => 'insights.recommendations.highSocialTraffic',
                'priority' => 'medium',
            ];
        }

        if (isset($channelData['direct']) && $channelData['direct']['percentage'] > 70) {
            $recommendations[] = [
                'type' => 'growth',
                'message_key' => 'insights.recommendations.highDirectTraffic',
                'priority' => 'high',
            ];
        }

        if ($sourceDiversity < 3) {
            $recommendations[] = [
                'type' => 'diversification',
                'message_key' => 'insights.recommendations.lowSourceDiversity',
                'priority' => 'high',
            ];
        }

        return [
            'sources' => array_values($sourceData->toArray()),
            'channels' => array_values($channelData),
            'top_source' => $topSource ? [
                'source' => $topSource->source,
                'clicks' => $topSource->clicks,
                'percentage' => round(($topSource->clicks / $totalClicks) * 100, 1),
            ] : null,
            'source_diversity' => $sourceDiversity,
            'total_clicks' => $totalClicks,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Returns a breakdown of clicks by navigation context (derived from Sec-Fetch-* headers).
     *
     * Navigation context is a more reliable attribution signal than click_source/referer,
     * since browsers do not suppress Sec-Fetch headers via Referrer-Policy.
     * Only includes clicks recorded after the Phase 1 migration.
     *
     * @param  AnalyticsFilters  $filters  Active filter state.
     * @return array<int, array{context: string, clicks: int, percentage: float}>
     */
    private function getNavigationContextBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $filters->applyToQuery(
            DB::table('clicks')
                ->selectRaw('navigation_context as context, COUNT(*) as clicks')
                ->where('link_id', $linkId)
                ->whereNotNull('navigation_context')
        )
            ->groupBy('navigation_context')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'context' => $r->context,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Returns a breakdown of clicks by HTTP protocol version (HTTP/1.1 vs HTTP/2).
     *
     * HTTP protocol is captured from the SERVER_PROTOCOL server variable (Phase 1).
     * High HTTP/2 percentage indicates modern browser traffic.
     *
     * @param  AnalyticsFilters  $filters  Active filter state.
     * @return array<int, array{protocol: string, clicks: int, percentage: float}>
     */
    private function getHttpProtocolBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        return $filters->applyToQuery(
            DB::table('clicks')
                ->selectRaw("COALESCE(http_protocol, 'unknown') as protocol, COUNT(*) as clicks")
                ->where('link_id', $linkId)
        )
            ->groupBy('http_protocol')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'protocol' => $r->protocol,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }

    /**
     * Returns a quality tier breakdown with average quality score.
     *
     * Groups clicks by quality_tier and computes per-tier click count, percentage,
     * and average quality_score. Only includes clicks scored after the Phase 3
     * migration (null values are coalesced to 'unknown').
     *
     * @param  AnalyticsFilters  $filters  Active filter state.
     * @return array{total_clicks: int, avg_quality_score: float|null, tier_breakdown: array, organic_percentage: float}
     */
    private function getQualityBreakdown(int $linkId, AnalyticsFilters $filters): array
    {
        $total = $this->baseQuery($linkId, $filters)->count();

        $tiers = $filters->applyToQuery(
            DB::table('clicks')
                ->selectRaw("COALESCE(quality_tier, 'unknown') as tier, COUNT(*) as clicks, ROUND(AVG(quality_score), 1) as avg_score")
                ->where('link_id', $linkId)
        )
            ->groupBy('quality_tier')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'tier' => $r->tier,
                'clicks' => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
                'avg_score' => (float) ($r->avg_score ?? 0),
            ])
            ->toArray();

        $avgScore = $filters->applyToQuery(
            DB::table('clicks')
                ->where('link_id', $linkId)
                ->whereNotNull('quality_score')
        )
            ->avg('quality_score');

        return [
            'total_clicks' => $total,
            'avg_quality_score' => $avgScore ? round((float) $avgScore, 1) : null,
            'tier_breakdown' => $tiers,
            'organic_percentage' => collect($tiers)->firstWhere('tier', 'organic')['percentage'] ?? 0,
        ];
    }

    /**
     * Build a base Eloquent query for clicks belonging to the given link,
     * with date-range and bot-exclusion filters applied.
     */
    private function baseQuery(int $linkId, AnalyticsFilters $filters): \Illuminate\Database\Eloquent\Builder
    {
        return $filters->applyToQuery(Click::where('link_id', $linkId));
    }
}
