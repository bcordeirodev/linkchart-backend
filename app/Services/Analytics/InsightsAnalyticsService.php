<?php

namespace App\Services\Analytics;

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
use Illuminate\Support\Facades\DB;

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

    public function getLinkInsightsAnalytics(int $linkId): array
    {
        Link::findOrFail($linkId);
        $totalClicks = Click::where('link_id', $linkId)->count();

        $analyticsData = [
            'retention'          => $this->getReturnVisitorRate($linkId),
            'session_depth'      => $this->getSessionDepthAnalysis($linkId),
            'traffic_sources'    => $this->getTrafficSourceAnalysis($linkId),
            'navigation_context' => $this->getNavigationContextBreakdown($linkId),
            'http_protocol'      => $this->getHttpProtocolBreakdown($linkId),
        ];

        if ($totalClicks === 0) {
            return [
                'insights' => [],
                'summary' => [
                    'total_insights' => 0,
                    'high_priority' => 0,
                    'actionable_insights' => 0,
                    'avg_confidence' => 0,
                ],
                'analytics_data' => $analyticsData,
                'generated_at' => now()->toISOString(),
            ];
        }

        $insights = $this->registry->generate($linkId, $totalClicks);

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
            'generated_at' => now()->toISOString(),
        ];
    }

    private function getReturnVisitorRate(int $linkId): array
    {
        $totalVisitors = Click::where('link_id', $linkId)->distinct('ip')->count('ip');

        if ($totalVisitors === 0) {
            return [
                'return_visitor_rate' => 0,
                'new_visitor_rate' => 0,
                'total_visitors' => 0,
                'return_visitors' => 0,
                'new_visitors' => 0,
                'retention_score' => 0,
                'benchmark_comparison' => 'insufficient_data',
            ];
        }

        $returnVisitors = Click::where('link_id', $linkId)
            ->where('is_return_visitor', true)
            ->distinct('ip')
            ->count('ip');

        $newVisitors = max(0, $totalVisitors - $returnVisitors);
        $returnVisitorRate = round(($returnVisitors / $totalVisitors) * 100, 2);
        $newVisitorRate = round(($newVisitors / $totalVisitors) * 100, 2);

        $retentionScore = min(100, round($returnVisitorRate * 1.5, 1));

        $benchmarkComparison = 'average';
        if ($returnVisitorRate >= 40) {
            $benchmarkComparison = 'excellent';
        } elseif ($returnVisitorRate >= 25) {
            $benchmarkComparison = 'good';
        } elseif ($returnVisitorRate >= 15) {
            $benchmarkComparison = 'average';
        } else {
            $benchmarkComparison = 'needs_improvement';
        }

        return [
            'return_visitor_rate' => $returnVisitorRate,
            'new_visitor_rate' => $newVisitorRate,
            'total_visitors' => $totalVisitors,
            'return_visitors' => $returnVisitors,
            'new_visitors' => $newVisitors,
            'retention_score' => $retentionScore,
            'benchmark_comparison' => $benchmarkComparison,
        ];
    }

    private function getSessionDepthAnalysis(int $linkId): array
    {
        $sessionData = DB::table('clicks')
            ->selectRaw('
                session_clicks,
                COUNT(*) as users,
                COUNT(DISTINCT ip) as unique_ips,
                AVG(CAST(response_time as DECIMAL)) as avg_response_time
            ')
            ->where('link_id', $linkId)
            ->whereNotNull('session_clicks')
            ->where('session_clicks', '>', 0)
            ->groupBy('session_clicks')
            ->orderBy('session_clicks', 'asc')
            ->get();

        if ($sessionData->isEmpty()) {
            return [
                'avg_session_depth' => 0,
                'max_session_depth' => 0,
                'session_distribution' => [],
                'power_users_count' => 0,
                'engagement_score' => 0,
                'session_quality' => 'no_data',
            ];
        }

        $totalUsers = $sessionData->sum('users');

        if ($totalUsers === 0) {
            return [
                'avg_session_depth' => 0,
                'max_session_depth' => 0,
                'session_distribution' => [],
                'power_users_count' => 0,
                'engagement_score' => 0,
                'session_quality' => 'no_data',
            ];
        }

        $weightedSum = $sessionData->sum(function ($item) {
            return $item->session_clicks * $item->users;
        });

        $avgSessionDepth = round($weightedSum / $totalUsers, 2);
        $maxSessionDepth = $sessionData->max('session_clicks');

        $powerUsersCount = $sessionData->where('session_clicks', '>=', 5)->sum('users');

        $engagementScore = min(100, round($avgSessionDepth * 20, 1));

        $sessionQuality = 'low';
        if ($avgSessionDepth >= 4) {
            $sessionQuality = 'excellent';
        } elseif ($avgSessionDepth >= 2.5) {
            $sessionQuality = 'good';
        } elseif ($avgSessionDepth >= 1.5) {
            $sessionQuality = 'average';
        }

        $distribution = $sessionData->map(function ($item) use ($totalUsers) {
            return [
                'session_clicks' => $item->session_clicks,
                'users' => $item->users,
                'percentage' => round(($item->users / $totalUsers) * 100, 1),
                'avg_response_time' => round($item->avg_response_time ?? 0, 3),
            ];
        })->toArray();

        return [
            'avg_session_depth' => $avgSessionDepth,
            'max_session_depth' => $maxSessionDepth,
            'session_distribution' => $distribution,
            'power_users_count' => $powerUsersCount,
            'power_users_percentage' => round(($powerUsersCount / $totalUsers) * 100, 1),
            'engagement_score' => $engagementScore,
            'session_quality' => $sessionQuality,
            'total_sessions' => $totalUsers,
        ];
    }

    private function getTrafficSourceAnalysis(int $linkId): array
    {
        $sourceData = DB::table('clicks')
            ->selectRaw('
                COALESCE(click_source, \'direct\') as source,
                COUNT(*) as clicks,
                COUNT(DISTINCT ip) as unique_visitors,
                AVG(CAST(response_time as DECIMAL)) as avg_response_time,
                AVG(session_clicks) as avg_session_depth
            ')
            ->where('link_id', $linkId)
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
                'message' => 'Alto tráfego social. Considere diversificar com SEO e email marketing.',
                'priority' => 'medium',
            ];
        }

        if (isset($channelData['direct']) && $channelData['direct']['percentage'] > 70) {
            $recommendations[] = [
                'type' => 'growth',
                'message' => 'Tráfego muito direto. Explore campanhas em redes sociais para ampliar alcance.',
                'priority' => 'high',
            ];
        }

        if ($sourceDiversity < 3) {
            $recommendations[] = [
                'type' => 'diversification',
                'message' => 'Baixa diversidade de fontes. Considere múltiplos canais para reduzir riscos.',
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
     * @param  int  $linkId
     * @return array<int, array{context: string, clicks: int, percentage: float}>
     */
    private function getNavigationContextBreakdown(int $linkId): array
    {
        $total = Click::where('link_id', $linkId)->count();

        return DB::table('clicks')
            ->selectRaw('navigation_context as context, COUNT(*) as clicks')
            ->where('link_id', $linkId)
            ->whereNotNull('navigation_context')
            ->groupBy('navigation_context')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'context'    => $r->context,
                'clicks'     => (int) $r->clicks,
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
     * @param  int  $linkId
     * @return array<int, array{protocol: string, clicks: int, percentage: float}>
     */
    private function getHttpProtocolBreakdown(int $linkId): array
    {
        $total = Click::where('link_id', $linkId)->count();

        return DB::table('clicks')
            ->selectRaw("COALESCE(http_protocol, 'unknown') as protocol, COUNT(*) as clicks")
            ->where('link_id', $linkId)
            ->groupBy('http_protocol')
            ->orderBy('clicks', 'desc')
            ->get()
            ->map(fn ($r) => [
                'protocol'   => $r->protocol,
                'clicks'     => (int) $r->clicks,
                'percentage' => $total > 0 ? round($r->clicks / $total * 100, 2) : 0,
            ])
            ->toArray();
    }
}
