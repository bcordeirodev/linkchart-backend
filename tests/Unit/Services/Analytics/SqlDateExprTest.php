<?php

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\Support\SqlDateExpr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks the exact SQL fragments emitted by SqlDateExpr so future edits cannot
 * silently change the generated SQL. The expected string is selected by the
 * active driver, so this test runs correctly under both the default SQLite
 * config (phpunit.xml) and the PostgreSQL config (phpunit.pgsql.xml) — pinning
 * BOTH driver branches byte-for-byte.
 */
class SqlDateExprTest extends TestCase
{
    /**
     * Whether the active connection is SQLite (drives expected-value selection).
     */
    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /**
     * The hour-of-day fragment must match the historic expression for the driver.
     */
    public function test_hour_of_day_expression(): void
    {
        $this->assertSame(
            $this->isSqlite()
                ? "COALESCE(hour_of_day, CAST(strftime('%H', created_at) AS INTEGER))"
                : 'COALESCE(hour_of_day, EXTRACT(HOUR FROM created_at)::int)',
            SqlDateExpr::hourOfDay(),
        );
    }

    /**
     * The day-of-week fragment must match the historic expression for the driver.
     */
    public function test_day_of_week_expression(): void
    {
        $this->assertSame(
            $this->isSqlite()
                ? "COALESCE(day_of_week, CASE CAST(strftime('%w', created_at) AS INTEGER) WHEN 0 THEN 7 ELSE CAST(strftime('%w', created_at) AS INTEGER) END)"
                : 'COALESCE(day_of_week, CASE WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7 ELSE EXTRACT(DOW FROM created_at)::int END)',
            SqlDateExpr::dayOfWeek(),
        );
    }

    /**
     * The date fragment must match the historic expression for the driver.
     */
    public function test_date_expression(): void
    {
        $this->assertSame(
            $this->isSqlite()
                ? "strftime('%Y-%m-%d', created_at)"
                : "TO_CHAR(created_at, 'YYYY-MM-DD')",
            SqlDateExpr::date(),
        );
    }

    /**
     * Custom timestamp/stored column names must be interpolated into the fragment.
     */
    public function test_custom_columns_are_interpolated(): void
    {
        $expr = SqlDateExpr::hourOfDay('clicks.created_at', 'clicks.hour_of_day');

        $this->assertStringContainsString('clicks.created_at', $expr);
        $this->assertStringContainsString('COALESCE(clicks.hour_of_day,', $expr);
    }
}
