<?php

namespace App\DTOs\Analytics;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Value object encapsulating the base query filters shared by all analytics services.
 *
 * Constructed from an HTTP Request via `fromRequest()`. Each analytics service
 * calls `applyToQuery()` on its base Click query before running aggregations.
 */
readonly class AnalyticsFilters
{
    public readonly ?Carbon $dateFrom;

    public readonly ?Carbon $dateTo;

    public function __construct(
        public readonly bool $excludeBots = false,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ) {
        $this->dateFrom = $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : null;
        $this->dateTo = $dateTo ? Carbon::parse($dateTo)->endOfDay() : null;
    }

    /**
     * Build an AnalyticsFilters from HTTP query params.
     *
     * Recognised params: `date_from` (ISO date string), `date_to` (ISO date string),
     * `exclude_bots` (bool-castable string).
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
        );
    }

    /**
     * Apply date-range and bot-exclusion constraints to a Click query builder.
     *
     * Works with both Eloquent\Builder and Query\Builder since both
     * implement `when()`, `whereDate()`, and `where()`.
     *
     * @param  mixed  $query  An active query builder for the clicks table.
     * @return mixed The same builder with constraints appended (fluent).
     */
    public function applyToQuery(mixed $query): mixed
    {
        return $query
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->excludeBots, fn ($q) => $q->where('is_bot', false));
    }
}
