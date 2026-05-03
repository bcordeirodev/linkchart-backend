<?php

namespace App\Contracts\Analytics;

interface AudienceAnalyticsInterface
{
    public function getLinkAudienceAnalytics(int $linkId): array;
}
