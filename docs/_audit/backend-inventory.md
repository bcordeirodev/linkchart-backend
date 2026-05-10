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

_To be filled in Task 1.4._

## 4. Contracts

_To be filled in Task 1.4._

## 5. DTOs

_To be filled in Task 1.5._

## 6. Models (and Observers)

_To be filled in Task 1.5._

## 7. Jobs

_To be filled in Task 1.6._

## 8. Middlewares

_To be filled in Task 1.7._

## 9. Providers / Bindings

_To be filled in Task 1.7._

## 10. Console (Commands + Schedule)

_To be filled in Task 1.7._

## 11. Logging

_To be filled in Task 1.7._

## 12. Routes

_To be filled in Task 1.8._

## 13. Migrations (chronological — schema is intocável)

_To be filled in Task 1.8._

## 14. Tests coverage

_To be filled in Task 1.9._

## 15. Backend domain → Frontend feature mapping

_To be filled in Task 1.9._

## 16. Oportunidades de refactor

_To be filled in Task 1.10._

## 17. Suspeitos de código órfão

_To be filled in Task 1.10._

## 18. Estado do PHPDoc

_To be filled in Task 1.10._

## 19. Resumo executivo

_To be filled in Task 1.10._
