# Database Migrations

> **Schema is append-only.** Every change is a new migration. Never edit a merged migration. Never run `migrate:fresh` in production. See `../CONTRIBUTING.md` (Phase 8) for the full policy.

## Migration timeline

### Foundation (Laravel scaffold)

- `0001_01_01_000000_create_users_table.php` — base `users` table (name, email unique, password, remember_token, timestamps), plus `password_reset_tokens` and `sessions` tables created in the same migration (three tables in one scaffold file).
- `0001_01_01_000001_create_cache_table.php` — Laravel database cache driver table (key, value, expiration); unused in this project — Redis is the configured cache driver.
- `0001_01_01_000002_create_jobs_table.php` — Laravel database queue driver table (queue, payload, attempts, etc.); unused — Redis is the configured queue driver.

### Auth & access tokens (2024-09 / 2025-02)

- `2024_09_18_000001_create_email_verification_tokens_table.php` — creates `email_verification_tokens` (token sha-256 64-char, type, expires_at, used/used_at, ip_address, user_agent, composite indexes) AND in the same `up()` also adds `email_verified` (boolean) and `email_verification_sent_at` (nullable timestamp) to `users` (double-duty migration; documented in Phase 1 audit).
- `2025_02_24_210902_create_personal_access_tokens_table.php` — Sanctum's `personal_access_tokens` morphable table (currently unused — JWT via tymon/jwt-auth is the active auth strategy).

### Core link & click model (2025-04)

- `2025_04_20_032909_create_links_table.php` — core `links` table: `id`, `user_id` (FK, not-null at creation), `slug` (unique), `original_url`, `expires_at`, `is_active`, timestamps.
- `2025_04_20_033001_create_clicks_table.php` — core `clicks` table (initial schema): `id`, `link_id` (FK), `ip`, `user_agent` (varchar 1024), `referer`, `country`, `city`, `device`, timestamps.
- `2025_04_20_033105_create_link_utm_table.php` — `link_utms` table joined to `clicks` via `click_id` FK (one UTM record per click, not per link).
- `2025_04_22_135210_update_links.php` — adds `starts_in` (nullable timestamp) to `links` for scheduled activation.

### Hardening & analytics fields (2025-08)

- `2025_08_17_130755_create_link_audits_table.php` — `link_audits` table with `link_id`, `user_id`, `action`, `old_values` (json), `new_values` (json), `ip_address`, `user_agent`; indexes on `(link_id, created_at)`, `(user_id, created_at)`, `(action, created_at)`.
- `2025_08_17_131403_add_additional_fields_to_links_table.php` — adds `title`, `description`, and five UTM default columns (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`) to `links`.
- `2025_08_17_151040_add_clicks_column_to_links_table.php` — adds denormalized `clicks` counter (bigInteger, default 0) to `links` — the column `LinkTrackingService` increments via a direct DB query.
- `2025_08_17_205843_add_click_limit_to_links_table.php` — adds `click_limit` (nullable integer) to `links`; NULL = unlimited.

### Geo + UA enrichment (2025-08 / 2025-09)

- `2025_08_19_160612_add_detailed_location_fields_to_clicks_table.php` — adds detailed geo columns to `clicks`: `iso_code`, `state`, `state_name`, `postal_code`, `latitude`, `longitude`, `timezone`, `continent`, `currency`; plus location indexes.
- `2025_09_11_130817_add_enhanced_tracking_to_clicks_table.php` — adds UA/device fields (`browser`, `browser_version`, `os`, `os_version`, `is_mobile`, `is_tablet`, `is_desktop`, `is_bot`), enriched temporal fields (`hour_of_day` through `is_business_hours`), behavior fields (`is_return_visitor`, `session_clicks`, `click_source`), and performance fields (`response_time`, `accept_language`) to `clicks`.
- `2025_09_14_140000_allow_null_user_id_simple.php` — makes `links.user_id` nullable (drops NOT NULL constraint), enabling anonymous public shortener links; re-applies the FK with cascade delete.
- `2025_09_14_140100_add_performance_indexes_simple.php` — adds performance indexes across three tables via raw SQL (`IF NOT EXISTS`): `clicks` (link_date, geo, user_agent, referer), `links` (user_active, expiration), `users` (created_at).

### Email verification fields (2025-09)

- `2025_09_18_114131_add_email_verification_fields_to_users_table.php` — idempotent safety net: re-adds `email_verified` and `email_verification_sent_at` to `users` only when absent (`Schema::hasColumn` guard on each column). Exists because the 2024-09 double-duty migration may have been skipped in some deployment sequences, leaving a partially-applied `users` schema.

### Health, previews, demo (2026-04)

- `2026_04_27_000001_add_health_to_links_table.php` — adds `health_status` (varchar 20, default `'unknown'`) and `health_checked_at` (nullable timestamp) to `links`; populated by `LinkHealthCheckJob`.
- `2026_04_27_000002_create_link_previews_table.php` — `link_previews` table with `link_id` as primary key (no auto-increment, no timestamps); columns: `favicon_url`, `og_title`, `og_image_url`, `fetched_at`; populated by `FetchLinkPreviewJob`.
- `2026_04_30_000001_add_is_demo_to_links_table.php` — adds `is_demo` boolean (default false) to `links`; used by `SeedDemoLinkJob` to flag demo links excluded from quota calculations.

### Click enrichment Phase 1 (2026-05-07)

- `2026_05_07_000001_add_phase1_enrichment_to_clicks_table.php` — adds Sec-Fetch-derived headers (`navigation_context` varchar 30, `fetch_dest` varchar 30), Client Hints (`ch_platform` varchar 30, `ch_is_mobile` nullable boolean), `is_data_saver` boolean (default false), `http_protocol` varchar 10, `primary_language` varchar 10, `language_region` varchar 10 to `clicks`; indexes on `navigation_context` and `primary_language`.

### Click enrichment Phase 2 (2026-05-07)

- `2026_05_07_000002_add_phase2_contextual_to_clicks_table.php` — adds `is_holiday` (nullable boolean), `holiday_name` varchar 100, `season` varchar 10, `viral_rank` varchar 15, `seconds_since_last_click` nullable integer, `connection_type` varchar 20, `rendering_engine` varchar 20 to `clicks`; indexes on `viral_rank` and `connection_type`.

### Click enrichment Phase 3 (2026-05-07)

- `2026_05_07_000003_add_phase3_quality_to_clicks_table.php` — adds `quality_score` (unsigned tinyint, nullable, 0–100), `quality_tier` (varchar 15, nullable), `fingerprint_score` (unsigned tinyint, default 0; count of detected header inconsistencies 0–3) to `clicks`; index on `quality_tier`.

### Auth0 social login (2026-05)

- `2026_05_12_121915_add_auth0_sub_to_users_table.php` — adds `users.auth0_sub` (nullable, unique — the Auth0 `sub` claim, e.g. `google-oauth2|1234`) and makes `users.password` nullable, since Auth0-only users never set a password.

### Custom subdomains + social platform (2026-05-19)

- `2026_05_19_000001_create_user_subdomains_table.php` — `user_subdomains` table (`user_id` FK, `subdomain` varchar 63, `status` active/inactive); on pgsql adds a **partial unique index** on `subdomain WHERE status = 'active'` so a released subdomain can be reclaimed (SQLite tests skip it).
- `2026_05_19_000002_add_short_domain_to_links_table.php` — adds `links.short_domain` (nullable varchar 255): full hostname captured at link creation (e.g. `acme.linkcharts.com.br`); NULL = default domain; immutable after creation by design.
- `2026_05_19_000003_add_social_platform_to_clicks_table.php` — adds `clicks.social_platform` (nullable varchar 30) after `click_source`.

### Temporal backfill (2026-05-21)

- `2026_05_21_000001_backfill_temporal_columns_in_clicks_table.php` — **data migration** (no schema change): backfills `day_of_week`/`hour_of_day` from UTC `created_at` where NULL and recalculates `is_weekend`/`is_business_hours` for all populated rows — fixes historical weekend clicks leaking into the "weekday only" filter. `down()` is intentionally empty (irreversible without the original timezone data). Separate pgsql/SQLite SQL variants.

### LGPD IP retention (2026-06-12)

- `2026_06_12_000001_add_ip_anonymized_to_clicks_table.php` — adds `clicks.ip_anonymized` (boolean, default false) + composite index `(ip_anonymized, created_at)` so the daily `clicks:anonymize-ips` sweep (90-day retention) only scans rows still pending anonymization.

### Click idempotency (2026-06-17)

- `2026_06_17_000001_add_dedup_key_to_clicks_table.php` — adds `clicks.dedup_key` (nullable varchar 80, UNIQUE): server-generated token created in `RedirectController` and carried in the `ProcessLinkClickJob` payload, making click persistence idempotent under job retry at the database level. Nullable on purpose — legacy payloads without a key still insert (NULLs never collide in a UNIQUE index).

### Referer widening (2026-07-06)

- `2026_07_06_000001_widen_clicks_referer_length.php` — widens `clicks.referer` from varchar(255) to varchar(2048): real referers (notably Facebook in-app-browser `l.php?u=…` wrappers) exceeded 255 chars and made `ProcessLinkClickJob` fail with SQLSTATE[22001], silently dropping those clicks.

### Tags (2026-07-10)

- `2026_07_10_000001_create_tags_table.php` — `tags` table: per-user label (`name` varchar 50, `color` 7-char hex) with `unique(user_id, name)` so name uniqueness is scoped per user.
- `2026_07_10_000002_create_link_tag_table.php` — `link_tag` pivot for the Link ↔ Tag many-to-many; lean by design (no surrogate id, no timestamps), both FKs cascade on delete.

### Onboarding + welcome email (2026-07-13 / 07-14)

- `2026_07_13_000001_add_onboarding_to_users_table.php` — adds `users.onboarding` (nullable JSON map): "user has seen X" markers keyed by dotted flag name → dismissal timestamp; replaces localStorage-only persistence so the guided tour stops replaying per browser/device.
- `2026_07_14_120000_add_welcome_email_sent_at_to_users.php` — adds `users.welcome_email_sent_at` (nullable timestamp): at-most-once guard claimed by `SendWelcomeEmailJob` via a conditional UPDATE, so job retries never send a duplicate welcome email.

### Multiple subdomains per user (2026-07-15)

- `2026_07_15_000001_allow_multiple_user_subdomains.php` — drops `UNIQUE(user_id)` on `user_subdomains` (replaced by a plain index), allowing several subdomains per user; the global partial unique on active `subdomain` labels remains.

### Clicks IP index (2026-07-27)

- `2026_07_27_000001_add_ip_index_to_clicks_table.php` — adds composite index `(ip, created_at)` (`idx_clicks_ip_created_at`): `ProcessLinkClickJob::analyzeVisitorBehavior` queries `WHERE ip = ? AND created_at >= ?` on every click (24h/1h windows), which was a full table scan without it.

---

## Policy

- **Append-only.** To change a column, add a new migration. Never edit a merged one.
- **Never `migrate:fresh` in production.** Production migrations run automatically via `scripts/deploy.sh` with `php artisan migrate --force`.
- **Rollback in production = forward migration that reverts the change.** Do not use `migrate:rollback` in prod.
- **Schema changes that affect the link slug cache** (e.g. adding a column that should invalidate `link:slug:{slug}` on save) MUST update the field list in `app/Models/Link.php::booted()` — see `app/Models/README.md`.
- **Click enrichment phases are append-only AND order-dependent.** Phase 2 queries reference columns from Phase 1 in some aggregations; Phase 3 references both. Don't reorder, don't squash.
