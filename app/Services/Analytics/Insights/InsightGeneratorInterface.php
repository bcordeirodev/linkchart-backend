<?php

namespace App\Services\Analytics\Insights;

interface InsightGeneratorInterface
{
    /** Returns insight array or null if condition not met */
    public function generate(int $linkId, int $totalClicks): ?array;
}
