<?php

namespace App\Contracts\Analytics;

interface GeographicAnalyticsInterface
{
    public function getLinkGeographicAnalytics(int $linkId): array;
}
