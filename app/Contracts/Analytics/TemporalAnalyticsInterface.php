<?php

namespace App\Contracts\Analytics;

interface TemporalAnalyticsInterface
{
    public function getLinkTemporalAnalytics(int $linkId): array;

    public function getAdvancedTemporalAnalytics(int $linkId): array;
}
