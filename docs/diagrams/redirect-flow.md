# Redirect Flow

This diagram documents the critical path for the `/r/{slug}` (and `/{slug}`) redirect route — the heart of Link Chart. It is marked **LOCKED**: the sequence described here reflects existing, tested behavior and must not be changed without updating both the tests and ADR 0003.

```mermaid
sequenceDiagram
  autonumber
  participant Visitor
  participant Nginx
  participant L as Laravel<br/>(RedirectController)
  participant Cache as Redis (Cache)
  participant PG as PostgreSQL
  participant Q as Redis (Queue)
  participant Worker as Queue Worker<br/>(ProcessLinkClickJob)

  Visitor->>Nginx: GET /r/{slug} or /{slug}
  Nginx->>L: forward
  L->>Cache: Link::findActiveBySlugCached($slug)
  alt cache miss
    Cache-->>L: nil
    L->>PG: SELECT * FROM links WHERE slug = ? AND active
    PG-->>L: Link row
    L->>Cache: Cache::put(link:slug:{slug}, link, 600s)
  else cache hit
    Cache-->>L: Link row
  end
  alt is bot (UA matches WhatsApp/Telegram/...)
    L-->>Visitor: 200 HTML with Open Graph meta
    Note over Visitor: No click tracked (preview, not visit)
  else human
    L->>Q: dispatch ProcessLinkClickJob(linkId, payload)
    L-->>Visitor: 302 Location: original_url
  end
  Note over Worker,PG: async — does NOT block visitor
  Worker->>Q: pop ProcessLinkClickJob
  Worker->>PG: INSERT INTO clicks (LinkTrackingService::registrarCliqueFromPayload)
  Worker->>PG: UPDATE links SET clicks = clicks + 1 (DB::table->increment)
```

This route lives in `routes/web.php` rather than `routes/api.php` for two reasons: it must serve raw HTML (with Open Graph and Twitter Card meta-tags) to bot crawlers so that link previews work correctly in WhatsApp, Telegram, and similar platforms; and it performs a direct 302 redirect for human visitors without routing through the Next.js SPA. The previously disabled `/api/r/{slug}` route (commented out in `routes/api.php` since 2025-11-04) is preserved as documentation — see ADR 0003. Do not reopen it without a compelling justification and a matching test update.

Cache invariants are locked: the slug cache TTL is 10 minutes; invalidation is triggered by changes to the fields `[slug, is_active, expires_at, starts_in, original_url, click_limit]` inside `Link::booted()`. The `links.clicks` counter increment happens inside `ProcessLinkClickJob` via `LinkTrackingService::registrarCliqueFromPayload`, not in the controller — the controller only dispatches the job. This keeps the HTTP response latency minimal and the cache entry stable.

Rate limiting is enforced by `throttle:redirect` (600 requests per minute per IP), registered in `bootstrap/app.php`. Two named routes share the same controller action: `/r/{slug}` (`public.redirect`) and `/{slug}` (`public.redirect.clean`, constrained by regex `[^/]+`). The clean-URL alias was introduced in commit `46bb550`. The `?preview=1` query parameter renders the Open Graph HTML without dispatching a tracking job, enabling link preview debugging without polluting analytics.
