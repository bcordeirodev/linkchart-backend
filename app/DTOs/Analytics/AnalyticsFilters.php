<?php

namespace App\DTOs\Analytics;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Value object encapsulating the query filters shared by all analytics services.
 *
 * Carries the date range and bot exclusion plus the three drill-down dimensions
 * (country, device, channel) and the continent scope. Constructed from an HTTP
 * Request via `fromRequest()`. Every analytics query must be passed through
 * `applyToQuery()` — or `applyDimensions()` when the query owns its own time
 * window — so that no panel silently reports numbers from a different scope
 * than its neighbours.
 */
readonly class AnalyticsFilters
{
    public readonly ?Carbon $dateFrom;

    public readonly ?Carbon $dateTo;

    public function __construct(
        public readonly bool $excludeBots = false,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        public readonly ?string $country = null,
        public readonly ?string $device = null,
        public readonly ?string $channel = null,
        public readonly ?string $continent = null,
    ) {
        $this->dateFrom = $dateFrom ? Carbon::parse($dateFrom) : null;
        $this->dateTo = $dateTo ? Carbon::parse($dateTo) : null;
    }

    /**
     * Build an AnalyticsFilters from HTTP query params.
     *
     * Recognised params: `date_from`, `date_to`, `exclude_bots`, `country`,
     * `device`, `channel`, `continent`.
     *
     * @param  Request  $request  Incoming HTTP request.
     * @return static New instance with parsed filter values.
     */
    public static function fromRequest(Request $request): static
    {
        return new static(
            excludeBots: $request->boolean('exclude_bots', false),
            dateFrom: $request->query('date_from'),
            dateTo: $request->query('date_to'),
            country: $request->query('country') ?: null,
            device: $request->query('device') ?: null,
            channel: $request->query('channel') ?: null,
            continent: $request->query('continent') ?: null,
        );
    }

    /**
     * Apply the full filter state — date range, bot exclusion and drill-down
     * dimensions — to a Click query builder.
     *
     * @param  mixed  $query  An active query builder for the clicks table.
     * @param  string  $prefix  Table qualifier for joined queries, e.g. `'clicks.'`.
     * @return mixed The same builder with constraints appended (fluent).
     */
    public function applyToQuery(mixed $query, string $prefix = ''): mixed
    {
        $query = $query
            ->when($this->dateFrom, fn ($q) => $q->where($prefix.'created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where($prefix.'created_at', '<=', $this->dateTo));

        return $this->applyDimensions($query, $prefix);
    }

    /**
     * Apply bot exclusion and the drill-down dimensions, but NOT the date range.
     *
     * Used by queries that define their own time window — the previous-period
     * comparison in DashboardAnalyticsService and the 7d/14d trend in
     * EngagementInsightGenerator — which must still honour an active drill-down.
     *
     * @param  mixed  $query  An active query builder for the clicks table.
     * @param  string  $prefix  Table qualifier for joined queries, e.g. `'clicks.'`.
     * @return mixed The same builder with constraints appended (fluent).
     */
    public function applyDimensions(mixed $query, string $prefix = ''): mixed
    {
        return $query
            ->when($this->excludeBots, fn ($q) => $q->where($prefix.'is_bot', false))
            ->when($this->country, fn ($q) => $q->where($prefix.'country', $this->country))
            ->when($this->device, fn ($q) => $q->where($prefix.'device', $this->device))
            ->when($this->continent, fn ($q) => $q->where($prefix.'continent', $this->continent))
            ->when($this->channel, fn ($q) => $this->applyChannel($q, $prefix));
    }

    /**
     * Apply the channel filter.
     *
     * `direct` is a COALESCE bucket, not a stored value: `click_source` is nullable
     * and every row predating the column carries NULL, which the aggregations count
     * as "direct" (see InsightsAnalyticsService::getTrafficSourceAnalysis). A plain
     * equality would silently drop those rows, so the user would click a bar reading
     * "490 cliques" and land on a page showing fewer.
     *
     * @param  mixed  $query  An active query builder for the clicks table.
     * @param  string  $prefix  Table qualifier for joined queries.
     * @return mixed The same builder with the channel constraint appended.
     */
    private function applyChannel(mixed $query, string $prefix): mixed
    {
        if ($this->channel === 'direct') {
            return $query->where(function ($q) use ($prefix) {
                $q->where($prefix.'click_source', 'direct')
                    ->orWhereNull($prefix.'click_source');
            });
        }

        return $query->where($prefix.'click_source', $this->channel);
    }

    /**
     * Stable string representation of the filter state, used as a cache-key
     * component by LinkAnalyticsOrchestrator::remember().
     *
     * Every dimension MUST appear here. A dimension missing from this key makes
     * the 60s cache serve an unfiltered payload to a filtered request.
     */
    public function cacheKey(): string
    {
        return implode('|', [
            $this->excludeBots ? '1' : '0',
            $this->dateFrom?->toDateTimeString() ?? '',
            $this->dateTo?->toDateTimeString() ?? '',
            $this->country ?? '',
            $this->device ?? '',
            $this->channel ?? '',
            $this->continent ?? '',
        ]);
    }
}
