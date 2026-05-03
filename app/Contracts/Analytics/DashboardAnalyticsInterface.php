<?php
namespace App\Contracts\Analytics;
interface DashboardAnalyticsInterface
{
    public function getLinkDashboardAnalytics(int $linkId, int $hours = 0): array;
}
