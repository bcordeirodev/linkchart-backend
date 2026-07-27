<?php

namespace App\Services\Analytics\Support;

use Illuminate\Support\Facades\DB;

/**
 * Centralises the driver-dependent SQL date/time extraction fragments used
 * across the analytics layer.
 *
 * Historically each analytics service inlined its own
 * `DB::connection()->getDriverName() === 'sqlite'` ternary to build hour-of-day,
 * day-of-week and date expressions, duplicating the exact same SQLite/PostgreSQL
 * branches in several places. This class is the single source of truth for those
 * fragments so the emitted SQL stays identical per driver.
 *
 * Each method returns the raw SQL fragment string only. Callers remain
 * responsible for wrapping it in `selectRaw`/`DB::raw` and appending any
 * `as alias`.
 */
class SqlDateExpr
{
    /**
     * Whether the active database connection is SQLite (development/test).
     *
     * @return bool True when the default connection driver is `sqlite`.
     */
    private static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * Builds the hour-of-day (0–23) extraction expression.
     *
     * Prefers the pre-computed stored column and falls back to a driver-native
     * extraction from the timestamp column when it is null.
     *
     * @param  string  $timestampColumn  Source timestamp column. Defaults to `created_at`.
     * @param  string  $storedColumn  Pre-computed column wrapped in COALESCE. Defaults to `hour_of_day`.
     * @return string Raw SQL fragment (no alias).
     */
    public static function hourOfDay(string $timestampColumn = 'created_at', string $storedColumn = 'hour_of_day'): string
    {
        return self::isSqlite()
            ? "COALESCE({$storedColumn}, CAST(strftime('%H', {$timestampColumn}) AS INTEGER))"
            : "COALESCE({$storedColumn}, EXTRACT(HOUR FROM {$timestampColumn})::int)";
    }

    /**
     * Builds the ISO day-of-week (1=Monday … 7=Sunday) extraction expression.
     *
     * Prefers the pre-computed stored column and falls back to a driver-native
     * extraction that remaps Sunday (0) to 7 for a Monday-first week.
     *
     * @param  string  $timestampColumn  Source timestamp column. Defaults to `created_at`.
     * @param  string  $storedColumn  Pre-computed column wrapped in COALESCE. Defaults to `day_of_week`.
     * @return string Raw SQL fragment (no alias).
     */
    public static function dayOfWeek(string $timestampColumn = 'created_at', string $storedColumn = 'day_of_week'): string
    {
        return self::isSqlite()
            ? "COALESCE({$storedColumn}, CASE CAST(strftime('%w', {$timestampColumn}) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', {$timestampColumn}) AS INTEGER) END)"
            : "COALESCE({$storedColumn}, CASE WHEN EXTRACT(DOW FROM {$timestampColumn})::int = 0 THEN 7 ELSE EXTRACT(DOW FROM {$timestampColumn})::int END)";
    }

    /**
     * Builds the calendar date (Y-M-D) formatting expression.
     *
     * @param  string  $timestampColumn  Source timestamp column. Defaults to `created_at`.
     * @return string Raw SQL fragment (no alias).
     */
    public static function date(string $timestampColumn = 'created_at'): string
    {
        return self::isSqlite()
            ? "strftime('%Y-%m-%d', {$timestampColumn})"
            : "TO_CHAR({$timestampColumn}, 'YYYY-MM-DD')";
    }

    /**
     * Builds the calendar year-month (Y-M) formatting expression.
     *
     * Matches PHP's `Carbon::format('Y-m')` (zero-padded month), so SQL
     * GROUP BY aggregations produce the same keys the previous in-memory
     * aggregation produced.
     *
     * @param  string  $timestampColumn  Source timestamp column. Defaults to `created_at`.
     * @return string Raw SQL fragment (no alias).
     */
    public static function yearMonth(string $timestampColumn = 'created_at'): string
    {
        return self::isSqlite()
            ? "strftime('%Y-%m', {$timestampColumn})"
            : "TO_CHAR({$timestampColumn}, 'YYYY-MM')";
    }
}
