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

_To be filled in Task 1.3._

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
