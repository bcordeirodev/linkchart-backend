# Plan — Analytics pre-aggregation (rollup tables)

**Status:** proposed · **Owner:** TBD · **Source finding:** SCALE-1 / #13 in `docs/audits/2026-06-17-backend-code-review.md`

## Problem

Temporal and audience analytics hydrate the full click set for a period into PHP
and aggregate in `foreach` loops, **synchronously per request**, O(clicks-in-period):

- `TemporalAnalyticsService::getHourlyPatterns` and siblings (`->get()` then loop).
- `AudienceAnalyticsService::getLanguageDistribution` (`->select('accept_language')->get()` then parse every row in PHP).

There is no rollup/materialised view. The orchestrator's 60s TTL cache only masks
the cost for the *second* viewer. As a viral link accumulates millions of clicks,
these endpoints get slow and memory-heavy. (Dashboard's direct breakdowns already
use SQL `GROUP BY` and are fine; the temporal/audience PHP-side paths are the cliff.)

## Goal

Make analytics reads scale with **number of days**, not number of clicks, while
**preserving the exact JSON output** of every analytics endpoint (verified by
characterization tests). No change to the public API contract.

## Design

### Schema (two tables)

`link_daily_stats` — headline metrics, one row per `(link_id, stat_date)` (UTC):
- `link_id`, `stat_date` (date), `total_clicks`, `bot_clicks`, `unique_ips`
- `avg_response_time` (nullable), and any other scalar aggregates the dashboard summary needs
- PK / unique on `(link_id, stat_date)`; index `(link_id, stat_date)`.

`link_daily_dimensions` — breakdowns, one row per `(link_id, stat_date, dimension, value)`:
- `link_id`, `stat_date`, `dimension` (e.g. `country`, `device`, `browser`, `os`,
  `referer_host`, `primary_language`, `hour_of_day`, `day_of_week`), `dimension_value`,
  `clicks`, `bot_clicks`
- unique on `(link_id, stat_date, dimension, dimension_value)`; index `(link_id, dimension, stat_date)`.

This narrow per-dimension layout handles every breakdown the analytics services
produce without a schema change per dimension, and supports the `exclude_bots`
filter via the separate `bot_clicks` column.

### Population — nightly batch, not hot-path

Write load stays **off** the redirect/tracking hot path (no contention on hot
rollup rows for viral links):

- New command `analytics:rollup {--date=} {--from=} {--to=}` aggregates a day's
  `clicks` into both tables via idempotent upserts (`insertOrIgnore` / `upsert`),
  so it is safe to re-run and to backfill a date range.
- Schedule it nightly in `bootstrap/app.php withSchedule` (e.g. `dailyAt('03:30')`,
  `withoutOverlapping()`), aggregating the previous UTC day.
- Reuse `SqlDateExpr` for the `hour_of_day`/`day_of_week` extraction so the rollup
  and the live path agree byte-for-byte.

### Read path — hybrid (rollup for the past, live for today)

- **Past days** (≤ yesterday): read from the rollup tables — fast, O(days).
- **Current day**: read live from `clicks` (bounded to ≤ 1 day of data, cheap).
- Merge the two in the analytics service. This bounds live aggregation to a single
  day regardless of total click volume.
- Gate behind a config flag (`config('analytics.use_rollups')`, default off) so the
  new path can be enabled per-environment and rolled back instantly.

## Phases

- **Phase 0 — Baseline.** Capture current analytics latency vs click volume (seed a
  link with N clicks; time each endpoint). Write characterization tests snapshotting
  the current JSON of every analytics endpoint for a fixed dataset — these are the
  parity gate for Phases 3–4.
- **Phase 1 — Schema.** Migrations for `link_daily_stats` + `link_daily_dimensions`
  with indexes. No reads yet. Verify on Postgres (`phpunit.pgsql.xml`).
- **Phase 2 — Rollup command.** `analytics:rollup` (idempotent upsert), backfill
  historical data, schedule nightly. Tests: a day of known clicks produces the
  expected rollup rows; re-running is a no-op; `exclude_bots` accounting is correct.
- **Phase 3 — Read path.** Refactor `TemporalAnalyticsService` / `AudienceAnalyticsService`
  (and any Dashboard PHP-loop paths) to the hybrid read, behind the flag. The Phase 0
  characterization tests MUST stay green with the flag on AND off. Verify on Postgres.
- **Phase 4 — Cutover.** Enable the flag in production, monitor latency + parity, then
  remove the dead PHP-aggregation code once validated. Keep the live current-day path.
- **Phase 5 — Retention (optional).** Once rollups are authoritative for history,
  consider pruning or archiving raw `clicks` beyond the drill-down window, coordinated
  with the existing LGPD IP-anonymisation retention (`clicks:anonymize-ips`).

## Risks / decisions

- **Unique visitors across a range** can't be summed from daily `unique_ips` without
  double-counting repeat visitors across days. Options: accept per-day uniqueness only,
  or store an HLL sketch per day for mergeable approximate distinct. Decide in Phase 1;
  the current code's semantics should be matched (check what "unique" means today).
- **Timezone:** roll up by **UTC** date — analytics already operate in UTC.
- **Filters:** `date_from`/`date_to` map to a `stat_date` range; `exclude_bots` uses
  `clicks - bot_clicks`. Confirm both reproduce current results in the parity tests.
- **Backfill cost:** the historical backfill is a one-off heavy pass — run it off-peak,
  chunked by date.
- **Output parity is non-negotiable:** this is a performance change only. If any
  characterization test diverges, the read path is wrong — fix it, don't update the snapshot.

## Out of scope

Decomposing `DashboardAnalyticsService` (#14) and reconciling the window-percentage
SQLite/Postgres difference are separate, lower-value items — see the audit's status table.
