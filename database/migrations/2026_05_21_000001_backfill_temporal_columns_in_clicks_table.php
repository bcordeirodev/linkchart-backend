<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill temporal columns for historical click records.
 *
 * Context: the 2025_09_11_130817 migration added is_weekend (default false)
 * and is_business_hours (default false) without backfilling existing rows,
 * and added day_of_week / hour_of_day as nullable without populating them.
 * Any click recorded before that migration therefore has:
 *   - is_weekend      = false  (regardless of the actual day)
 *   - is_business_hours = false  (regardless of the actual hour)
 *   - day_of_week     = null
 *   - hour_of_day     = null
 *
 * This caused the weekday segment filter (WHERE is_weekend = false) to
 * incorrectly include historical weekend clicks, surfacing Saturday/Sunday
 * bars in the day-of-week chart even when "weekday only" was selected.
 *
 * Strategy:
 *   1. Fill day_of_week and hour_of_day WHERE NULL using UTC created_at.
 *      UTC is the best available approximation for visitor local time on
 *      historical rows (the actual local timezone was not stored then).
 *      Rows already populated by LinkTrackingService (post-migration) are
 *      left untouched.
 *   2. Recalculate is_weekend from day_of_week for ALL rows where
 *      day_of_week IS NOT NULL. This is safe because for post-migration rows
 *      the tracking service derives is_weekend from the same day_of_week
 *      value, so the result is identical.
 *   3. Recalculate is_business_hours from hour_of_day for ALL rows where
 *      hour_of_day IS NOT NULL, applying the same [9, 17] business-hours
 *      definition used by LinkTrackingService.
 */
return new class extends Migration
{
    /**
     * Run the backfill.
     */
    public function up(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        if ($isSqlite) {
            $this->upSqlite();
        } else {
            $this->upPostgres();
        }
    }

    /**
     * SQLite variant.
     *
     * SQLite boolean columns are stored as INTEGER (0/1).
     * strftime('%w') returns 0=Sunday…6=Saturday; we normalise to ISO
     * (1=Monday…7=Sunday) to match day_of_week values set by the PHP layer.
     */
    private function upSqlite(): void
    {
        // 1. Backfill day_of_week (null rows only)
        DB::statement("
            UPDATE clicks
            SET day_of_week = CASE CAST(strftime('%w', created_at) AS INTEGER)
                WHEN 0 THEN 7
                ELSE CAST(strftime('%w', created_at) AS INTEGER)
            END
            WHERE day_of_week IS NULL
        ");

        // 2. Backfill hour_of_day (null rows only)
        DB::statement("
            UPDATE clicks
            SET hour_of_day = CAST(strftime('%H', created_at) AS INTEGER)
            WHERE hour_of_day IS NULL
        ");

        // 3. Recalculate is_weekend from day_of_week (all rows with day_of_week set)
        DB::statement("
            UPDATE clicks
            SET is_weekend = CASE WHEN day_of_week IN (6, 7) THEN 1 ELSE 0 END
            WHERE day_of_week IS NOT NULL
        ");

        // 4. Recalculate is_business_hours from hour_of_day (all rows with hour_of_day set)
        DB::statement("
            UPDATE clicks
            SET is_business_hours = CASE WHEN hour_of_day BETWEEN 9 AND 17 THEN 1 ELSE 0 END
            WHERE hour_of_day IS NOT NULL
        ");
    }

    /**
     * PostgreSQL variant.
     *
     * PostgreSQL boolean columns accept TRUE/FALSE literals directly.
     * EXTRACT(DOW FROM …) returns 0=Sunday…6=Saturday; normalised to ISO.
     */
    private function upPostgres(): void
    {
        // 1. Backfill day_of_week (null rows only)
        DB::statement("
            UPDATE clicks
            SET day_of_week = CASE
                WHEN EXTRACT(DOW FROM created_at)::int = 0 THEN 7
                ELSE EXTRACT(DOW FROM created_at)::int
            END
            WHERE day_of_week IS NULL
        ");

        // 2. Backfill hour_of_day (null rows only)
        DB::statement("
            UPDATE clicks
            SET hour_of_day = EXTRACT(HOUR FROM created_at)::int
            WHERE hour_of_day IS NULL
        ");

        // 3. Recalculate is_weekend from day_of_week (all rows with day_of_week set)
        DB::statement("
            UPDATE clicks
            SET is_weekend = (day_of_week IN (6, 7))
            WHERE day_of_week IS NOT NULL
        ");

        // 4. Recalculate is_business_hours from hour_of_day (all rows with hour_of_day set)
        DB::statement("
            UPDATE clicks
            SET is_business_hours = (hour_of_day BETWEEN 9 AND 17)
            WHERE hour_of_day IS NOT NULL
        ");
    }

    /**
     * Cannot safely reverse this migration without the original visitor-
     * timezone data. Rolling back would restore incorrect default values.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
};
