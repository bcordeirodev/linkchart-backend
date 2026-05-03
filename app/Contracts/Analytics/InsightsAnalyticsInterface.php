<?php
namespace App\Contracts\Analytics;
interface InsightsAnalyticsInterface
{
    public function getLinkInsightsAnalytics(int $linkId): array;
}
