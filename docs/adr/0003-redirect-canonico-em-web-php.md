# 0003 — Canonical /r/{slug} redirect lives in routes/web.php (not /api)

- **Status:** Accepted
- **Date:** 2026-05-10
- **Deciders:** Bruno Cordeiro

## Context and Problem Statement

The original design exposed `/api/r/{slug}` returning JSON for the front-end to redirect via JS. This broke Open Graph previews (bots can't run JS) and added an unnecessary frontend hop on the hottest endpoint in the system. The migration to a server-side redirect under `routes/web.php` happened on **2025-11-04**. The original AJAX route is preserved as a comment in `routes/api.php` lines 19–32 (the `⚠️ ROTA DESABILITADA` block) for historical reference and must not be re-enabled without revising this ADR.

In commit `46bb550` (this consolidation cycle), a clean-URL alias `/{slug}` was added alongside `/r/{slug}` to support `redirect.linkcharts.com.br/{slug}` deep links.

## Considered Options

- Option 1 — Keep `/api/r/{slug}` JSON + serve OG metadata via a separate endpoint that the FE calls after the redirect. Pro: REST consistency. Con: extra hop, OG breaks for bots, latency penalty.
- Option 2 — Move to `routes/web.php` with HTTP 302 + bot-detection HTML for previewers (chosen). Pro: OG works natively, lowest latency for humans, single source of truth. Con: redirect logic lives outside the `/api` middleware chain.
- Option 3 — Serve all redirects from Nginx (no Laravel). Pro: fastest. Con: loses tracking, bot detection, OG previews, and rate limiting.

## Decision Outcome

Chosen: **Option 2** — because server-side 302 with bot detection is the only option that satisfies Open Graph previews, low latency for human visitors, and full asynchronous tracking simultaneously.

### Positive Consequences

- Open Graph previews work for WhatsApp, Telegram, Slack, Twitter, Discord, and the rest.
- Lower redirect latency: one fewer hop (visitor → API direct, no Next.js round trip).
- Cleaner public URL: `redirect.linkcharts.com.br/{slug}` (clean alias from commit `46bb550`) and `/r/{slug}` (original).
- Tracking continues asynchronously via `ProcessLinkClickJob` — visitor sees 302 immediately.

### Negative Consequences

- The redirect lives outside the `/api` middleware chain. `TrustProxies` and `AssignRequestId` still apply via the `web()` middleware stack in `bootstrap/app.php` (lines 29–33). `NormalizeApiResponse` does NOT — but the redirect doesn't return JSON.
- Any change to `/r/{slug}` or `/{slug}` is high-risk: gating tests `RedirectTest` and `ProcessLinkClickJobTest` MUST stay green.
- Cache invariant: `Link::findActiveBySlugCached` TTL 10 min + the invalidation field list `[slug, is_active, expires_at, starts_in, original_url, click_limit]` MUST stay byte-identical.
- The `metrics.redirect` middleware (`RedirectMetricsCollector`) sits on this route and must follow the AppLogger convention (R-10 ensured this).

## Links

- [Redirect flow diagram](../diagrams/redirect-flow.md)
- [Cache strategy diagram](../diagrams/caching-strategy.md)
- [`routes/web.php`](../../routes/web.php) — lines 68–78
- [`app/Http/Controllers/Links/RedirectController.php`](../../app/Http/Controllers/Links/RedirectController.php)
- [`routes/api.php`](../../routes/api.php) — disabled JSON route preserved at lines 19–32 (the `⚠️ ROTA DESABILITADA` block) (DO NOT re-enable without revising this ADR)
