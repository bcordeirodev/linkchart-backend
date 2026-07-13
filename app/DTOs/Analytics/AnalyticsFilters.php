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

    /**
     * Channel values that `getTrafficSourceAnalysis()` (InsightsAnalyticsService)
     * and `categorizeClickSource()` (LinkTrackingService) map 1:1 to themselves.
     * Any `click_source` outside this set — including `'unknown'` — is folded
     * into the derived `'other'` bucket by those same methods' `match(...)`
     * `default` arm. Kept in sync manually; if that `match(...)` list changes,
     * this constant must change with it.
     */
    private const NAMED_CHANNELS = ['social', 'search', 'direct', 'email', 'referral'];

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
     * Returns a copy of this filter state with the continent dimension cleared.
     *
     * The continent breakdown is the continent *selector*: it owns that dimension,
     * so it must keep showing every continent even while one is selected (the
     * frontend highlights the active slice via `activeContinentCode`). It still
     * honours every other dimension — filtering by device must show the continent
     * split of that device's clicks.
     *
     * @return static New instance identical to this one except `continent` is null.
     */
    public function withoutContinent(): static
    {
        return new static(
            excludeBots: $this->excludeBots,
            dateFrom: $this->dateFrom?->toDateTimeString(),
            dateTo: $this->dateTo?->toDateTimeString(),
            country: $this->country,
            device: $this->device,
            channel: $this->channel,
            continent: null,
        );
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
     * `other` is the same kind of derived bucket, just at the opposite end: it is
     * whatever `click_source` is NOT one of the named channels (see NAMED_CHANNELS),
     * including values like `'unknown'`. A plain equality (`click_source = 'other'`)
     * matches zero rows, since `'other'` is never actually stored — it would send
     * the user from a bar reading "N cliques" to a page showing none.
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

        if ($this->channel === 'other') {
            return $query->where(function ($q) use ($prefix) {
                $q->whereNotNull($prefix.'click_source')
                    ->whereNotIn($prefix.'click_source', self::NAMED_CHANNELS);
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
