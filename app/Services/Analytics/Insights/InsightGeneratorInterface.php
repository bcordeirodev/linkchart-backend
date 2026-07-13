<?php

namespace App\Services\Analytics\Insights;

use App\DTOs\Analytics\AnalyticsFilters;

/**
 * Contract for a single insight generator in the Strategy pattern.
 *
 * Each implementation encapsulates one domain-specific analytical check
 * (device dominance, geographic diversity, traffic trend, etc.) and
 * returns either an insight payload or null when its triggering condition
 * is not satisfied.
 */
interface InsightGeneratorInterface
{
    /**
     * Generates an insight for the given link, or returns null if the
     * generator's condition is not met (e.g. insufficient data, no anomaly).
     *
     * Return shape (all implementations must include these keys):
     *   - type         (string) — insight category, e.g. 'audience', 'geographic'
     *   - title        (string) — short display title
     *   - description  (string) — human-readable finding
     *   - priority     (string) — 'high' | 'medium' | 'low'
     *   - actionable   (bool)   — whether a recommendation is actionable
     *   - confidence   (float)  — 0.0–1.0 confidence score
     *   - impact_score (int)    — 1–10 impact rating
     *   - recommendation (string) — suggested action
     *
     * Some generators include additional keys (e.g. data_points in RetentionInsightGenerator).
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Pre-computed total click count for the link, already filtered.
     * @param  AnalyticsFilters  $filters  Active filter state. MUST be applied to every
     *                                     query the generator builds: $totalClicks is the
     *                                     filtered denominator, so an unfiltered numerator
     *                                     yields percentages above 100%.
     * @return array<string, mixed>|null Insight payload, or null if condition not met.
     */
    public function generate(int $linkId, int $totalClicks, AnalyticsFilters $filters): ?array;
}
