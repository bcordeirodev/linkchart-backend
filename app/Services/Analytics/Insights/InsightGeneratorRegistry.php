<?php

namespace App\Services\Analytics\Insights;

use App\DTOs\Analytics\AnalyticsFilters;

/**
 * Strategy registry for insight generators.
 *
 * Holds all registered InsightGeneratorInterface implementations and
 * iterates over them in registration order when generating insights.
 * A generator is skipped (its result omitted) when it returns null,
 * which happens when its triggering condition is not met.
 *
 * InsightsAnalyticsService instantiates generators inline in __construct
 * and registers them here (deferred R-15 from audit — future hardening
 * should inject the registry and generators via the service container).
 */
class InsightGeneratorRegistry
{
    /** @var InsightGeneratorInterface[] */
    private array $generators = [];

    /**
     * Registers a generator at the end of the execution queue.
     *
     * @param  InsightGeneratorInterface  $generator  The generator to register.
     */
    public function register(InsightGeneratorInterface $generator): void
    {
        $this->generators[] = $generator;
    }

    /**
     * Runs all registered generators and collects non-null insights.
     *
     * Each generator returns an insight array or null. Null results are silently
     * dropped — this is the generators' way of signalling that their condition
     * was not met (e.g. not enough data).
     *
     * @param  int  $linkId  Link primary key.
     * @param  int  $totalClicks  Pre-computed filtered total click count.
     * @param  AnalyticsFilters  $filters  Active filter state, forwarded to every generator.
     * @return array<int, array<string, mixed>> All non-null insight payloads.
     */
    public function generate(int $linkId, int $totalClicks, AnalyticsFilters $filters): array
    {
        $insights = [];
        foreach ($this->generators as $gen) {
            $insight = $gen->generate($linkId, $totalClicks, $filters);
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        return $insights;
    }
}
