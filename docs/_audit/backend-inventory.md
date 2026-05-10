# Backend Inventory — 2026-05-10

> Snapshot of the linkcharts backend (Laravel 12 / PHP 8.2). Source for the consolidation plan at `docs/superpowers/plans/2026-05-10-backend-consolidation-and-documentation.md`.
> **Read-only audit.** No code is changed by writing this document.

## 1. Controllers

### BaseController

**Role:** Abstract base class for all API controllers; provides shared ownership helpers and standardised JSON response factories.

**Constructor dependencies:** _None._

**Protected helpers:**
- **protected** `findOwnedLink(int|string $id, string $by = 'id'): ?Link` — queries the authenticated user's links by the given field; returns `null` if unauthenticated or link not found.
- **protected** `linkNotFound(): JsonResponse` — returns a 404 JSON response with a generic Portuguese "not found / no permission" message.
- **protected** `serverError(string $message, \Throwable $e): JsonResponse` — logs the exception via `AppLogger::httpServerError`, then returns a 500 JSON response (debug detail included only when `APP_DEBUG=true`).

**Notes:**
- Has no routes; never registered in `routes/api.php` or `routes/web.php`.
- All other API controllers that extend it inherit the three helpers above without re-declaring them.

---

### Auth/AuthController

**Role:** Handles user registration, login, logout, JWT token refresh, profile management, password operations, and email verification flows.

**Constructor dependencies:**
- `EmailVerificationService $emailVerificationService`

**Actions table:**

| Action | Route | Middleware | FormRequest | Resource |
|---|---|---|---|---|
| login | POST /api/auth/login | throttle:login | — | — |
| register | POST /api/auth/register | throttle:login | — | — |
| googleLogin | POST /api/auth/google | throttle:login | — | — |
| verifyEmail | POST /api/auth/verify-email | throttle:login | — | — |
| forgotPassword | POST /api/auth/forgot-password | throttle:login | — | — |
| resetPassword | POST /api/auth/reset-password | throttle:login | — | — |
| me | GET /api/me | api.auth:api | — | — |
| logout | POST /api/logout | api.auth:api | — | — |
| checkEmailVerificationStatus | GET /api/email-verification-status | api.auth:api | — | — |
| resendVerificationEmail | POST /api/resend-verification-email | api.auth:api | — | — |
| updateProfile | PUT /api/profile | api.auth:api, verified | — | — |
| changePassword | PUT /api/change-password | api.auth:api, verified | — | — |

**Notes:**
- `googleLogin` is declared as a route (`POST /api/auth/google`) but the corresponding method does **not exist** in the controller file — this is a stub / dead route.
- `resendVerificationEmail` sits in the `api.auth:api`-only group (lines 69–77 of `routes/api.php`), NOT in the `throttle:login` group — carries no rate limit.
- All validation is done inline via `Validator::make`; no FormRequest classes are used in this controller.

---

### Links/LinkController

**Role:** RESTful CRUD for authenticated user's links, plus per-link click data, paginated click list, and audit history.

**Constructor dependencies:**
- `LinkServiceInterface $linkService`
- `LinkAuditService $auditService`

**Actions table:**

| Action | Route | Middleware | FormRequest | Resource |
|---|---|---|---|---|
| index | GET /api/links | api.auth:api, verified | — | `LinkResource` (collection) |
| store | POST /api/links | api.auth:api, verified | `CreateLinkRequest` | `LinkResource` |
| show | GET /api/links/{id} | api.auth:api, verified | — | `LinkResource` |
| update | PUT /api/links/{id} | api.auth:api, verified | `UpdateLinkRequest` | `LinkResource` |
| destroy | DELETE /api/links/{id} | api.auth:api, verified | — | — |
| getClicksData | GET /api/link/{id}/clicks | api.auth:api, verified | — | — |
| getClicksList | GET /api/link/{id}/clicks-list | api.auth:api, verified | — | — |
| auditHistory | GET /api/links/{id}/audit-history | api.auth:api, verified | — | — |
| showBySlug | — | — | — | `LinkResource` |

**Notes:**
- `getClicksData` and `getClicksList` are mounted under the singular prefix `/api/link/{id}/...` (not `/api/links`), as noted in lines 110–113 of `routes/api.php`.
- A separate `PublicLinkController::showBySlug` (line 65) handles `GET /api/public/link/{slug}`. The `LinkController::showBySlug` method (line 476) appears to be a duplicate/leftover with no route registration — verify in Task 1.10.
- `auditHistory` is defined in the controller but has no matching route in `routes/api.php` — it is an orphan method (verify in Task 1.10).
- `GET /api/links/{id}/analytics` is mounted on the `links` prefix group but dispatches to `AnalyticsController::getLinkLegacyAnalytics` (see AnalyticsController notes).

---

### Links/LinkMetaController

**Role:** Provides per-link metadata endpoints: batch sparkline/trend/preview/health in a single call, plus individual granular endpoints for each metric type.

**Constructor dependencies:**
- `MetricsService $metricsService` (`App\Services\Analytics\MetricsService`)

**Actions table:**

| Action | Route | Middleware | FormRequest | Resource |
|---|---|---|---|---|
| batchMeta | POST /api/links/batch-meta | api.auth:api, verified | — | — |
| sparkline | GET /api/links/{id}/sparkline | api.auth:api, verified | — | — |
| trend | GET /api/links/{id}/trend | api.auth:api, verified | — | — |
| preview | GET /api/links/{id}/preview | api.auth:api, verified | — | — |
| health | GET /api/links/{id}/health | api.auth:api, verified | — | — |

**Notes:**
- `batchMeta` accepts `{ ids: int[], days?: int }` in the request body and dispatches `FetchLinkPreviewJob` for stale or missing previews as a side-effect.
- Inline `$request->validate(...)` is used in `batchMeta`; no separate FormRequest class.

---

### Links/PublicLinkController

**Role:** Unauthenticated endpoints for public URL shortening, basic link info lookup by slug, and basic public analytics (no sensitive data).

**Constructor dependencies:**
- `LinkServiceInterface $linkService`

**Actions table:**

| Action | Route | Middleware | FormRequest | Resource |
|---|---|---|---|---|
| store | POST /api/public/shorten | throttle:public-shorten | `CreatePublicLinkRequest` | `PublicLinkResource` |
| showBySlug | GET /api/public/link/{slug} | — | — | `PublicLinkResource` |
| basicAnalytics | GET /api/public/analytics/{slug} | throttle:public-analytics | — | — |

**Notes:**
- `store` creates links with no `user_id` (anonymous/public links).
- `basicAnalytics` caches its response for 5 minutes via `Cache::remember("public_analytics_{id}", 300, ...)`.
- `showBySlug` has no route-level rate limit.

---

### Links/RedirectController

**Role:** The critical redirect hot-path: serves bots with Open Graph HTML for social previews; redirects human visitors via HTTP 302 and dispatches async click tracking.

**Constructor dependencies:**
- `LinkServiceInterface $linkService`
- `LinkTrackingService $linkTrackingService`

**Actions table:**

| Action | Route | Middleware | FormRequest | Resource |
|---|---|---|---|---|
| redirect | GET /r/{slug} | throttle:redirect, metrics.redirect | — | — |
| redirect | GET /{slug} | throttle:redirect, metrics.redirect | — | — |
| ~~handle~~ | ~~GET /api/r/{slug}~~ | **DISABLED** | — | — |

**Notes:**
- The `redirect` action is served by **two** routes in `routes/web.php`: `/r/{slug}` (named `public.redirect`) and `/{slug}` (named `public.redirect.clean`, constrained by `where('slug', '[^/]+')`). Both carry identical middleware.
- The old `handle` action on `/api/r/{slug}` was commented out on 2025-11-04 and migrated to `routes/web.php`. It must **not** be re-enabled without justification (see comment block at `routes/api.php` lines 18–32).
- For human visitors, tracking is completely asynchronous: `ProcessLinkClickJob` is dispatched and the HTTP response is returned immediately (302) without waiting for the job. The denormalised `links.clicks` counter is incremented via `DB::table->increment` to avoid model observers and keep the cached `Link` stable.
- Bot detection checks `BOT_USER_AGENT_PATTERNS` (20 patterns) and then falls back to `jenssegers/agent` `isRobot()`.
- SSRF protection is applied before fetching OG metadata from the original URL (`isSafeFetchUrl`).

---

### Analytics/AnalyticsController

**Role:** Advanced per-link analytics — dashboard data, comprehensive stats, geographic, temporal, audience, business insights, and a legacy flat-aggregation endpoint.

**Constructor dependencies:**
- `LinkAnalyticsOrchestrator $analyticsService`
- `TemporalAnalyticsInterface $temporalService`

**Actions table:**

| Action | Route | Middleware | FormRequest | Resource |
|---|---|---|---|---|
| getLinkDashboardData | GET /api/analytics/link/{linkId}/dashboard | api.auth:api, verified | — | — |
| getLinkAnalytics | GET /api/analytics/link/{linkId}/comprehensive | api.auth:api, verified | — | — |
| getGeographicAnalytics | GET /api/analytics/link/{linkId}/geographic | api.auth:api, verified | — | — |
| getBusinessInsights | GET /api/analytics/link/{linkId}/insights | api.auth:api, verified | — | — |
| getTemporalAnalytics | GET /api/analytics/link/{linkId}/temporal | api.auth:api, verified | — | — |
| getAudienceAnalytics | GET /api/analytics/link/{linkId}/audience | api.auth:api, verified | — | — |
| getLinkLegacyAnalytics | GET /api/links/{id}/analytics | api.auth:api, verified | — | — |

**Notes:**
- `getLinkLegacyAnalytics` is **cross-mounted**: it lives in `AnalyticsController` but is registered inside the `Route::prefix('links')->controller(LinkController::class)` group at `routes/api.php` line 97 as `[AnalyticsController::class, 'getLinkLegacyAnalytics']`. The path resolves to `/api/links/{id}/analytics`.
- `getTemporalAnalytics` merges base temporal data from `LinkAnalyticsOrchestrator` with advanced data from `TemporalAnalyticsInterface`, enriching timezone entries with percentage fields before returning.
- All actions use `findOwnedLink` (inherited from `BaseController`) to enforce ownership before loading analytics.

---

### MetricsController

**Role:** Unified metrics controller offering dashboard, per-category, per-link, comparison, and cache-clearing endpoints backed by `MetricsService`.

**Constructor dependencies:**
- `MetricsService $metricsService` (`App\Services\MetricsService`)

**Actions table:**

| Action | Route | Middleware | FormRequest | Resource |
|---|---|---|---|---|
| getDashboardMetrics | — | — | — | — |
| getMetricsByCategory | — | — | — | — |
| getLinkMetrics | — | — | — | — |
| compareMetrics | — | — | — | — |
| clearCache | — | — | — | — |

**Routed:** none found in `routes/api.php` or `routes/web.php` — possible orphan (verify in Task 1.10).

**Notes:**
- Does **not** extend `BaseController`; manages auth checks inline via `auth()->guard('api')->id()`.
- PHPDoc comments describe it as "eliminates duplication between analytics controllers" and "replaces multiple endpoints" — suggests it was intended as a consolidation target but never wired into the router.

## 2. Services

### Sub-folder: root (`app/Services/`)

#### EmailService

- **Role:** Low-level email dispatcher — sends transactional email via the SendGrid HTTP API or Laravel's native `Mail` facade (SMTP); also exposes test and config-inspection helpers.
- **Contract:** none
- **Constructor dependencies:** _None._ (Guzzle-backed `SendGrid` client instantiated inline from config.)
- **Public methods:**
  - `sendEmailViaSendGridAPI($toEmail, $subject, $htmlContent, $textContent = null, $toName = null): array`
  - `sendTestEmailViaSendGridAPI($toEmail, $toName = null): array`
  - `testSendGridAPI(): array`
  - `getSendGridConfiguration(): array`
  - `sendTestEmail($toEmail, $toName = null): array`
  - `testConnection(): array`
  - `getMailConfiguration(): array`
  - `sendCustomEmail($toEmail, $subject, $htmlContent, $textContent = null): array`
- **Side effects:** sends email via SendGrid HTTP API (`sendgrid/sendgrid`); sends email via Laravel `Mail` facade; logs to `app` channel (`AppLogger::emailSent`, `AppLogger::emailFailed`, `AppLogger::emailTestFailed`).
- **Notes:**
  - All public methods lack PHP type hints on parameters — every argument is untyped.
  - `testConnection()` sends a real email to the hardcoded address `test@example.com` when called.
  - `getSendGridConfiguration()` and `getMailConfiguration()` are pure read-only helpers with no side effects.

---

#### EmailVerificationService

- **Role:** Orchestrates email-based verification and password-reset flows: creates tokens, sends emails via `EmailService`, marks tokens used, and updates user state.
- **Contract:** none
- **Constructor dependencies:**
  - `EmailService $emailService`
- **Public methods:**
  - `sendVerificationEmail(User $user, ?Request $request = null): array`
  - `verifyEmail(string $token): array`
  - `sendPasswordResetEmail(string $email, ?Request $request = null): array`
  - `resetPassword(string $token, string $newPassword): array`
- **Side effects:** writes to `email_verification_tokens` (`EmailVerificationToken::createEmailVerificationToken`, `EmailVerificationToken::createPasswordResetToken`, `EmailVerificationToken::markAsUsed`); writes to `users` (`User::markEmailAsVerified`, `User::markVerificationEmailSent`, `User::update` for password); sends email via `EmailService::sendEmailViaSendGridAPI`; logs to `auth` channel (`AppLogger::authEmailVerificationSent`, `AppLogger::authEmailVerified`, `AppLogger::authPasswordResetRequested`, `AppLogger::authPasswordResetCompleted`, `AppLogger::authError`).
- **Notes:**
  - `sendPasswordResetEmail` always returns `success: true` even when the user is not found (security: email enumeration prevention).
  - Renders Blade views (`emails.verification`, `emails.verification-text`, `emails.password-reset`, `emails.password-reset-text`) to produce email body strings.

---

### Sub-folder: Services/Links

#### ClickVelocityService

- **Role:** Tracks per-link click velocity using Redis sliding windows (5 min and 60 min) and classifies links into viral-rank tiers (viral, trending, warming, cold).
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `record(int $linkId): array`
- **Side effects:** reads/writes Redis keys `link:{id}:v5`, `link:{id}:v60`, `link:{id}:last_click` via a Redis pipeline; logs a `warning` via `Log::warning` (not `AppLogger`) on Redis downtime — **flagged for Task 1.10** (inconsistent logging facade).
- **Notes:**
  - Degrades gracefully when Redis is unavailable: returns `['viral_rank' => 'cold', 'seconds_since_last_click' => null]`.
  - Thresholds read from `config/tracking.php` under `viral_thresholds`.
  - `getset` (deprecated in Redis ≥ 6.2) is used for the `last_click` timestamp swap.

---

#### LinkAuditService

- **Role:** Records create/update/delete operations on links into the `link_audits` table for audit, security, and compliance purposes.
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `logCreated(Link $link, int $userId, Request $request): void`
  - `logUpdated(Link $link, array $oldValues, int $userId, Request $request): void`
  - `logDeleted(Link $link, int $userId, Request $request): void`
  - `getLinkHistory(int $linkId, int $userId): \Illuminate\Database\Eloquent\Collection`
  - `getUserHistory(int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection`
- **Side effects:** writes to `link_audits` (`LinkAudit::create`); logs to `audit` channel on failure (`AppLogger::auditFailed`).
- **Notes:**
  - `getLinkHistory` and `getUserHistory` are pure reads (no writes or cache).
  - Audit failures are swallowed — they log to the `audit` channel but do not propagate the exception, so the primary operation (link CRUD) is never blocked by audit errors.

---

#### LinkPreviewService

- **Role:** Fetches Open Graph metadata (title, image) and favicon URL for a given destination URL.
- **Contract:** none
- **Constructor dependencies:** _None._ (Constructs a `GuzzleHttp\Client` with 5 s timeout internally.)
- **Public methods:**
  - `fetchPreview(string $url): array`
- **Side effects:** outbound HTTP GET to the destination URL via Guzzle.
- **Notes:**
  - TLS verification is disabled (`'verify' => false`) in the Guzzle client — flagged for Task 1.10.
  - Favicon is always resolved via `https://www.google.com/s2/favicons?domain=…` (external HTTP call to Google).
  - On Guzzle `RequestException`, returns empty metadata but still attempts to build the favicon URL.

---

#### LinkSafetyService

- **Role:** Checks a URL against the Google Safe Browsing API v4 for malware, phishing, unwanted software, and harmful applications.
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `checkUrl(string $url): array`
- **Side effects:** outbound HTTP POST to `https://safebrowsing.googleapis.com/v4/threatMatches:find` via Laravel `Http` facade; logs to `app` channel (`AppLogger::safetyUrlFlagged`, `AppLogger::safetyApiUnavailable`, `AppLogger::safetyApiError`).
- **Notes:**
  - When the API key is missing or the request fails, it fails open (returns `safe: true`) and sets `api_available: false`.

---

#### LinkService

- **Role:** Business-logic layer for authenticated link CRUD (create, read, update, delete, redirect resolution, public shortening, slug generation).
- **Contract:** `Contracts\Services\LinkServiceInterface`
- **Constructor dependencies:**
  - `LinkRepositoryInterface $linkRepository`
- **Public methods:**
  - `getAllUserLinks(): Collection`
  - `getUserLink(string $id): ?Link`
  - `createLink(CreateLinkDTO $linkDTO): Link`
  - `updateLink(string $id, UpdateLinkDTO $linkDTO): ?Link`
  - `deleteLink(string $id): bool`
  - `processRedirect(string $slug): ?string`
  - `createPublicLink(CreatePublicLinkDTO $linkDTO): Link`
  - `generateUniqueSlug(int $length = 6): string`
- **Side effects:** delegates all persistence to `LinkRepositoryInterface` (reads and writes `links` table); `processRedirect` calls `linkRepository->incrementClicks` which updates the `links.clicks` column.
- **Notes:**
  - All auth-scoped reads use `auth()->guard('api')->id()` inline.
  - `processRedirect(string $slug)` (line 97) is dead code: it has no callers in `app/`, `tests/`, or `routes/` (verified via grep). It is the only caller of `LinkRepository::incrementClicks(string $slug)`, so that repository method is also effectively dead. Both are flagged for Task 1.10 review (potential removal). The active increment of `links.clicks` happens exclusively in `LinkTrackingService::registrarCliqueFromPayload` (line 108), invoked by `ProcessLinkClickJob`.

---

#### LinkTrackingService

- **Role:** Enriches a click payload with geo-location (GeoIP), user-agent parsing, temporal data, visitor behavior, navigation context, viral rank, quality score, and holiday/season signals, then persists the enriched `Click` record and any UTM data.
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `registrarCliqueFromPayload(int $linkId, array $payload): void`
  - `resolveRealUserIP(Request $request): string`
- **Side effects:** reads from `clicks` (for behavior analysis); writes to `clicks` (`Click::create`); writes to `link_utms` (`LinkUtm::create`); increments `links.clicks` via `DB::table('links')->increment('clicks')`; resolves geo-location via `app('geoip')` (torann/geoip); records viral rank via `app(ClickVelocityService::class)->record()` (Redis pipeline); calls `azuyalabs/yasumi` for holiday detection; logs to `tracking` channel (`AppLogger::trackingClickRegistered`, `AppLogger::trackingLinkNotFound`, `AppLogger::geoipDefaultLocation`, `AppLogger::geoipFailed`, `AppLogger::userAgentParseFailed`, `AppLogger::trackingTemporalEnrichmentFailed`, `AppLogger::trackingBehaviorAnalysisFailed`, `AppLogger::event('tracking', 'warning', 'tracking.invalid_timezone', …)`).
- **Notes:**
  - Called exclusively from `ProcessLinkClickJob` (asynchronous) — never on the HTTP request path.
  - `resolveRealUserIP` is a pure utility method with no side effects; called in `RedirectController` before dispatching the job.
  - Uses `app()->make(ClickVelocityService::class)` rather than constructor injection — flagged for Task 1.10.
  - The docblock above `RedirectController::dispatchTracking()` (line 117) says "increment the denormalised click counter directly via DB" — this is misleading because the method itself only dispatches the job; the increment happens later inside the job. Rewrite candidate for Phase 3 PHPDoc work.

---

### Sub-folder: Services/Onboarding

#### OnboardingDemoDataService

- **Role:** Seeds a new user's account with a demo link and 1,200 realistic synthetic clicks spread across 60 days to populate analytics from day one.
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `run(User $user): void`
- **Side effects:** writes one row to `links` (`Link::create`, `Link::update`); writes up to 1,200 rows to `clicks` in batches of 500 (`Click::insert`); reads `links` to check for existing demo data.
- **Notes:**
  - Idempotent: exits early if any link with `is_demo = true` already exists for the user.
  - All writes bypass the tracking pipeline (no job dispatch, no GeoIP, no UTM parsing) — data is synthetic.
  - Uses `mt_rand` for IP and timestamp generation — not cryptographically random, which is acceptable for demo data.

---

### Sub-folder: Services/Analytics

#### AudienceAnalyticsService

- **Role:** Aggregates audience-dimension analytics for a link: device, browser, OS, language, platform (Client Hints), connection type, rendering engine, navigation context, return-visitor rate, and quality tier breakdown.
- **Contract:** `Contracts\Analytics\AudienceAnalyticsInterface`
- **Constructor dependencies:**
  - `UserAgentParser $uaParser`
- **Public methods:**
  - `getLinkAudienceAnalytics(int $linkId): array`
- **Side effects:** reads `clicks` and `links` tables (read-only aggregations via `DB::table` and `Click`/`Link` Eloquent queries). _None observed_ (pure query service).

---

#### DashboardAnalyticsService

- **Role:** Produces the full dashboard payload for a single link — summary KPIs (click totals, unique visitors, success rate, response time, viral rank, quality), temporal patterns, geographic heatmap, and audience breakdowns — with optional time-window filtering.
- **Contract:** `Contracts\Analytics\DashboardAnalyticsInterface`
- **Constructor dependencies:** _None._
- **Public methods:**
  - `getLinkDashboardAnalytics(int $linkId, int $hours = 0): array`
- **Side effects:** reads `clicks` and `links` tables (read-only aggregations). _None observed_ (pure query service).
- **Notes:**
  - Contains its own `extractPrimaryLanguage` helper, duplicating the same logic that exists in `UserAgentParser::extractPrimaryLanguage` and `DashboardAnalyticsService` itself — flagged for Task 1.10.
  - SQLite-aware: switches between `EXTRACT(HOUR FROM …)` (PostgreSQL) and `strftime('%H', …)` (SQLite) in several private methods.

---

#### GeographicAnalyticsService

- **Role:** Aggregates geographic analytics for a link: heatmap data points, top countries/states/cities, and continent distribution.
- **Contract:** `Contracts\Analytics\GeographicAnalyticsInterface`
- **Constructor dependencies:** _None._
- **Public methods:**
  - `getLinkGeographicAnalytics(int $linkId): array`
- **Side effects:** reads `clicks` and `links` tables (read-only aggregations). _None observed_ (pure query service).

---

#### InsightsAnalyticsService

- **Role:** Generates business insights for a link by running all registered `InsightGeneratorInterface` implementations and computing supplemental analytics (retention, session depth, traffic sources, navigation context, HTTP protocol, quality tier breakdown).
- **Contract:** `Contracts\Analytics\InsightsAnalyticsInterface`
- **Constructor dependencies:** _None._ (Instantiates `InsightGeneratorRegistry` and all 8 generators directly in `__construct`.)
- **Public methods:**
  - `getLinkInsightsAnalytics(int $linkId): array`
- **Side effects:** reads `clicks` and `links` tables (read-only aggregations). _None observed_ (pure query service).
- **Notes:**
  - All 8 generators are constructed inline (`new GeographicInsightGenerator`, etc.) rather than being injected — this makes them untestable in isolation and prevents swapping implementations via the container. Flagged for Task 1.10.

---

#### LinkAnalyticsOrchestrator

- **Role:** Thin orchestrator that fan-outs analytics requests to the five domain analytics services (dashboard, geographic, temporal, audience, insights) and assembles a combined payload.
- **Contract:** none (concrete class, not bound to an interface)
- **Constructor dependencies:**
  - `DashboardAnalyticsInterface $dashboard`
  - `GeographicAnalyticsInterface $geographic`
  - `TemporalAnalyticsInterface $temporal`
  - `AudienceAnalyticsInterface $audience`
  - `InsightsAnalyticsInterface $insights`
- **Public methods:**
  - `getComprehensiveLinkAnalytics(int $linkId): array`
  - `getLinkDashboardAnalytics(int $linkId, int $hours = 0): array`
  - `getLinkGeographicAnalytics(int $linkId): array`
  - `getLinkTemporalAnalytics(int $linkId): array`
  - `getLinkAudienceAnalytics(int $linkId): array`
  - `getLinkInsightsAnalytics(int $linkId): array`
- **Side effects:** delegates entirely to injected services — reads `clicks` and `links` tables indirectly. _None observed_ beyond what the delegates produce.
- **Notes:**
  - This class has no contract (no interface, not bound in `AppServiceProvider`) — it is resolved directly by `AnalyticsController`. If a second orchestrator were ever needed, swapping would require changing the controller. Flagged for Task 1.10.

---

#### MetricsService

- **Role:** Provides cached per-user and per-link metric aggregations (click totals, performance stats, geographic reach, audience device types, sparkline series, trend comparison) used by `LinkMetaController` and `MetricsController`.
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `getUserBasicMetrics(int $userId, int $hours = 24): array`
  - `getUserPerformanceMetrics(int $userId, int $hours = 24): array`
  - `getLinkMetrics(int $linkId): array`
  - `getUserGeographicMetrics(int $userId): array`
  - `getUserAudienceMetrics(int $userId): array`
  - `clearUserMetricsCache(int $userId): void`
  - `getUserTopLinks(int $userId, int $limit = 5): array`
  - `getUserChartData(int $userId, int $hours = 24): array`
  - `getLinkSparkline(int $linkId, int $days = 7): array`
  - `getLinkTrend(int $linkId, int $window = 7): array`
- **Side effects:** reads from cache (`Cache::remember`, `Cache::get`); `clearUserMetricsCache` calls `Cache::forget` for per-user metric keys; reads `clicks` and `links` tables; logs to `app` channel on error in `getUserChartData` (`AppLogger::analyticsError`).
- **Notes:**
  - `clearUserMetricsCache` uses literal pattern strings (e.g. `"metrics:user:{$userId}:*"`) with `Cache::forget` — glob patterns are not supported by the standard `Cache::forget` API and will silently fail if the cache driver is not Redis with pattern-delete support. Flagged for Task 1.10.
  - `calculateAverageResponseTime` reads hourly redirect-metrics cache keys (`redirect_metrics:hour:{date}`) written by `RedirectMetricsCollector` middleware — creates an implicit dependency on that middleware's cache format.
  - `getUserChartData` contains hardcoded `EXTRACT(DOW FROM …)` PostgreSQL syntax (not SQLite-safe), unlike the analytics services which are dual-driver.

---

#### TemporalAnalyticsService

- **Role:** Aggregates temporal analytics for a link: clicks by hour and day-of-week, local hourly patterns, weekend vs. weekday, business hours, holiday impact, seasonal distribution, and a full advanced-temporal payload with weekly/monthly trends, heatmap matrix, and device-by-period breakdown.
- **Contract:** `Contracts\Analytics\TemporalAnalyticsInterface`
- **Constructor dependencies:** _None._
- **Public methods:**
  - `getLinkTemporalAnalytics(int $linkId): array`
  - `getAdvancedTemporalAnalytics(int $linkId): array`
- **Side effects:** reads `clicks` and `links` tables (read-only aggregations). _None observed_ (pure query service).
- **Notes:**
  - `getAdvancedTemporalAnalytics` loads all clicks for a link into memory (`Click::where(...)->get()`) and processes them in PHP — may be slow for links with many clicks. Flagged for Task 1.10.
  - SQLite-aware in `getLinkTemporalAnalytics` methods but not in `getAdvancedTemporalAnalytics` (which relies on Carbon's `startOfWeek`/`format` on the Eloquent model — acceptable).

---

### Sub-folder: Services/Analytics/Support

#### UserAgentParser

- **Role:** Lightweight regex-based parser for extracting browser name, OS name, and primary language from raw `User-Agent` / `Accept-Language` strings.
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `extractBrowser(?string $ua): string`
  - `extractOS(?string $ua): string`
  - `extractPrimaryLanguage(?string $acceptLanguage): ?string`
- **Side effects:** _None observed._ (Pure string processing.)
- **Notes:**
  - `extractPrimaryLanguage` contains the same language-name mapping as `DashboardAnalyticsService::extractPrimaryLanguage` — duplicated logic. Flagged for Task 1.10.
  - Used only by `AudienceAnalyticsService`; `DashboardAnalyticsService` has its own inline copy instead of injecting this class.

---

### Sub-folder: Services/Analytics/Insights

#### InsightGeneratorInterface

Contract for all insight generators. Declares a single method:

- `generate(int $linkId, int $totalClicks): ?array` — returns an insight array if the condition is met, or `null` to skip this generator.

---

#### InsightGeneratorRegistry

- **Role:** Holds an ordered list of `InsightGeneratorInterface` instances and runs them all against a link, collecting non-null results.
- **Contract:** none
- **Constructor dependencies:** _None._
- **Public methods:**
  - `register(InsightGeneratorInterface $generator): void`
  - `generate(int $linkId, int $totalClicks): array`
- **Side effects:** delegates to each registered generator's `generate()` call — reads `clicks` table indirectly. _None observed_ at this layer.

---

### Sub-folder: Services/Analytics/Insights/Generators

All 8 generators implement `InsightGeneratorInterface` and are registered in `InsightGeneratorRegistry` (instantiated inline by `InsightAnalyticsService::__construct`). Each exposes exactly one public method: `generate(int $linkId, int $totalClicks): ?array`. They are pure read-only aggregators against the `clicks` table with no cache, queue, or log side effects.

| Generator | One-line role | Notable inputs |
|---|---|---|
| `DeviceInsightGenerator` | Identifies the dominant device type and its share of total clicks | `clicks.device` |
| `DiversityInsightGenerator` | Fires when a link has reached more than 5 distinct countries | `clicks.country` |
| `EngagementInsightGenerator` | Detects week-over-week growth or decline exceeding 20 % | `clicks.created_at` |
| `GeographicInsightGenerator` | Identifies the top country and its percentage of total clicks | `clicks.country` |
| `PerformanceInsightGenerator` | Flags slow average response time (> 500 ms) or confirms good performance | `clicks.response_time` |
| `RetentionInsightGenerator` | Reports return-visitor rate and benchmarks it against thresholds (15 %, 25 %) | `clicks.is_return_visitor`, `clicks.ip` |
| `SecurityInsightGenerator` | Flags any IP with more than 50 clicks as potentially abnormal | `clicks.ip` |
| `TemporalInsightGenerator` | Identifies the peak hour of click activity | `clicks.hour_of_day`, `clicks.created_at` |

## 3. Repositories

### LinkRepository

- **Contract:** `Contracts\Repositories\LinkRepositoryInterface`
- **Model:** `Link`
- **Public methods:**
  - `getAllByUser(): Collection` — returns all links for the currently authenticated user, ordered `created_at DESC`.
  - `findByIdAndUser(string $id, int $userId): ?Link` — finds a link by `id` scoped to `user_id`.
  - `findBySlug(string $slug): ?Link` — finds an active link by `slug` (`is_active = true`).
  - `create(array $data): Link` — thin `Link::create` wrapper.
  - `update(string $id, array $data, int $userId): ?Link` — delegates to `findByIdAndUser`, calls `update` + `fresh()`.
  - `delete(string $id, int $userId): bool` — delegates to `findByIdAndUser`, calls `delete()`.
  - `incrementClicks(string $slug): bool` — increments `links.clicks` via `Link::where('slug',...)->where('is_active', true)->increment('clicks')`.
  - `slugExists(string $slug): bool` — `Link::where('slug', $slug)->exists()`.
- **Non-trivial queries:**
  - `getAllByUser()` — filters by `user_id` + `is_active` indirectly via `idx_links_user_active` (`links.user_id, is_active, created_at`) defined in `2025_09_14_140100_add_performance_indexes_simple.php`.
  - `findBySlug()` — filters by `slug` + `is_active`; benefits from `idx_links_user_active` only partially (no composite slug index). The slug column has a unique index from the original create-links migration.
  - `incrementClicks()` — update query on `slug` + `is_active`; same index note as `findBySlug`.
- **Notes:**
  - `incrementClicks` is effectively dead code: its only caller is `LinkService::processRedirect`, which itself has no callers in `app/`, `routes/`, or `tests/` (verified in Task 1.3). The active click counter increment happens in `LinkTrackingService::registrarCliqueFromPayload` via a raw `DB::table('links')->increment` call that bypasses this repository entirely.
  - Auth context (`auth()->guard('api')->id()`) is resolved directly inside `getAllByUser()` rather than being passed as a parameter — couples the repository to the HTTP request context.

---

### ChartRepository

- **Contract:** `none` (no interface; not bound in `AppServiceProvider`)
- **Model:** `Click`, `Link`, `User` (all three queried directly)
- **Public methods:**
  - `totalClicks($userId = null, $linkId = null)` — `COUNT(*)` on `clicks`, optionally filtered by user (via `whereHas('link', user_id)`) or `link_id`.
  - `clicksByDay($days = 30, $userId = null, $linkId = null)` — `DATE(created_at)` grouped by day, limited to `$days` rows.
  - `clicksByCountry($userId = null, $linkId = null)` — `COUNT(*)` grouped by `country`.
  - `clicksByCity($userId = null, $linkId = null)` — `COUNT(*)` grouped by `city`.
  - `clicksByDevice($userId = null, $linkId = null)` — `COUNT(*)` grouped by `device`.
  - `clicksByReferer($userId = null, $linkId = null)` — `COUNT(*)` grouped by `referer`.
  - `clicksByCampaign($userId = null, $linkId = null)` — three-table join (`link_utms → clicks → links`); `COUNT(*)` grouped by `utm_campaign`.
  - `clicksPerLinkByDay($linkId = null, $days = 30)` — `DATE(created_at)` grouped by day for one or all links.
  - `topLinks($limit = 10, $userId = null)` — `Link::withCount('clicks')` ordered by `clicks_count DESC`.
  - `clicksByUser()` — joins `users → links → clicks`, `COUNT(clicks.id)` grouped by `users.id, users.name`.
  - `clicksGroupedByLinkAndDay($userId = null, $linkId = null)` — `link_id + DATE(created_at)` grouped.
  - `clicksByUserAgent($userId = null, $linkId = null)` — `COUNT(*)` grouped by `user_agent`.
  - `linksCreatedByDay($userId = null, $days = 30)` — `DATE(created_at)` grouped by day on `links`.
- **Non-trivial queries:**
  - `clicksByDay`, `clicksPerLinkByDay`, `clicksGroupedByLinkAndDay` — all filter/group on `created_at`; benefit from `idx_clicks_link_date` (`clicks.link_id, created_at`) defined in `2025_09_14_140100_add_performance_indexes_simple.php` when `link_id` is supplied.
  - `clicksByCountry`, `clicksByCity` — filter on `link_id, country, city`; served by `idx_clicks_geo` (`clicks.link_id, country, city`) from the same migration.
  - `clicksByReferer` — filters on `link_id, referer`; served by `idx_clicks_referer` (`clicks.link_id, referer`).
  - `clicksByUserAgent` — filters on `link_id, user_agent`; served by `idx_clicks_user_agent` (`clicks.link_id, user_agent`).
  - `clicksByCampaign` — explicit three-table join via raw `DB::table('link_utms')→join('clicks',...)→join('links',...)`. No dedicated composite index for this join path.
  - `topLinks` — uses `withCount('clicks')` which produces a sub-select; benefits from `idx_clicks_link_date` (full scan of `clicks` per link).
  - `clicksByUser` — explicit two-join chain (`users → links → clicks`) with `GROUP BY users.id, users.name`; `idx_links_user_active` helps the `links.user_id` join leg.
- **Notes:**
  - No callers found in `app/`, `routes/`, or `tests/` — `ChartRepository` appears to be an **orphan class** (no route, no controller, no service injects it). Flagged as dead code for Task 1.10.
  - All parameters are untyped (no PHP type hints); all methods lack return-type declarations.
  - The `whereHas` approach used for user-scoped filtering triggers a correlated sub-query on `links.user_id`; a direct `join` would be more efficient for large datasets.

---

### WordRepository

- **Contract:** `none` (no interface; not bound in `AppServiceProvider`)
- **Model:** `Word` — referenced in the `use App\Models\Word` import but **no `app/Models/Word.php` file exists** in the codebase.
- **Public methods:**
  - `getAll(): Collection` — `Word::all()`.
  - `find(string $id): ?Word` — `Word::find($id)`.
  - `create(array $data): Word` — `Word::create($data)`.
  - `update(string $id, array $data): ?Word` — `Word::find + update()`.
  - `delete(string $id): ?Word` — `Word::find + delete()`, returns the deleted model.
- **Non-trivial queries:** none — all are simple CRUD wrappers.
- **Notes:**
  - **Dead orphan:** no callers found anywhere in `app/`, `routes/`, or `tests/`. The `Word` model it imports does not exist. This class cannot be instantiated without a fatal autoload error. Flagged for removal in Task 1.10.
  - All return types are declared only in PHPDoc `@return` annotations, not as PHP type hints.

## 4. Contracts

### Contracts → Implementations

| Contract | Implementer | Bound in (file:line) |
|---|---|---|
| `Contracts\Repositories\LinkRepositoryInterface` | `Repositories\LinkRepository` | AppServiceProvider:17 |
| `Contracts\Services\LinkServiceInterface` | `Services\Links\LinkService` | AppServiceProvider:22 |
| `Contracts\Analytics\DashboardAnalyticsInterface` | `Services\Analytics\DashboardAnalyticsService` | AppServiceProvider:26 |
| `Contracts\Analytics\GeographicAnalyticsInterface` | `Services\Analytics\GeographicAnalyticsService` | AppServiceProvider:27 |
| `Contracts\Analytics\TemporalAnalyticsInterface` | `Services\Analytics\TemporalAnalyticsService` | AppServiceProvider:28 |
| `Contracts\Analytics\AudienceAnalyticsInterface` | `Services\Analytics\AudienceAnalyticsService` | AppServiceProvider:29 |
| `Contracts\Analytics\InsightsAnalyticsInterface` | `Services\Analytics\InsightsAnalyticsService` | AppServiceProvider:30 |

**Notes:** All 7 contract bindings live exclusively in `AppServiceProvider::register()` — there is no second binding location. `LinkAnalyticsOrchestrator` is the only analytics orchestration class that has **no contract** (confirmed in Task 1.3): `AnalyticsController` injects it as a concrete class, bypassing the container's interface resolution. `ChartRepository` and `WordRepository` have no contracts and are not bound. The five analytics interfaces (`DashboardAnalyticsInterface`, `GeographicAnalyticsInterface`, `TemporalAnalyticsInterface`, `AudienceAnalyticsInterface`, `InsightsAnalyticsInterface`) each define a single method — they are minimal contracts that exactly mirror the public surface of their concrete implementations.

## 5. DTOs

### CreateLinkDTO

- **Direction:** input
- **Properties:**
  - `string $original_url` (required)
  - `int $user_id` (required)
  - `?string $title`
  - `?string $description`
  - `?string $expires_at`
  - `bool $is_active` (default `true`)
  - `?string $starts_in`
  - `?string $custom_slug`
  - `?int $click_limit`
  - `?string $utm_source`, `?string $utm_medium`, `?string $utm_campaign`, `?string $utm_term`, `?string $utm_content`
- **Used by:**
  - `LinkController::store` — calls `CreateLinkDTO::fromRequest($request)` to build the DTO (consumer).
  - `LinkService::createLink(CreateLinkDTO $linkDTO)` — accepts it and delegates to `LinkRepository::create($dto->toArray())`.
  - `LinkServiceInterface::createLink` — declares it in the method signature.
- **Notes:**
  - `readonly` properties (PHP 8.1 style, assigned via constructor body).
  - Static factory `fromRequest(Request $request): self` resolves `user_id` via `Auth::id()` internally — couples instantiation to the HTTP context.
  - `toArray()` uses `array_filter(..., fn ($value) => $value !== null)` which silently drops `is_active = false` if that value is `false` but not null — a subtle bug candidate (false is not null, so this is safe; `false` passes `!== null`). However `0` and `""` would be dropped — flagged for Task 1.10.
  - Additional helpers: `isValidUrl(): bool` (pure URL validation).

---

### CreatePublicLinkDTO

- **Direction:** input
- **Properties:**
  - `string $original_url` (required)
  - `?string $title`
  - `?string $slug`
  - `bool $is_active` (default `true`)
  - `?int $user_id` (always `null` for public links, documented in comment)
- **Used by:**
  - `PublicLinkController::store` — calls `CreatePublicLinkDTO::fromRequest($request)` (consumer).
  - `LinkService::createPublicLink(CreatePublicLinkDTO $linkDTO)` — accepts it.
  - `LinkServiceInterface::createPublicLink` — declares it in the method signature.
- **Notes:**
  - `readonly` constructor-promoted properties (PHP 8.1 syntax).
  - Static factory `fromRequest(CreatePublicLinkRequest $request): self` — typed to the specific `FormRequest` subclass, unlike `CreateLinkDTO::fromRequest` which accepts the base `Request`.
  - `toArray()` hardcodes `'clicks' => 0`, `'created_at' => now()`, `'updated_at' => now()` — sets audit timestamps inside the DTO, which is unusual and bypasses Eloquent's automatic timestamp management.
  - Additional helpers: `isValidUrl(): bool`, `hasValidData(): bool`.

---

### LinkDTO

- **Direction:** both (can represent input from a request or output from a model)
- **Properties:**
  - `?string $id`
  - `string $original_url`
  - `string $expires_at`
  - `bool $is_active`
  - `string $created_at`
  - `string $updated_at`
  - `string $starts_in`
- **Used by:** _use site not found in `app/` or `tests/`_ — this DTO is an orphan with no callers.
- **Notes:**
  - **Not** `readonly`; all properties are mutable public fields.
  - Has both `fromRequest(Request $request): self` and `fromModel(Link $link): self` static factories.
  - Covers only 7 of the 20+ fields on `Link` (no `slug`, no UTM fields, no `click_limit`, no `title`/`description`) — likely superseded by the `LinkResource` API resource and the dedicated Create/Update DTOs. Flagged for removal in Task 1.10.
  - `expires_at`, `created_at`, `updated_at`, `starts_in` are typed as `string` rather than `?Carbon` or `?datetime` — no null handling.

---

### UpdateLinkDTO

- **Direction:** input
- **Properties:**
  - `?string $original_url`
  - `?string $title`
  - `?string $slug`
  - `?string $description`
  - `?string $expires_at`
  - `?bool $is_active`
  - `?string $starts_in`
  - `?int $click_limit`
  - `?string $utm_source`, `?string $utm_medium`, `?string $utm_campaign`, `?string $utm_term`, `?string $utm_content`
- **Used by:**
  - `LinkController::update` — calls `UpdateLinkDTO::fromRequest($request)` (consumer).
  - `LinkService::updateLink(string $id, UpdateLinkDTO $linkDTO)` — accepts it.
  - `LinkServiceInterface::updateLink` — declares it in the method signature.
- **Notes:**
  - `readonly` properties (PHP 8.1 style, assigned via constructor body).
  - All properties nullable; `fromRequest` uses `$request->has('is_active')` to distinguish "not sent" from `false` — correctly avoids treating absent fields as explicit nulls.
  - `toArray()` same `array_filter` pattern as `CreateLinkDTO` — strips all null values, preventing accidental overwrites of existing data on partial updates.
  - Additional helpers: `hasDataToUpdate(): bool`, `isValidUrl(): bool`.

## 6. Models (and Observers)

### User

- **Table:** `users`
- **Relationships:**
  - `links()` — `HasMany Link`
  - `emailVerificationTokens()` — `HasMany EmailVerificationToken` (foreign key `email`, local key `email`)
- **Casts:** `email_verified_at` → `datetime`; `email_verification_sent_at` → `datetime`; `email_verified` → `boolean`; `password` → `hashed`.
- **Scopes:** _None._
- **Observers:** registered via `User::observe(UserObserver::class)` in `AppServiceProvider::boot()` (line 35).
- **Cache:** none.
- **Helpers:**
  - `hasVerifiedEmail(): bool` — checks both `email_verified` flag and `email_verified_at` being non-null.
  - `markEmailAsVerified(): void` — sets `email_verified = true` and `email_verified_at = now()`.
  - `canResendVerificationEmail(): bool` — enforces a 2-minute re-send cooldown via `email_verification_sent_at`.
  - `markVerificationEmailSent(): void` — updates `email_verification_sent_at = now()`.
  - `getJWTIdentifier()` / `getJWTCustomClaims()` — required by `JWTSubject` (tymon/jwt-auth).
- **Notes:**
  - Implements `JWTSubject` for `tymon/jwt-auth`.
  - `$hidden` includes `password` and `remember_token`.
  - The email-verification contract (`MustVerifyEmail`) is commented out; verification is handled by the custom `email_verified` boolean column rather than Laravel's built-in verification system.

---

### Link

- **Table:** `links`
- **Relationships:**
  - `user()` — `BelongsTo User`
  - `clicks()` — `HasMany Click`
  - `preview()` — `HasOne LinkPreview`
- **Casts:** `expires_at` → `datetime`; `starts_in` → `datetime`; `is_active` → `boolean`; `is_demo` → `boolean`; `health_checked_at` → `datetime`.
- **Scopes:** _None._
- **Observers:** none via `AppServiceProvider`. Cache invalidation is self-registered inside `static::booted()` — `saved` and `deleted` model events.
- **Cache:**
  - Key: `link:slug:{slug}` (via `slugCacheKey(string $slug): string`).
  - TTL: `600` seconds (constant `CACHE_TTL_SECONDS = 600`).
  - Invalidation triggered by `saved` event when: link was just created (`wasRecentlyCreated = true`) OR any of `[slug, is_active, expires_at, starts_in, original_url, click_limit]` changed.
  - On slug rename: also forgets the previous slug key (`getOriginal('slug')`).
  - `deleted` event always forgets the slug key.
  - Public static access: `Link::findActiveBySlugCached(string $slug): ?self`.
- **Helpers:**
  - `isExpired(): bool` — returns `true` if `expires_at` is set and in the past.
  - `hasReachedClickLimit(): bool` — returns `true` if `click_limit !== null && clicks >= click_limit`.
  - `getRemainingClicks(): ?int` — returns `null` for unlimited; otherwise `max(0, click_limit - clicks)`.
  - `getShortedUrl(): string` — returns `{config('app.redirect_url')}/{slug}`.
- **Notes:**
  - `is_demo` boolean differentiates real links from demo seed data (used by `OnboardingDemoDataService`).
  - `health_status` and `health_checked_at` fields are present for link health monitoring (populated by `FetchLinkPreviewJob`).

---

### Click

- **Table:** `clicks`
- **Relationships:**
  - `link()` — `BelongsTo Link`
  - `utm()` — `HasOne LinkUtm`
- **Casts:** none declared (all columns queried as raw types).
- **Scopes:** _None._
- **Observers:** none.
- **Cache:** none.
- **Helpers:** none.
- **Notes:**
  - The most column-rich model in the codebase: 50+ fillable fields spanning geographic data, device/browser/OS details, temporal enrichment (hour, day-of-week, month, weekend flag, business-hours flag), behavioral signals (return visitor, session clicks), navigation context (Sec-Fetch headers, Client Hints, HTTP protocol, language), viral/seasonal context, and quality scoring (Phase 1–3 enrichment migrations).
  - No casts are declared despite several boolean-like fields (`is_mobile`, `is_tablet`, `is_desktop`, `is_bot`, `is_return_visitor`, `is_weekend`, `is_business_hours`, `is_holiday`, `is_data_saver`) — these are stored as the DB's native type and returned as integers or strings depending on the driver.

---

### LinkAudit

- **Table:** `link_audits`
- **Relationships:**
  - `link()` — `BelongsTo Link`
  - `user()` — `BelongsTo User`
- **Casts:** `old_values` → `array`; `new_values` → `array`.
- **Scopes:** _None._
- **Observers:** none.
- **Cache:** none.
- **Helpers:** none (three class constants: `ACTION_CREATED`, `ACTION_UPDATED`, `ACTION_DELETED`).
- **Notes:** pure append-only audit log; never updated after creation.

---

### LinkPreview

- **Table:** `link_previews`
- **Relationships:**
  - `link()` — `BelongsTo Link`
- **Casts:** `fetched_at` → `datetime`.
- **Scopes:** _None._
- **Observers:** none.
- **Cache:** none.
- **Helpers:** none.
- **Notes:**
  - `$primaryKey = 'link_id'` (non-standard primary key — the preview is a 1:1 extension of `Link`).
  - `$incrementing = false` — primary key is not auto-incremented.
  - `$timestamps = false` — no `created_at`/`updated_at`; `fetched_at` is used as the staleness indicator instead.

---

### LinkUtm

- **Table:** `link_utms`
- **Relationships:**
  - `click()` — `BelongsTo Click`
- **Casts:** none declared.
- **Scopes:** _None._
- **Observers:** none.
- **Cache:** none.
- **Helpers:** none.
- **Notes:** stores the five UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`) extracted from the click's referrer URL or query string by `LinkTrackingService`. One row per click that had at least one UTM param.

---

### EmailVerificationToken

- **Table:** `email_verification_tokens`
- **Relationships:**
  - `user()` — `BelongsTo User` (foreign key `email`, owner key `email`; non-FK join on email string).
- **Casts:** `expires_at` → `datetime`; `used_at` → `datetime`; `used` → `boolean`.
- **Scopes:** _None._
- **Observers:** none.
- **Cache:** none.
- **Helpers:**
  - `generateToken(): string` (static) — `hash('sha256', Str::random(60))`.
  - `createEmailVerificationToken(string $email, ...): self` (static) — invalidates previous unused tokens of the same type before creating a new one (24 h TTL).
  - `createPasswordResetToken(string $email, ...): self` (static) — same pattern with 1 h TTL.
  - `isValid(): bool` — checks `!used && expires_at->isFuture()`.
  - `markAsUsed(): void` — sets `used = true`, `used_at = now()`.
  - `findValidToken(string $token, string $type): ?self` (static) — finds an unexpired, unused token.
  - `cleanExpiredTokens(): int` (static) — bulk deletes expired or used tokens; intended for a scheduled command.
- **Notes:**
  - `type` column distinguishes `email_verification` from `password_reset` tokens.
  - Token invalidation on `create*` methods is non-atomic (a `UPDATE` followed by an `INSERT`) — potential race condition under concurrent requests.

---

### Observers

#### UserObserver

- **Registered in:** `AppServiceProvider::boot()` line 35 via `User::observe(UserObserver::class)`.
- **Reacts to:** `User::created` event.
- **Action:** dispatches `SeedDemoLinkJob::dispatch($user->id)`, which invokes `OnboardingDemoDataService::run` asynchronously to seed a demo link with 1,200 synthetic clicks for the new user.

## 7. Jobs

### ProcessLinkClickJob

- **Trigger:** `RedirectController::dispatchTracking()` line 143 — `ProcessLinkClickJob::dispatch($link->id, $payload)`. Dispatched for every human (non-bot) redirect hit, immediately before the 302 response is returned.
- **Queue:** `default` (no explicit `onQueue()` call and no `$queue` property set).
- **Retry policy:** `$tries = 3`, `$backoff = 10` (seconds), `$timeout = 30` (seconds).
- **Side effects:**
  - Calls `LinkTrackingService::registrarCliqueFromPayload($linkId, $payload)` which writes one `Click` row to `clicks` and optionally one `LinkUtm` row to `link_utms` (if UTM params are present).
  - Enriches the click with GeoIP data via `torann/geoip`, User-Agent data via `jenssegers/agent`, temporal/behavioral/performance fields.
  - Logs to the `jobs` channel (`AppLogger::jobStarted`, `AppLogger::jobSucceeded`, `AppLogger::jobFailed`).
  - Logs to the `tracking` channel via `AppLogger::trackingClickRegistered` (and warning variants on GeoIP/UA failure).
- **Idempotency:** **not idempotent** — each invocation inserts a new `Click` row unconditionally. On retry after a partial failure (e.g., a crash after inserting `Click` but before the job ack), a duplicate click row will be written. No deduplication guard exists.
- **On final failure:** after exhausting all 3 tries, Laravel moves the job to the failed-jobs table. There is no `failed()` method on this class; the last `AppLogger::jobFailed` call in the `catch` block is the final log entry before the exception propagates and the framework records failure.
- **Notes:** uses the `HasLogContext` trait. `pushLogContext()` is called at the start of `handle()` and `popLogContext()` is called in a `finally` block, propagating the originating HTTP request's `request_id` (from `$payload['request_id']`) into every log line emitted during job execution.

---

### SeedDemoLinkJob

- **Trigger:** `UserObserver::created()` — `SeedDemoLinkJob::dispatch($user->id)`. Fired immediately after a new user is persisted, before the registration HTTP response is sent.
- **Queue:** `default` (no explicit `onQueue()` call).
- **Retry policy:** `$tries = 3`, `$backoff = 30` (seconds), `$timeout = 60` (seconds).
- **Side effects:**
  - Calls `OnboardingDemoDataService::run($user)` which seeds demo `Link` rows and 1,200 synthetic `Click` rows for the new user.
  - Logs to the `jobs` channel (`AppLogger::jobStarted`, `AppLogger::jobSucceeded`, `AppLogger::jobFailed`).
  - If the user is not found at execution time, the job exits early (treating it as success) — no error is raised.
- **Idempotency:** **not idempotent** — each invocation calls `OnboardingDemoDataService::run` which creates additional demo links and clicks. A retry after partial failure will result in duplicate demo data. In practice, the early-exit guard (user not found) is the only safety net.
- **On final failure:** has an explicit `failed(Throwable $e): void` method that calls `AppLogger::jobFailed(static::class, $e, $this->tries)` as the final-failure callback. The failed job is also recorded in the failed-jobs table by the framework.
- **Notes:** uses the `HasLogContext` trait. `logContextRequestId()` returns `null` (no originating request_id — the job is dispatched after the registration response is sent), so `pushLogContext()` generates a fallback `job_<hex>` id. `logContextUserId()` returns `$this->userId` so logs carry the new user's id.

---

### FetchLinkPreviewJob

- **Trigger:** dispatched from two places in `LinkMetaController`:
  - `batchMeta()` line 47 — `FetchLinkPreviewJob::dispatch((int) $id, $link->original_url)` — triggered when a preview is missing or stale (older than 24 hours) for any link in the batch.
  - `preview()` line 100 — `FetchLinkPreviewJob::dispatch($link->id, $link->original_url)` — same staleness check for a single link preview endpoint.
- **Queue:** `default` (no explicit `onQueue()` call).
- **Retry policy:** `$tries = 2`, no explicit `$backoff` property (Laravel default: 0 seconds), `$timeout = 30` (seconds).
- **Side effects:**
  - Calls `LinkPreviewService::fetchPreview($url)` which performs an external HTTP request to fetch OG metadata from the original URL.
  - Writes to the `link_previews` table via `LinkPreview::updateOrCreate(['link_id' => $linkId], [..., 'fetched_at' => now()])`.
- **Idempotency:** **idempotent via `updateOrCreate`** — retrying produces an upsert on `link_id`, so no duplicate rows are created. The `fetched_at` field is always overwritten with `now()`.
- **On final failure:** no `failed()` method and no `AppLogger` calls — failures are silently swallowed after exhausting retries (job moves to failed-jobs table with no domain-level side effect).
- **Notes:** does not use the `HasLogContext` trait — log lines from this job carry no `request_id` correlation.

---

### LinkHealthCheckJob

- **Trigger:** scheduler only — `bootstrap/app.php` line 10 — `$schedule->job(new \App\Jobs\LinkHealthCheckJob)->hourly()->withoutOverlapping()`. Not dispatched from any application code.
- **Queue:** `default` (no explicit `onQueue()` call).
- **Retry policy:** `$tries = 1` (no retries), no explicit `$backoff`, `$timeout = 300` (seconds — 5 minutes for the full scan).
- **Side effects:**
  - Issues an HTTP `HEAD` request to every active link's `original_url` via Guzzle (timeout 5s, connect-timeout 3s, up to 5 redirects, SSL verification disabled).
  - Processes links in chunks of 50 via `Link::where('is_active', true)->chunk(50, ...)`.
  - For each link: updates `links.health_status` (`ok` or `error`) and `links.health_checked_at` via `DB::table('links')->where('id', $link->id)->update(...)`.
- **Idempotency:** **idempotent** — each run overwrites `health_status` and `health_checked_at` in place. Re-running produces the same result (assuming the target URL's state is unchanged).
- **On final failure:** no `failed()` method and no `AppLogger` calls. Individual URL check failures are caught and silently recorded as `status = 'error'`; a catastrophic failure of the whole job moves it to the failed-jobs table with no domain-level notification.
- **Notes:** does not use the `HasLogContext` trait. `withoutOverlapping()` prevents a second scheduler invocation while the previous run is still processing — important given the potentially long scan duration across all active links.

## 8. Middlewares

| Middleware | Alias | Where applied | Purpose | Notes |
|---|---|---|---|---|
| `ApiAuthenticate` | `api.auth` | per-route via `api.auth:api` | Validates JWT and resolves the authenticated user; throws `AuthenticationException` for missing/invalid tokens. | Extends Laravel's built-in `Authenticate`; `redirectTo()` returns `null` for `api/*` or JSON requests so the exception renders as JSON (handled in `bootstrap/app.php`). |
| `AssignRequestId` | none | `web()` and `api()` stacks (both) | Generates or reuses an inbound `X-Request-Id`; calls `RequestContext::set()` to populate the process-scoped logging context; echoes the id back on the response; clears the context in `finally`. | Must run early — placed before `NormalizeApiResponse` and all domain logic. Format: reuse inbound header or generate `req_<16 hex chars>`. `TrustProxies` is the only middleware that precedes it. |
| `EnsureEmailIsVerified` | `verified` | per-route, combined with `api.auth:api` | Checks `auth()->user()->hasVerifiedEmail()`; returns a 403 JSON with `type=email_not_verified` and resend metadata if the user has not verified. | Returns 401 if no user is authenticated (defence-in-depth; `api.auth` normally fires first). `can_resend` and `last_sent` fields expose the cooldown state to the frontend. |
| `MetricsCollector` | `metrics.collector` | aliased — not applied to any route in `routes/api.php` or `routes/web.php` as of this audit | Collects per-request performance metrics (response time, memory, status codes, endpoints) into Redis/file cache. Collects per-hour and per-minute bucketed counters plus per-user and per-day error data. | Contains direct `Log::debug()` and `Log::debug()` calls — does not use `AppLogger` (violation of the logging convention). Not wired to any route; the alias exists but appears unused. |
| `NormalizeApiResponse` | none | `api()` stack (all API routes) | Wraps every JSON response in the canonical envelope: success → `{data, meta?, message?}`; error (4xx/5xx) → `{error: {code, message, details?}}`. Passes non-JSON responses through unchanged. | Applied globally to the `api` group in `bootstrap/app.php`; controllers may return bare arrays or `success/message` shapes without worrying about the envelope. |
| `RedirectMetricsCollector` | `metrics.redirect` | per-route on `GET /r/{slug}` and `GET /{slug}` (both redirect routes in `routes/web.php`) | Collects detailed redirect-specific metrics (slug, IP, country via GeoIP, device type, referer domain, response time, status code) into Redis/file cache as hourly and daily buckets. | Contains direct `Log::debug()` calls throughout (violation of the logging convention). `getRealUserIP()` duplicates the IP-resolution logic of `LinkTrackingService`. |
| `TrustProxies` | none | `web()` and `api()` stacks (both, first in each stack) | Configures Laravel to trust proxy headers (`X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Port`, `X-Forwarded-Proto`) from all proxies (`$proxies = '*'`). | Extends Laravel's built-in `TrustProxies`. Overrides `shouldTrustRequest()`: always trusts in `local`/`development`; in production, trusts only when proxy headers are present (Nginx/Cloudflare). `X-Real-IP` and `CF-Connecting-IP` are handled manually in `LinkTrackingService` / `RedirectMetricsCollector`. |

## 9. Providers / Bindings

Single service provider: `app/Providers/AppServiceProvider.php`.

### `register()` — Service Container bindings

See **Section 4 (Contracts)** for the full interface-to-implementation table. In summary, `register()` binds:
- `LinkRepositoryInterface` → `LinkRepository`
- `LinkServiceInterface` → `LinkService`
- `DashboardAnalyticsInterface` → `DashboardAnalyticsService`
- `GeographicAnalyticsInterface` → `GeographicAnalyticsService`
- `TemporalAnalyticsInterface` → `TemporalAnalyticsService`
- `AudienceAnalyticsInterface` → `AudienceAnalyticsService`
- `InsightsAnalyticsInterface` → `InsightsAnalyticsService`

All bindings use `$this->app->bind(...)` (transient — a new instance per resolution).

### `boot()` — Runtime setup

1. **`User::observe(UserObserver::class)`** (line 35) — registers `UserObserver` to fire on `User::created`, dispatching `SeedDemoLinkJob`.

2. **`Request::macro('isApiRequest', fn(): bool)`** (line 37) — adds a macro to Laravel's `Request` object. Returns `true` when the request either sends an `Accept: application/json` header (`$this->expectsJson()`) or has a path matching `api/*` (`$this->is('api/*')`). Used by the exception handler in `bootstrap/app.php` to decide whether to render JSON error responses.

3. **Rate limiter registrations** (`RateLimiter::for(...)`) — four named limiters:

| Name | Window | Key | Limit | Applied to |
|---|---|---|---|---|
| `login` | 1 minute | `email` input (or `ip` when absent) | 5 requests | `POST /api/auth/login`, register, google-login, forgot-password, verify-email |
| `public-shorten` | 1 minute | IP address | 10 requests | `POST /api/public/shorten` |
| `public-analytics` | 1 minute | IP address | 30 requests | `GET /api/public/analytics/{slug}` |
| `redirect` | 1 minute | IP address | 600 requests | `GET /r/{slug}` and `GET /{slug}` (flood protection only) |

## 10. Console (Commands + Schedule)

### OptimizeApiCommand

- **Signature:** `api:optimize`
- **Description:** `Optimize Laravel API for production (without views)`
- **Purpose:** Clears config, route, and application caches, then re-caches config and routes. Skips `view:cache` (API-only project has no Blade views in production). If the queue driver is not `sync`, restarts queue workers via `queue:restart`. Intended to be run as part of a production deployment pipeline.

---

### TestEmailCommand

- **Signature:** `email:test {email} {--name= : Nome do destinatário} {--send : Enviar email real}`
- **Description:** `Testa configuração de email e conectividade SMTP`
- **Purpose:** Development and ops utility. Always prints the current mail configuration (mailer, host, port, from address) and tests DNS resolution + SMTP connectivity via `EmailService::testConnection()`. With `--send`, actually dispatches a test email to the provided address via `EmailService::sendTestEmail()`. Safe to run in production for smoke-testing email delivery.

---

### UpdateExistingLinksUrls

- **Signature:** `app:update-existing-links-urls`
- **Description:** `Command description` (placeholder — never filled in)
- **Purpose:** Unknown and empty — `handle()` contains only a comment stub. **Candidate orphan:** the command body was never implemented; it is likely a one-shot migration scaffold that was run (or abandoned) and never cleaned up. Should be deleted unless there is a documented future use.

---

### Schedule

Defined in `bootstrap/app.php::withSchedule()` (lines 9–11).

| Job | Frequency | Concurrency |
|---|---|---|
| `LinkHealthCheckJob` | `hourly()` | `withoutOverlapping()` |

- `routes/console.php` contains only the default Laravel `inspire` Artisan stub (`Artisan::command('inspire', ...)`); scheduling is fully defined in `bootstrap/app.php`, as noted by a comment in that file.

## 11. Logging

### Architecture

The logging system is built around `App\Logging\AppLogger` — a `final` class of semantic static methods. **Never call `\Log::*` directly anywhere in the codebase; always use `AppLogger`.** If a specific event method does not exist, use the escape hatch `AppLogger::event($channel, $level, $event, $context)`.

**Known violation:** `ClickVelocityService.php:44` calls `Log::warning(...)` directly instead of using `AppLogger`. `MetricsCollector` and `RedirectMetricsCollector` also contain many `Log::debug()` calls.

`AppLogger` has **49 public static methods** (note: spec said 51 — actual count from `grep -c "public static function"` is 49). Each method is named after a domain event (e.g. `redirectStarted`, `jobFailed`, `authLoginSuccess`) and encodes the channel, log level, and event name.

### Components

| File | Role |
|---|---|
| `AppLogger.php` | Central logging facade — semantic methods per domain event, each encoding channel + level + event name. |
| `Context/RequestContext.php` | Process-scoped singleton holding `requestId`, `userId`, `ip`, `route` for the current HTTP request or job. |
| `Context/HasLogContext.php` | Trait for queued jobs — `pushLogContext()` sets `RequestContext` from the job payload's `request_id`; `popLogContext()` (in `finally`) clears it. |
| `Taps/ChannelTap.php` | Monolog tap applied to each channel's file handler; configures per-channel processors (including `PiiRedactionProcessor` for non-auth/non-audit channels and `RequestContextProcessor` everywhere). Accepts `:skip-redaction` parameter for `auth` and `audit` channels. |
| `Taps/SampleRateTap.php` | Monolog tap applied to the `redirect_file` handler only; reads `sample_rate` from channel config and drops records randomly below the configured rate — allows the high-volume redirect channel to be sampled down without changing log level. |
| `Formatters/KeyValueFormatter.php` | Monolog formatter producing `key=value` text records (as shown in CLAUDE.md). |
| `Processors/RequestContextProcessor.php` | Stamps every log record with `request_id` (and optionally `user_id`, `ip`, `route`) from `RequestContext::current()`. |
| `Processors/PiiRedactionProcessor.php` | Redacts `email` and `ip` values in log records before they reach non-auth/non-audit channels. Applied to all channels except `auth_file` and `audit_file` (which use `:skip-redaction` in `ChannelTap`). The `errors` channel applies redaction even when the source was `auth` or `audit`. |

### Channels

Each domain channel is a `stack` that fans out to its own `_file` channel and the central `errors` channel (which collects all `>= ERROR` level records).

| Channel | Retention | Notes |
|---|---|---|
| `redirect` | 7 days (`LOG_REDIRECT_DAYS`) | Level configurable via `LOG_REDIRECT_LEVEL` (default `info`). Sampling configurable via `LOG_REDIRECT_SAMPLE_RATE` (default 1.0 = no sampling). `SampleRateTap` applied. |
| `tracking` | 14 days (`LOG_TRACKING_DAYS`) | Level configurable via `LOG_TRACKING_LEVEL`. |
| `jobs` | 14 days (`LOG_JOBS_DAYS`) | Level configurable via `LOG_JOBS_LEVEL`. |
| `auth` | 4 days (`LOG_AUTH_DAYS`) | PII redaction **skipped** — raw email and IP preserved for incident response. Level configurable via `LOG_AUTH_LEVEL`. |
| `audit` | 10 days (`LOG_AUDIT_DAYS`) | PII redaction **skipped** — raw email and IP preserved. Level fixed at `info` (not env-configurable). |
| `http` | 14 days (`LOG_HTTP_DAYS`) | Level defaults to `warning` via `LOG_HTTP_LEVEL` — only 4xx (warning) and 5xx (error) requests are logged. |
| `app` | 14 days (`LOG_APP_DAYS`) | Default channel for email, links, analytics, safety events. Level via `LOG_LEVEL`. |
| `errors` | 14 days (`LOG_ERRORS_DAYS`) | Central mirror of all `>= ERROR` records from every domain channel. PII redaction **applied** even to records originating from `auth` or `audit`. |

### `request_id` propagation

1. `AssignRequestId` middleware fires at the start of every HTTP request — generates `req_<16 hex chars>` (or reuses the inbound `X-Request-Id`) and calls `RequestContext::set(new RequestContext(requestId: ..., ...))`.
2. `RequestContextProcessor` (registered via `ChannelTap` on every channel's handler) reads `RequestContext::current()` and stamps each Monolog record with `request_id` (plus `user_id`, `ip`, `route` when available).
3. For queued jobs, `AssignRequestId` does not run. Instead, `RedirectController::dispatchTracking()` injects the current `RequestContext::current()?->requestId` into the job payload under the `request_id` key. The job's `HasLogContext` trait reads it in `pushLogContext()` and calls `RequestContext::set(...)`, restoring the same id for the duration of the job's `handle()`. `popLogContext()` is called in `finally` to clear the context.
4. To correlate all log lines for a single click (HTTP redirect → queued job → tracking service), use: `grep -r 'request_id=req_xy123' backend/storage/logs/`.

## 12. Routes

### routes/web.php

| Method | URI | Name | Action | Middleware (route-specific) |
|---|---|---|---|---|
| GET | `/` | — | Closure (returns API status JSON) | — |
| GET | `/health` | — | Closure (DB + Redis health check, 200/503) | — |
| GET | `/r/{slug}` | `public.redirect` | `RedirectController@redirect` | `throttle:redirect`, `metrics.redirect` |
| GET | `/{slug}` | `public.redirect.clean` | `RedirectController@redirect` | `throttle:redirect`, `metrics.redirect` |

> The `storage/{path}` route (GET and PUT) and the `{fallbackPlaceholder}` catch-all are injected automatically by the Laravel framework — they are not defined in `routes/web.php` and carry no application logic.

### routes/api.php

| Method | URI | Name | Action | Middleware (route-specific) |
|---|---|---|---|---|
| POST | `/api/auth/login` | — | `AuthController@login` | `throttle:login` |
| POST | `/api/auth/register` | — | `AuthController@register` | `throttle:login` |
| POST | `/api/auth/google` | — | `AuthController@googleLogin` | `throttle:login` |
| POST | `/api/auth/verify-email` | — | `AuthController@verifyEmail` | `throttle:login` |
| POST | `/api/auth/forgot-password` | — | `AuthController@forgotPassword` | `throttle:login` |
| POST | `/api/auth/reset-password` | — | `AuthController@resetPassword` | `throttle:login` |
| GET | `/api/me` | — | `AuthController@me` | `api.auth:api` |
| POST | `/api/logout` | — | `AuthController@logout` | `api.auth:api` |
| GET | `/api/email-verification-status` | — | `AuthController@checkEmailVerificationStatus` | `api.auth:api` |
| POST | `/api/resend-verification-email` | — | `AuthController@resendVerificationEmail` | `api.auth:api` |
| PUT | `/api/profile` | — | `AuthController@updateProfile` | `api.auth:api`, `verified` |
| PUT | `/api/change-password` | — | `AuthController@changePassword` | `api.auth:api`, `verified` |
| GET | `/api/links` | — | `LinkController@index` | `api.auth:api`, `verified` |
| POST | `/api/links` | — | `LinkController@store` | `api.auth:api`, `verified` |
| GET | `/api/links/{id}` | — | `LinkController@show` | `api.auth:api`, `verified` |
| PUT | `/api/links/{id}` | — | `LinkController@update` | `api.auth:api`, `verified` |
| DELETE | `/api/links/{id}` | — | `LinkController@destroy` | `api.auth:api`, `verified` |
| GET | `/api/links/{id}/analytics` | — | `AnalyticsController@getLinkLegacyAnalytics` | `api.auth:api`, `verified` |
| POST | `/api/links/batch-meta` | — | `LinkMetaController@batchMeta` | `api.auth:api`, `verified` |
| GET | `/api/links/{id}/sparkline` | — | `LinkMetaController@sparkline` | `api.auth:api`, `verified` |
| GET | `/api/links/{id}/trend` | — | `LinkMetaController@trend` | `api.auth:api`, `verified` |
| GET | `/api/links/{id}/preview` | — | `LinkMetaController@preview` | `api.auth:api`, `verified` |
| GET | `/api/links/{id}/health` | — | `LinkMetaController@health` | `api.auth:api`, `verified` |
| GET | `/api/link/{id}/clicks` | — | `LinkController@getClicksData` | `api.auth:api`, `verified` |
| GET | `/api/link/{id}/clicks-list` | — | `LinkController@getClicksList` | `api.auth:api`, `verified` |
| GET | `/api/analytics/link/{linkId}/dashboard` | — | `AnalyticsController@getLinkDashboardData` | `api.auth:api`, `verified` |
| GET | `/api/analytics/link/{linkId}/comprehensive` | — | `AnalyticsController@getLinkAnalytics` | `api.auth:api`, `verified` |
| GET | `/api/analytics/link/{linkId}/geographic` | — | `AnalyticsController@getGeographicAnalytics` | `api.auth:api`, `verified` |
| GET | `/api/analytics/link/{linkId}/insights` | — | `AnalyticsController@getBusinessInsights` | `api.auth:api`, `verified` |
| GET | `/api/analytics/link/{linkId}/temporal` | — | `AnalyticsController@getTemporalAnalytics` | `api.auth:api`, `verified` |
| GET | `/api/analytics/link/{linkId}/audience` | — | `AnalyticsController@getAudienceAnalytics` | `api.auth:api`, `verified` |
| POST | `/api/public/shorten` | — | `PublicLinkController@store` | `throttle:public-shorten` |
| GET | `/api/public/link/{slug}` | — | `PublicLinkController@showBySlug` | — |
| GET | `/api/public/analytics/{slug}` | — | `PublicLinkController@basicAnalytics` | `throttle:public-analytics` |

> **`/api/r/{slug}` is intentionally DISABLED.** The original AJAX redirect route was decommissioned on 04/11/2025 and preserved as commented code at `routes/api.php` lines 18–32. It **must not** be re-enabled — redirect handling was migrated to `routes/web.php` to support Open Graph previews and direct browser redirects.

## 13. Migrations (chronological — schema is intocável)

### Foundation (Laravel scaffold)

- `0001_01_01_000000_create_users_table.php` — base `users` table (id, name, email, password, remember_token) plus `password_reset_tokens` and `sessions` tables.
- `0001_01_01_000001_create_cache_table.php` — Laravel database `cache` and `cache_locks` tables (unused — Redis is the cache driver).
- `0001_01_01_000002_create_jobs_table.php` — Laravel database `jobs`, `job_batches`, and `failed_jobs` tables (unused — Redis is the queue driver).

### Auth & access tokens (2024–2025)

- `2024_09_18_000001_create_email_verification_tokens_table.php` — `email_verification_tokens` table (token, type, expires_at, used, ip/UA) plus adds `email_verified` and `email_verification_sent_at` columns to `users`.
- `2025_02_24_210902_create_personal_access_tokens_table.php` — Sanctum's `personal_access_tokens` table (currently unused — JWT via `tymon/jwt-auth` is the active auth mechanism).

### Core link & click model (2025-04)

- `2025_04_20_032909_create_links_table.php` — core `links` table (user_id FK, slug unique, original_url, expires_at, is_active).
- `2025_04_20_033001_create_clicks_table.php` — core `clicks` table (link_id FK, ip, user_agent, referer, country, city, device).
- `2025_04_20_033105_create_link_utm_table.php` — `link_utms` table keyed by click_id FK (utm_source, utm_medium, utm_campaign, utm_term, utm_content).
- `2025_04_22_135210_update_links.php` — adds `starts_in` timestamp column to `links` (scheduled activation support).

### Hardening & analytics fields (2025-08)

- `2025_08_17_130755_create_link_audits_table.php` — `link_audits` table (link_id/user_id FKs, action, old_values JSON, new_values JSON, ip/UA); used by `LinkAuditService`.
- `2025_08_17_131403_add_additional_fields_to_links_table.php` — adds `title`, `description`, and UTM parameter columns (`utm_source/medium/campaign/term/content`) to `links`.
- `2025_08_17_151040_add_clicks_column_to_links_table.php` — adds denormalized `clicks` counter (bigInteger, default 0) to `links`; incremented via direct DB query to avoid observer overhead.
- `2025_08_17_205843_add_click_limit_to_links_table.php` — adds nullable `click_limit` integer to `links` (NULL = unlimited).

### Geo + UA enrichment (2025-08–2025-09)

- `2025_08_19_160612_add_detailed_location_fields_to_clicks_table.php` — adds detailed geo fields to `clicks`: `iso_code`, `state`, `state_name`, `postal_code`, `latitude`, `longitude`, `timezone`, `continent`, `currency`; plus three composite indexes.
- `2025_09_11_130817_add_enhanced_tracking_to_clicks_table.php` — adds UA device fields (`browser`, `browser_version`, `os`, `os_version`, `is_mobile/tablet/desktop/bot`), temporal fields (`hour_of_day` through `is_business_hours`), behaviour fields (`is_return_visitor`, `session_clicks`, `click_source`), and performance fields (`response_time`, `accept_language`) to `clicks`.
- `2025_09_14_140000_allow_null_user_id_simple.php` — makes `links.user_id` nullable to support anonymous public shortener (drops and re-adds FK constraint via raw SQL for PostgreSQL compatibility).
- `2025_09_14_140100_add_performance_indexes_simple.php` — adds performance indexes via raw `CREATE INDEX IF NOT EXISTS` SQL: `idx_clicks_link_date`, `idx_clicks_geo`, `idx_clicks_user_agent`, `idx_clicks_referer` on `clicks`; `idx_links_user_active`, `idx_links_expiration` on `links`.

### Email verification fields (2025-09)

- `2025_09_18_114131_add_email_verification_fields_to_users_table.php` — idempotent guard (`Schema::hasColumn`) re-adds `email_verified` and `email_verification_sent_at` to `users` in case the 2024 migration did not run (migration order safety net).

### Health, previews, demo (2026-04)

- `2026_04_27_000001_add_health_to_links_table.php` — adds `health_status` (string, default `'unknown'`) and `health_checked_at` timestamp to `links`; populated by `LinkHealthCheckJob`.
- `2026_04_27_000002_create_link_previews_table.php` — `link_previews` table (link_id PK/FK, favicon_url, og_title, og_image_url, fetched_at); populated by `FetchLinkPreviewJob`.
- `2026_04_30_000001_add_is_demo_to_links_table.php` — adds `is_demo` boolean (default false) to `links`; used by `SeedDemoLinkJob` to mark demo data.

### Click enrichment Phase 1 (2026-05-07)

- `2026_05_07_000001_add_phase1_enrichment_to_clicks_table.php` — adds Sec-Fetch headers (`navigation_context`, `fetch_dest`), Client Hints (`ch_platform`, `ch_is_mobile`), Save-Data (`is_data_saver`), HTTP protocol (`http_protocol`), and language fields (`primary_language`, `language_region`) to `clicks`.

### Click enrichment Phase 2 (2026-05-07)

- `2026_05_07_000002_add_phase2_contextual_to_clicks_table.php` — adds contextual intelligence fields to `clicks`: `is_holiday`, `holiday_name`, `season`, `viral_rank`, `seconds_since_last_click`, `connection_type`, `rendering_engine`.

### Click enrichment Phase 3 (2026-05-07)

- `2026_05_07_000003_add_phase3_quality_to_clicks_table.php` — adds click quality scoring to `clicks`: `quality_score` (0–100), `quality_tier` (string tier label), `fingerprint_score` (consistency heuristic).

> **Migrations are append-only.** Never edit a merged migration. To change a column, write a new migration. Never run `migrate:fresh` in production. See the future `CONTRIBUTING.md`.

## 14. Tests coverage

### Test inventory

| File | Type | Coverage area | Notes |
|---|---|---|---|
| `tests/Feature/RedirectTest.php` | Feature | `/r/{slug}` + `/{slug}` clean alias — bot vs human, 302, 404, expired, inactive, not-yet-started, OG metadata rendering, preview mode, click counter increment, slug cache | **Gating test** — must stay green for any redirect change. |
| `tests/Feature/ProcessLinkClickJobTest.php` | Feature | `ProcessLinkClickJob` — payload deserialization, `Click` record creation, UTM extraction from query string and referer, retry config, job serialization | **Gating test** — must stay green for any tracking change. |
| `tests/Feature/LinkCrudTest.php` | Feature | `LinkController` CRUD — store, index, show, update, destroy; ownership isolation (user A cannot see/edit/delete user B's links) | — |
| `tests/Feature/LinkMetaControllerTest.php` | Feature | `LinkMetaController` — batch-meta returns correct fields for owned links, ignores other-user links, requires auth; sparkline returns N daily points; trend returns correct structure | — |
| `tests/Feature/PublicAnalyticsTest.php` | Feature | `PublicLinkController@basicAnalytics` — browser breakdown, day-of-week distribution (7 entries), 200 for active link with no clicks | — |
| `tests/Feature/Analytics/AnalyticsEndpointsTest.php` | Feature | `AnalyticsController` HTTP layer — 401 without token, 404 for other-user link (all analytics endpoints), 200 for owned link; geographic endpoint shape; removed heatmap endpoint returns 404 | — |
| `tests/Feature/Analytics/AnalyticsStructureTest.php` | Feature | Analytics service internals — click factory day-of-week, `DashboardAnalyticsService` since-filter, `UserAgentParser` (Chrome, Android, language), `TemporalAnalyticsService` advanced keys, `InsightsAnalyticsService` shape, `GeographicAnalyticsService` heatmap, `LinkAnalyticsOrchestrator` top-level keys | — |
| `tests/Feature/Analytics/AudienceAnalyticsServiceEnhancedTest.php` | Feature | `AudienceAnalyticsService` enhanced breakdown — navigation context counts/percentages, return-visitor rate, quality-tier distribution and bot rate; edge cases (empty clicks, null tiers) | — |
| `tests/Feature/Logging/AssignRequestIdMiddlewareTest.php` | Feature | `AssignRequestId` middleware — generates ID when header absent, reuses inbound `X-Request-Id`, populates ip/route in `RequestContext`, clears context after response | — |
| `tests/Feature/ExampleTest.php` | Feature | — | Laravel scaffold — minimal coverage (GET `/` returns 200). |
| `tests/Unit/ExampleTest.php` | Unit | — | Laravel scaffold — minimal coverage (`assertTrue(true)`). |
| `tests/Unit/Logging/KeyValueFormatterTest.php` | Unit | `KeyValueFormatter` — key=value serialisation: simple pairs, quoted strings with spaces, escaped inner quotes, arrays as inline JSON, null omission, timestamp/level/channel, extra fields | — |
| `tests/Unit/Logging/PiiRedactionProcessorTest.php` | Unit | `PiiRedactionProcessor` — redacts sensitive keys, masks email partially, masks IPv4, recurses into nested arrays, processes `extra` field, preserves non-string values | — |
| `tests/Unit/Logging/RequestContextProcessorTest.php` | Unit | `RequestContextProcessor` — injects fields from active context, omits `request_id` when no context, does not overwrite existing `extra` fields | — |
| `tests/Unit/Logging/RequestContextTest.php` | Unit | `RequestContext` value object — `current()` returns null when unset, set/current round-trip, `clear()` resets, set overwrites existing | — |
| `tests/Unit/Logging/SampleRateTapTest.php` | Unit | `SampleRateTap` — rate=0 drops INFO records, rate=0 keeps WARNING/ERROR, rate=1 keeps everything, missing config keeps everything | — |
| `tests/Unit/Services/Links/ClickVelocityServiceTest.php` | Unit | `ClickVelocityService` — `viral_rank` classification (cold/warming/trending/viral based on 5-min and 1-hour click counts), `seconds_since_last_click` computation from previous timestamp | — |
| `tests/Unit/Services/Links/LinkTrackingPhase1Test.php` | Unit | Phase 1 enrichment — `navigation_context` classification from Sec-Fetch headers (browser-direct, referral, in-app-webview, preload, api-programmatic), `is_data_saver` from Save-Data header, language parsing (pt-BR, en, zh-Hant-TW, null) | — |
| `tests/Unit/Services/Links/LinkTrackingPhase2Test.php` | Unit | Phase 2 enrichment — hemisphere-aware `season` (Brazil vs. Germany/US for January and July), `connection_type` classification from ISP name (datacenter, mobile, education, residential, unknown), `rendering_engine` from browser name (Blink/Gecko/WebKit/unknown) | — |
| `tests/Unit/Services/Links/LinkTrackingPhase3Test.php` | Unit | Phase 3 quality scoring — `quality_score` for organic clicks, bot clicks (score=0), datacenter connections, api-programmatic without hints, flood patterns; `fingerprint_score` for ch_is_mobile inconsistency; `quality_tier` mapping | — |

> `tests/Concerns/CreatesTestLinks.php` is a shared trait used by Feature tests to create test link fixtures.

## 15. Backend domain → Frontend feature mapping

| Backend domain (controller / route family) | Frontend feature(s) | Notes |
|---|---|---|
| Auth (`Controllers/Auth/AuthController` — `/api/auth/*`, `/api/me`, `/api/logout`, `/api/profile`, `/api/change-password`, `/api/email-verification-status`, `/api/resend-verification-email`) | `profile` (+ app-wide auth state) | JWT issued by `tymon/jwt-auth` (`dev-chore/laravel-12`). `googleLogin` route exists but the controller method is a stub. |
| Links CRUD (`Controllers/Links/LinkController` — `/api/links/*`, `/api/link/{id}/clicks*`) | `links` | `LinkResource` shape consumed; `/api/link/{id}/clicks` drives real-time clicks component; `/api/link/{id}/clicks-list` drives the ClicksTable tab. |
| Link metadata (`Controllers/Links/LinkMetaController` — `/api/links/batch-meta`, `/api/links/{id}/sparkline\|trend\|preview\|health`) | `links` (list page sparkline + trend), `analytics` (preview/health) | Response fields locked: `sparkline`, `trend`, `preview`, `health`. |
| Public shortener (`Controllers/Links/PublicLinkController` — `/api/public/*`) | `shorter`, `public-analytics` | Rate limited by `throttle:public-shorten` (store) and `throttle:public-analytics` (basicAnalytics). `showBySlug` has no rate limit. |
| Redirect (`Controllers/Links/RedirectController` — `/r/{slug}`, `/{slug}`) | `redirect` (and direct browser hits, plus bot OG previews) | Web routes only (not API). Rate limited by `throttle:redirect`. `/{slug}` clean-URL alias is intended for production domain `redirect.linkcharts.com.br`. |
| Analytics (`Controllers/Analytics/AnalyticsController` — `/api/analytics/link/{linkId}/*`, `/api/links/{id}/analytics`) | `analytics`, `public-analytics` | Heatmap endpoint removed (returns 404 per `AnalyticsEndpointsTest`). `/api/links/{id}/analytics` is a legacy endpoint preserved for backwards compatibility. |

> The mapping above is by convention, not enforced by the framework — any frontend component can call any backend endpoint. Changes to response shapes in the backend must be coordinated with the corresponding frontend feature directory.

## 16. Oportunidades de refactor

> Each item lists what to do, why, risk level, and the call-site footprint.
> **Phase 2 executes only items the human approves.** Items marked `alto` require explicit secondary confirmation per the plan's Hard Rules.

### Baixo risco

#### R-01 — Delete `LinkService::processRedirect` and `LinkRepository::incrementClicks` (verified dead)

- **What:** delete `LinkService::processRedirect` (line 97), `LinkRepository::incrementClicks` (line ~115), and the matching contract entries `LinkServiceInterface::processRedirect` and `LinkRepositoryInterface::incrementClicks`.
- **Rationale:** zero callers in `app/`, `tests/`, or `routes/` (verified via grep). The live counter increment happens exclusively in `LinkTrackingService::registrarCliqueFromPayload` via `DB::table('links')->increment`. Both methods exist only because of a prior redirect architecture that was decommissioned when `/api/r/{slug}` was disabled on 04/11/2025.
- **Risk:** baixo. No callers, no tests exercise these methods, no contract consumer depends on them.
- **Impact:** 4 files (`app/Services/Links/LinkService.php`, `app/Contracts/Services/LinkServiceInterface.php`, `app/Repositories/LinkRepository.php`, `app/Contracts/Repositories/LinkRepositoryInterface.php`). 0 call-site updates needed. Reversible from git history.

---

#### R-02 — Delete `WordRepository.php` (broken + orphan)

- **What:** delete `app/Repositories/WordRepository.php`. Also run `grep -rn "Word\b" database/ app/` before deletion to confirm no seed/factory references exist.
- **Rationale:** imports `App\Models\Word` which does not exist — the class will fatal on any instantiation. Zero callers in `app/`, `routes/`, or `tests/`. No contract, not bound in the service container.
- **Risk:** baixo. Fatal class cannot be in use; deletion is safe.
- **Impact:** 1 file deleted. Reversible from git history.

---

#### R-03 — Delete `ChartRepository.php` (orphan)

- **What:** delete `app/Repositories/ChartRepository.php`. Verify once more with `grep -rn "ChartRepository" app/ routes/ tests/` before deletion.
- **Rationale:** zero callers in `app/`, `routes/`, or `tests/`. No contract, not bound in the service provider. Was likely a legacy analytics utility that was superseded by the domain analytics services (`DashboardAnalyticsService`, `GeographicAnalyticsService`, etc.).
- **Risk:** baixo (verify with one grep immediately before deletion).
- **Impact:** 1 file deleted. Reversible from git history. Note: if R-03 is approved, item R-19 (covering index for `ChartRepository::clicksByCampaign`) becomes moot.

---

#### R-04 — Delete `LinkDTO.php` (superseded + orphan)

- **What:** delete `app/DTOs/LinkDTO.php`.
- **Rationale:** zero callers in `app/` or `tests/`. Every use site uses `CreateLinkDTO`, `UpdateLinkDTO`, or `CreatePublicLinkDTO` instead. `LinkDTO` covers only 7 of the 20+ `Link` model fields, and the API layer uses `LinkResource` for output — `LinkDTO::fromModel` has no functional role.
- **Risk:** baixo. Zero callers; no tests exercise it.
- **Impact:** 1 file deleted. Reversible from git history.

---

#### R-05 — Delete `MetricsController.php` (orphan, never wired)

- **What:** delete `app/Http/Controllers/MetricsController.php`.
- **Rationale:** no route in `routes/api.php` or `routes/web.php` mounts any of its 5 actions. The class PHPDoc describes it as a planned "consolidation" that was never integrated. `MetricsService` (which it injects) is fully used by `LinkMetaController` — the consolidation intent is already served by an existing routed controller.
- **Risk:** baixo. No route, no callers, no tests.
- **Impact:** 1 file deleted. Reversible from git history.

---

#### R-06 — Delete orphan methods `LinkController::auditHistory` and `LinkController::showBySlug`

- **What:** delete the method bodies for `auditHistory` (line 433) and `showBySlug` (line 476) from `app/Http/Controllers/Links/LinkController.php`.
- **Rationale:** `auditHistory` has no matching route (audit history is served by `LinkAuditService::getLinkHistory` only when called internally by `LinkController` itself, not via this action). `showBySlug` is duplicated by the actively routed `PublicLinkController::showBySlug`. Leaving dead controller actions creates a misleading surface area during future route additions.
- **Risk:** baixo. No routes mount them, no tests call them.
- **Impact:** 1 file (`app/Http/Controllers/Links/LinkController.php`). 0 call-site updates. Reversible from git history.

---

#### R-07 — Delete `UpdateExistingLinksUrls` artisan command (scaffold stub, never implemented)

- **What:** delete `app/Console/Commands/UpdateExistingLinksUrls.php`.
- **Rationale:** `handle()` is empty, description is the Laravel scaffold placeholder `"Command description"`. This is either an abandoned one-shot migration or a dead placeholder that was never implemented. There is no Artisan registration outside of auto-discovery, and it has no callers.
- **Risk:** baixo. Empty command with no business logic.
- **Impact:** 1 file deleted. Reversible from git history.

---

#### R-08 — Remove unused `metrics.collector` middleware alias from `bootstrap/app.php`

- **What:** remove the alias registration for `metrics.collector` (lines 47–52 of `bootstrap/app.php`). Keep `metrics.redirect` — it is actively applied on both redirect routes.
- **Rationale:** the alias exists but `MetricsCollector` is not applied to any route. Leaving the alias in place creates an implicit contract that it "should" be used somewhere, and it imports the class even when it is never invoked.
- **Risk:** baixo. No routes reference `metrics.collector`.
- **Impact:** 1 file (`bootstrap/app.php`). Reversible from git history.

---

#### R-10 — Replace direct `Log::*` calls with `AppLogger::*` (CLAUDE.md convention)

- **What:** in `app/Services/Links/ClickVelocityService.php:44`, replace `Log::warning(...)` with an appropriate `AppLogger::event('app', 'warning', 'click_velocity.redis_unavailable', [...])` call (or a new semantic method if this event warrants one). In `app/Http/Middleware/MetricsCollector.php` and `app/Http/Middleware/RedirectMetricsCollector.php`, replace all `Log::debug(...)` calls with `AppLogger::event(...)` or suppress them (these middlewares are debug-only calls; see R-08 for `MetricsCollector` itself).
- **Rationale:** CLAUDE.md explicitly prohibits direct `Log::*` usage. Three files violate this convention. `ClickVelocityService` is on the hot path (Redis pipeline).
- **Risk:** baixo. Pure logging replacement; no logic changes.
- **Impact:** 3 files (`app/Services/Links/ClickVelocityService.php`, `app/Http/Middleware/MetricsCollector.php`, `app/Http/Middleware/RedirectMetricsCollector.php`). 0 functional call-site changes.

---

#### R-11 — Extract shared `extractPrimaryLanguage` into `UserAgentParser` (DRY)

- **What:** confirm `UserAgentParser::extractPrimaryLanguage` is the canonical implementation. Remove the duplicate private `extractPrimaryLanguage` method from `DashboardAnalyticsService` and inject `UserAgentParser` into `DashboardAnalyticsService` (add constructor parameter). Update the 1 call-site inside `DashboardAnalyticsService` to use the injected parser.
- **Rationale:** the same language-name mapping array is duplicated in two files. Any update to the mapping (e.g., adding a new language code) must be made in both places — a maintenance hazard.
- **Risk:** baixo. `DashboardAnalyticsService` already has no constructor; adding a constructor-injected `UserAgentParser` (which has no dependencies itself) is a clean change. `AppServiceProvider` already binds `DashboardAnalyticsInterface` — adding `UserAgentParser` to its constructor is auto-resolved by the container.
- **Impact:** 2 files (`app/Services/Analytics/DashboardAnalyticsService.php`, `app/Services/Analytics/Support/UserAgentParser.php`). 0 external call-site changes (the interface `DashboardAnalyticsInterface` is unchanged).

---

#### R-12 — Replace `app()->make(ClickVelocityService::class)` with constructor injection in `LinkTrackingService`

- **What:** add `ClickVelocityService $clickVelocityService` to `LinkTrackingService::__construct`, remove the `app()->make(ClickVelocityService::class)` service-locator call inside `registrarCliqueFromPayload`, and use the injected dependency instead.
- **Rationale:** service-locator pattern (`app()->make(...)`) bypasses the DI container's visibility, makes dependencies implicit, and prevents the test double from being injected cleanly. `LinkTrackingService` is instantiated by `ProcessLinkClickJob` via `new LinkTrackingService()` (check `ProcessLinkClickJob::handle` before applying).
- **Risk:** baixo — but verify: if `ProcessLinkClickJob` calls `new LinkTrackingService()` directly (not via container), constructor injection requires updating the job to resolve via container or pass `ClickVelocityService` explicitly. If resolved via `app()->make(LinkTrackingService::class)`, no change is needed in the job.
- **Impact:** 2 files (`app/Services/Links/LinkTrackingService.php`, possibly `app/Jobs/ProcessLinkClickJob.php`). Reversible from git history.

---

#### R-13 — Introduce a `LinkAnalyticsOrchestratorInterface` contract

- **What:** create `app/Contracts/Analytics/LinkAnalyticsOrchestratorInterface.php` exposing the 6 public methods. Bind it in `AppServiceProvider::register()`. Update `AnalyticsController` to type-hint the interface instead of the concrete class.
- **Rationale:** `LinkAnalyticsOrchestrator` is the only analytics orchestration class not behind an interface, making `AnalyticsController` the only analytics controller that injects a concrete. Inconsistent with the rest of the analytics layer and blocks test substitution.
- **Risk:** baixo. Purely additive — new interface, new binding, controller type-hint change only.
- **Impact:** 3 files (new `app/Contracts/Analytics/LinkAnalyticsOrchestratorInterface.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Analytics/AnalyticsController.php`).

---

#### R-14 — Add PHP type hints to `EmailService` public method parameters

- **What:** add parameter type hints and return types to all 8 public methods in `app/Services/EmailService.php`. The types are inferrable from usage and internal doc comments.
- **Rationale:** `EmailService` is the only service in the codebase where public method parameters are entirely untyped. This is inconsistent with the rest of the codebase and was flagged in the audit.
- **Risk:** baixo. Type hints are additive; no behavior changes. Existing callers pass strings — no incompatibility.
- **Impact:** 1 file (`app/Services/EmailService.php`). Callers in `EmailVerificationService` will benefit from IDE-checked types.

---

#### R-20 — Rewrite misleading docblock above `RedirectController::dispatchTracking()` (Phase 3 candidate)

- **What:** fix the docblock at line 117 of `app/Http/Controllers/Links/RedirectController.php` which says "increment the denormalised click counter directly via DB" — the method only dispatches `ProcessLinkClickJob`; the counter increment happens inside the job.
- **Rationale:** the misleading comment will cause future developers to misunderstand the click-tracking flow and look in the wrong place when debugging counter discrepancies.
- **Risk:** baixo. Documentation-only change; zero functional impact.
- **Impact:** 1 file (`app/Http/Controllers/Links/RedirectController.php`). Listed here for completeness; scheduled for Phase 3 PHPDoc pass.

---

#### R-21 — Clean up the 31 phpstan baseline errors

- **What:** fix the 31 real errors reported across 16 files (see Section 15 — phpstan baseline state). Most are in `nullsafe.neverNull`, `property.notFound` on `Click::$clicks`, `notIdentical.alwaysTrue`, and stale ignore patterns.
- **Rationale:** the phpstan baseline was pruned of stale entries in commit `3fbf7c0`; the remaining 31 errors are real and can mask new regressions. Resolving them tightens the static analysis net.
- **Risk:** baixo per error (most are nullsafe / type annotation fixes), but the volume (~16 files) means meaningful time. Suggest deferring to a dedicated post-plan PR rather than folding into Phase 2.
- **Impact:** 16 files across controllers, services, models. No behavioral changes expected.

---

#### R-MASS-PINT — Run `vendor/bin/pint` over `app/` and `tests/` in an isolated commit

- **What:** run `./vendor/bin/pint app/ tests/` and commit any formatting changes in a single isolated commit with no other changes mixed in.
- **Rationale:** Pint is installed and the codebase was normalized in commit `1ec4533` but has since received new files. Running Pint idempotently confirms formatting consistency or surfaces new violations for review.
- **Risk:** baixo. Formatting-only; CI-safe.
- **Impact:** unknown until run — likely a no-op or near-no-op. If not, that itself is a finding.

---

### Médio risco

#### R-09 — Fix `AuthController::googleLogin` — implement or remove (product decision required)

- **What:** the route `POST /api/auth/google` is declared in `routes/api.php:55` and points to `AuthController@googleLogin`, but the method does not exist in `app/Http/Controllers/Auth/AuthController.php`. Any request to this route will throw a `BadMethodCallException` at runtime.
- **Decision needed:** either (a) implement `googleLogin` using a Google OAuth flow, or (b) remove the route from `routes/api.php` and remove any frontend reference to `POST /api/auth/google`. **Phase 2 cannot resolve this without a product direction call.**
- **Rationale:** this is a runtime bug, not a cleanup. If (b), the frontend's Google login button (if any) must also be removed or disabled.
- **Risk:** médio — affects the auth surface; needs the human to decide direction (implement vs. remove).
- **Impact:** at minimum 1 file (`routes/api.php`); if implemented, also `app/Http/Controllers/Auth/AuthController.php` plus any OAuth config.

---

#### R-15 — Refactor `InsightsAnalyticsService` to resolve generators via container

- **What:** instead of constructing all 8 generators inline (`new GeographicInsightGenerator`, etc.), accept either a tagged collection from the container (register all generators in `AppServiceProvider` with a tag, inject via `$app->tagged(...)`) or an `InsightGeneratorRegistry` pre-populated by the provider.
- **Rationale:** generators constructed inline are non-substitutable for testing and cannot be swapped via the service container. This makes `InsightsAnalyticsService` untestable in isolation.
- **Risk:** médio — changes the seam between `InsightsAnalyticsService` and its generators; requires updating `AppServiceProvider` bindings and potentially the `InsightsAnalyticsInterface` contract (if the interface is unchanged, this is transparent to callers). Needs test coverage update.
- **Impact:** 3 files (`app/Services/Analytics/InsightsAnalyticsService.php`, `app/Providers/AppServiceProvider.php`, possibly `app/Services/Analytics/Insights/InsightGeneratorRegistry.php`). Existing tests in `AnalyticsStructureTest` exercise the final output shape — those should remain green.

---

#### R-16 — Fix `MetricsService::clearUserMetricsCache` glob no-op

- **What:** `Cache::forget("metrics:user:{$userId}:*")` is a no-op for any cache driver that is not Redis with pattern-delete support. Options: (a) switch the cache store for metrics keys to a Redis store and use `Cache::store('redis')->connection()->del(...)` with a pattern scan, or (b) enumerate the 6–8 specific cache keys that `MetricsService` writes and call `Cache::forget` for each of them explicitly.
- **Rationale:** the current method silently does nothing — a user clearing their metrics cache via any route that calls this method gets no actual cache invalidation. Option (b) is safer: no Redis-specific dependency, no glob, deterministic.
- **Risk:** médio — touches cache invariants; any change to metrics cache keys in the future must also be reflected in the explicit forget list. A missed key = stale cache.
- **Impact:** 1 file (`app/Services/Analytics/MetricsService.php`). No external callers beyond `LinkMetaController::batchMeta` (indirectly) and `MetricsController::clearCache` (orphan — see R-05).

---

#### R-17 — Re-enable TLS verification in `LinkPreviewService` Guzzle client

- **What:** change `'verify' => false` to `'verify' => true` (or remove the key — Guzzle defaults to `true`) in `app/Services/Links/LinkPreviewService.php`'s Guzzle client constructor. Similarly, `LinkHealthCheckJob` also disables SSL verification for `HEAD` requests.
- **Rationale:** disabling TLS verification (`'verify' => false`) means man-in-the-middle attacks against OG metadata fetches are undetected. A malicious response could return arbitrary metadata for a shortened link's preview. This is a security concern.
- **Risk:** médio — re-enabling `verify` means some destination URLs with broken or self-signed certificates will fail to fetch a preview (they will return empty metadata instead of potentially spoofed metadata). This is the safer behavior, but it may noticeably affect preview success rate. Needs a product call if preview success rate is tracked.
- **Impact:** 2 files (`app/Services/Links/LinkPreviewService.php`, `app/Jobs/LinkHealthCheckJob.php`).

---

### Alto risco

#### R-18 — Harden `EmailVerificationToken` token invalidation against race condition

- **What:** the static methods `createEmailVerificationToken` and `createPasswordResetToken` perform a non-atomic `UPDATE` (mark old tokens as used) followed by an `INSERT` (create a new token). Under concurrent identical requests (e.g., double-tap on "resend verification"), both requests can read "no valid token found", each issue their own `INSERT`, and the user ends up with two valid tokens.
- **Recommended approach:** wrap the two statements in `DB::transaction(...)` with an explicit table lock, or rewrite as a single `INSERT ... ON CONFLICT (email, type) DO UPDATE` with a unique constraint on `(email, type, used)` — but PostgreSQL partial index semantics make this complex. The simplest safe fix is `DB::transaction()` with `NOWAIT` lock escalation.
- **Risk:** alto — touches the security-critical email verification and password-reset auth flows. Requires careful testing under load and review of the token table schema. **Explicitly requires secondary approval before Phase 2 execution.**
- **Impact:** 1 file (`app/Models/EmailVerificationToken.php`). Integration tests for concurrent token creation would also be needed.

---

### Condicional

#### R-19 — Add covering composite index for `ChartRepository::clicksByCampaign` join

- **What:** add a migration that creates a composite index on `link_utms` covering `(click_id, utm_campaign)` to support the three-table join in `ChartRepository::clicksByCampaign`.
- **Rationale:** the join path `link_utms → clicks → links` has no dedicated covering index; the query forces a full scan of `link_utms` for user-scoped aggregation.
- **Conditional:** this item is **moot if R-03 is approved** — `ChartRepository` is an orphan class that would be deleted. List it here only as a finding for the record. If `ChartRepository` is retained, treat this as baixo-risk (additive migration).
- **Impact:** 1 new migration file. Conditional on R-03 disposition.

---

## 17. Suspeitos de código órfão

> Items in this section are *suspected* orphans with less than 100 % confidence, or where deletion requires side-effect investigation that is not yet complete. Confirmed orphans are listed in Section 16 (R-01 through R-08).

| Suspect | Evidence | Confidence | Recommended action |
|---|---|---|---|
| `BaseController::serverError` | Declared `protected` — not visible to external callers. Used by subclasses; check all controllers that extend `BaseController` before concluding it is unused within the class hierarchy. | Suspect only — method is `protected` and used internally in error paths. | Verify callers with `grep -rn "serverError" app/Http/Controllers/` before any action. Keep unless zero hits. |
| `BaseController::linkNotFound` | Same as above — `protected` helper. | Suspect only. | Same: verify with grep. |
| `MetricsController` (5 actions: `getDashboardMetrics`, `getMetricsByCategory`, `getLinkMetrics`, `compareMetrics`, `clearCache`) | No routes. The class itself is a confirmed orphan (see R-05 in Section 16). Individual action methods are listed here as suspects in case any of them are also referenced as static helpers or in Artisan commands before deletion is approved. | High (confirmed orphan class) | Delete entire class if R-05 is approved. No per-method analysis needed since the class itself is unrouted. |
| `LinkTrackingService::resolveRealUserIP` | Method is called from `RedirectController` before dispatching the job (Section 2 notes this). Appears active — not an orphan. Listed here because it duplicates logic in `RedirectMetricsCollector::getRealUserIP`. | Low suspicion (likely active) | Not an orphan. Consider extracting shared IP-resolution logic into a standalone helper (not in scope for Phase 2). |
| `EmailService::testConnection` | Sends a real email to `test@example.com` when called. Likely used only by `TestEmailCommand`. No other callers found in `app/`. | Suspect — may be production-only CLI utility | Verify with `grep -rn "testConnection" app/` — if sole caller is `TestEmailCommand`, the method is intentionally kept for ops use. Keep. |
| `EmailService::getSendGridConfiguration` and `getMailConfiguration` | Pure read-only helpers. Called from `TestEmailCommand`. No evidence of controller or service callers. | Suspect — may be ops-only | Same: verify, likely keep as intentional ops utilities. |
| `Link::getShortedUrl()` | Helper method on `Link` model. Verify with `grep -rn "getShortedUrl\|shortedUrl" app/ tests/`. If unused, it is a naming anti-pattern (`shorted` is not standard English) and a deletion candidate. | Suspect — name is non-standard, usage unknown | Run grep before Phase 2; delete if zero callers. |
| `CreateLinkDTO::toArray` filtering behavior (zero/empty string stripping) | `array_filter(..., fn ($value) => $value !== null)` will preserve `false` but strip `0` and `""`. This may silently drop valid falsy values. Not an orphan but a logic suspect. | Bug suspect, not orphan | Flag for review during Phase 3 PHPDoc pass; add a comment at minimum. |
| `UpdateExistingLinksUrls` command `handle()` body | Confirmed empty placeholder (see R-07 in Section 16). | High (confirmed orphan command) | Delete if R-07 is approved. |
| `TemporalAnalyticsService::getAdvancedTemporalAnalytics` full-load | Loads all clicks for a link into PHP memory (`Click::where(...)->get()`). For high-traffic links, this is a memory/performance hazard, not a dead code issue. | Not an orphan — active code with a performance concern | Flag for future optimization. Not in scope for Phase 2. |
| `InsightGeneratorInterface::generate` and all 8 generators | All 8 generators are constructed inline (see R-15). The interface and generators are active, but the inline construction pattern makes them effectively non-substitutable. Not dead — active on the insights endpoint. | Not orphans — active code | Addressed by R-15. |

---

## 18. Estado do PHPDoc

> Heuristic: counted `public function` declarations vs `*/` (closing docblock) lines per file. Files where the gap exceeds 1 likely have undocumented public methods. Manual verification required in Phase 3 because `*/` also closes inline block comments.

### Controllers

| File | pubs | docs | gap | Notes |
|---|---|---|---|---|
| `app/Http/Controllers/Auth/AuthController.php` | 13 | 12 | 1 | One public method (likely `googleLogin` stub) lacks a docblock. |
| `app/Http/Controllers/Links/LinkMetaController.php` | 6 | 5 | 1 | One method undocumented. |
| `app/Http/Controllers/Analytics/AnalyticsController.php` | 8 | 9 | 0 | Well covered. |
| `app/Http/Controllers/Links/LinkController.php` | 10 | 10 | 0 | Well covered. |
| `app/Http/Controllers/Links/PublicLinkController.php` | 4 | 4 | 0 | Well covered. |
| `app/Http/Controllers/Links/RedirectController.php` | 2 | 4 | 0 | Extra `*/` from block comments; well covered. |
| `app/Http/Controllers/MetricsController.php` | 6 | 6 | 0 | Fully documented (orphan class — see R-05). |

**Controller layer:** mostly well-documented. Two files have a gap of 1; neither is critical for Phase 3.

---

### Services

| File | pubs | docs | gap | Notes |
|---|---|---|---|---|
| `app/Services/Analytics/LinkAnalyticsOrchestrator.php` | 7 | 0 | **7** | No docblocks at all. Highest-priority Phase 3 target. |
| `app/Services/Analytics/Support/UserAgentParser.php` | 3 | 0 | **3** | No docblocks. |
| `app/Services/Analytics/Insights/InsightGeneratorRegistry.php` | 2 | 0 | **2** | No docblocks. |
| `app/Services/Analytics/GeographicAnalyticsService.php` | 1 | 0 | 1 | No docblock on sole public method. |
| `app/Services/Links/LinkPreviewService.php` | 2 | 1 | 1 | One of two public methods undocumented. |
| `app/Services/Links/LinkSafetyService.php` | 1 | 0 | 1 | No docblock on sole public method. |
| `app/Services/Onboarding/OnboardingDemoDataService.php` | 1 | 0 | 1 | No docblock on sole public method. |
| `app/Services/Analytics/Insights/InsightGeneratorInterface.php` | 1 | 0 | 1 | Interface contract method undocumented. |
| `app/Services/Analytics/Insights/Generators/DeviceInsightGenerator.php` | 1 | 0 | 1 | (×8 generators — same pattern for all 8) |
| `app/Services/Links/LinkService.php` | 9 | 9 | 0 | Well covered. |
| `app/Services/EmailService.php` | 8 | 10 | 0 | Well covered. |
| `app/Services/Analytics/MetricsService.php` | 10 | 20 | 0 | Extra `*/` from section comments; well covered. |
| `app/Services/Links/LinkTrackingService.php` | 2 | 9 | 0 | Extra `*/` from phase headers; well covered. |

**Service layer:** the analytics sub-layer is the weakest. `LinkAnalyticsOrchestrator` (7 undocumented public methods) and the 8 insight generators (each with 0 docblocks) account for the majority of the PHPDoc deficit. Phase 3 should prioritize this layer.

---

### Repositories

| File | pubs | docs | gap | Notes |
|---|---|---|---|---|
| `app/Repositories/ChartRepository.php` | 13 | 13 | 0 | Well covered (orphan — see R-03). |
| `app/Repositories/LinkRepository.php` | 8 | 9 | 0 | Well covered. |
| `app/Repositories/WordRepository.php` | 5 | 5 | 0 | Fully documented (orphan — see R-02). |

**Repository layer:** no PHPDoc gap. Both orphan repositories happen to be well-documented — another sign they were never completed or tested rather than gradually abandoned.

---

### Models

| File | pubs | docs | gap | Notes |
|---|---|---|---|---|
| `app/Models/Link.php` | 7 | 0 | **7** | No docblocks at all. Highest-priority model target for Phase 3. |
| `app/Models/Click.php` | 2 | 1 | 1 | One method undocumented. |
| `app/Models/LinkPreview.php` | 1 | 0 | 1 | No docblock. |
| `app/Models/LinkUtm.php` | 1 | 0 | 1 | No docblock. |
| `app/Models/User.php` | 8 | 10 | 0 | Well covered. |
| `app/Models/EmailVerificationToken.php` | 3 | 8 | 0 | Well covered (extra `*/` from helper comments). |
| `app/Models/LinkAudit.php` | 2 | 4 | 0 | Well covered. |

**Model layer:** `Link` is the critical gap — 7 public methods with zero docblocks. Given that `Link` is the core domain model (cache helpers, expiry, click-limit logic), Phase 3 must document it first.

---

### Jobs

| File | pubs | docs | gap | Notes |
|---|---|---|---|---|
| `app/Jobs/FetchLinkPreviewJob.php` | 2 | 0 | **2** | No docblocks. |
| `app/Jobs/LinkHealthCheckJob.php` | 1 | 0 | 1 | No docblock. |
| `app/Jobs/ProcessLinkClickJob.php` | 2 | 3 | 0 | Well covered. |
| `app/Jobs/SeedDemoLinkJob.php` | 3 | 3 | 0 | Well covered. |

**Job layer:** `FetchLinkPreviewJob` and `LinkHealthCheckJob` are undocumented. Phase 3 should cover these in the same pass as models.

---

### DTOs

| File | pubs | docs | gap | Notes |
|---|---|---|---|---|
| `app/DTOs/CreateLinkDTO.php` | 3 | 4 | 0 | Well covered. |
| `app/DTOs/CreatePublicLinkDTO.php` | 4 | 5 | 0 | Well covered. |
| `app/DTOs/LinkDTO.php` | 2 | 4 | 0 | Well covered (orphan — see R-04). |
| `app/DTOs/UpdateLinkDTO.php` | 4 | 5 | 0 | Well covered. |

**DTO layer:** no PHPDoc gap.

---

### PHPDoc summary by layer

The **analytics services sub-layer** (particularly `LinkAnalyticsOrchestrator`, the 8 insight generators, and `UserAgentParser`) and the **`Link` model** are the most underdocumented areas of the codebase. Controllers and DTOs are in good shape. Phase 3 should prioritize in this order: (1) `Link` model, (2) `LinkAnalyticsOrchestrator`, (3) the 8 generators and `InsightGeneratorRegistry`, (4) the remaining undocumented services (`GeographicAnalyticsService`, `LinkPreviewService`, `LinkSafetyService`, `OnboardingDemoDataService`), (5) jobs (`FetchLinkPreviewJob`, `LinkHealthCheckJob`).

---

## 19. Resumo executivo

- **Snapshot:** the backend has 8 controllers (7 routed + 1 orphan `MetricsController`), ~15 services (spanning links, analytics, email, onboarding, and velocity tracking), 3 repositories (1 active, 2 orphan), 4 jobs, 4 DTOs (1 orphan), and 22 migrations. The layered architecture (Controllers → Services → Repositories → Models, with Contracts at the seam) is well-respected across the active code surface. PHPUnit coverage exists for the two most critical paths (redirect + click tracking) and for the core analytics endpoints.

- **Top 3 must-fix findings:**
  1. **`AuthController::googleLogin` is a ghost route** — `POST /api/auth/google` is registered in `routes/api.php` but the method does not exist in the controller. Any invocation throws a `BadMethodCallException`. This is a live bug in the auth surface. Requires a product decision: implement or remove (R-09).
  2. **`WordRepository` will fatal on any call** — it imports `App\Models\Word` which does not exist. The class cannot be instantiated. Zero callers, but its presence in the codebase creates confusion. Delete (R-02).
  3. **TLS verification is disabled in `LinkPreviewService`** — `'verify' => false` in the Guzzle client allows MITM attacks against OG metadata fetches. `LinkHealthCheckJob` has the same issue. Re-enable (R-17), pending a product call on acceptable preview failure rate for sites with broken certs.

- **Top 3 high-value cleanup opportunities:**
  1. **Delete all confirmed dead code in one batch** (R-01 through R-08): 6 dead files / methods, 2 interface entries, 1 bootstrap alias. Zero call-site updates needed. This removes ~450 lines of misleading code from the active surface.
  2. **Centralize logging via AppLogger** (R-10): 3 files use `Log::*` directly in violation of CLAUDE.md. Replacing them closes the logging convention gap and ensures Redis-downtime events in `ClickVelocityService` are correlated by `request_id`.
  3. **Tighten phpstan baseline** (R-21): 31 real errors across 16 files represent the largest static-analysis debt. Resolving them makes future type regressions detectable.

- **PHPDoc gap by layer:** analytics services (especially `LinkAnalyticsOrchestrator`, 8 generators) and the `Link` model are the most underdocumented — combined gap of ~18 undocumented public methods. Controllers and DTOs are well-covered. Phase 3 should begin with `Link` and `LinkAnalyticsOrchestrator`.

- **Surprising findings during audit:**
  - `MetricsService::clearUserMetricsCache` is effectively a no-op: `Cache::forget` with a glob pattern silently does nothing on non-Redis cache drivers (R-16). This means any feature that allows users to "refresh" their metrics cache is currently broken.
  - `ChartRepository` is a fully-documented, well-indexed 13-method class with absolutely zero callers anywhere in the codebase. It was likely the original analytics layer, entirely superseded by the domain analytics services, but never deleted.
  - The two orphan repositories (`ChartRepository` and `WordRepository`) happen to have complete PHPDoc coverage — suggesting they were carefully written and then abandoned rather than gradually decaying.
  - `resendVerificationEmail` sits in the `api.auth:api`-only group with no rate limit, while all other auth-adjacent endpoints are under `throttle:login` (5/min). This means an authenticated user with an unverified email can hammer the resend endpoint without limit.

- **Recommended Phase 2 execution order (baixo-risk batch):**
  The following items can be batched together in Phase 2 with no cross-item dependencies and no call-site updates required from external code:
  1. **R-02** — Delete `WordRepository.php` (fatal on instantiation; zero callers)
  2. **R-04** — Delete `LinkDTO.php` (zero callers)
  3. **R-07** — Delete `UpdateExistingLinksUrls` command (empty stub)
  4. **R-05** — Delete `MetricsController.php` (unrouted; zero callers) — approve R-03 first to confirm `ChartRepository` (its implicit sibling) is also gone
  5. **R-03** — Delete `ChartRepository.php` (verify one grep first)
  6. **R-08** — Remove `metrics.collector` alias from `bootstrap/app.php`
  7. **R-06** — Remove orphan methods from `LinkController` (`auditHistory`, `showBySlug`)
  8. **R-01** — Remove `processRedirect` / `incrementClicks` from service + repository + interfaces
  9. **R-10** — Replace `Log::*` with `AppLogger::*` in 3 files
  10. **R-11** — Extract shared `extractPrimaryLanguage` (DRY)
  11. **R-13** — Introduce `LinkAnalyticsOrchestratorInterface`
  12. **R-14** — Add type hints to `EmailService`
  13. **R-12** — Replace service-locator in `LinkTrackingService` (verify job instantiation pattern first)
  14. **R-MASS-PINT** — Run Pint in an isolated commit after all functional changes above
  Medium-risk items (R-15, R-16, R-17) and the alto-risk item (R-18) require explicit approval each, per the plan's Hard Rules.

- **Open questions for the human (approval gate):**
  1. **R-09 — `googleLogin`:** should the Google OAuth flow be implemented, or should the route and any frontend reference be removed? This is the only finding that requires a product direction call before Phase 2 can proceed on auth.
  2. **R-17 — TLS verify:** should TLS verification be re-enabled in `LinkPreviewService` and `LinkHealthCheckJob`, accepting that some sites with broken or self-signed certificates will no longer produce previews?
  3. **R-15 — Generator injection:** is it acceptable to refactor `InsightsAnalyticsService` to resolve generators via the container (changes the DI seam and requires updating `AppServiceProvider`)? This enables generator-level unit testing but adds provider boilerplate.
  4. **R-16 — Metrics cache:** should option (a) (Redis-tagged store) or option (b) (explicit key enumeration) be used to fix `clearUserMetricsCache`?
  5. **R-18 — Race condition:** is hardening `EmailVerificationToken` invalidation against concurrent requests (race condition in token creation) in scope for Phase 2, given that it requires careful transaction testing on the security-critical auth flow?
  6. **`resendVerificationEmail` rate limit gap:** the endpoint sits in the `api.auth:api` group with no `throttle:login`, unlike every other auth endpoint. Should a rate limit be added (not currently in the refactor list — would become R-22)?
