# 0001 — Controllers → Services → Repositories → Models

- **Status:** Accepted
- **Date:** 2026-05-10
- **Deciders:** Bruno Cordeiro

## Context and Problem Statement

Link Chart API has 7 controller domains (Auth, Links, Analytics, plus root Metrics now deleted, BaseController as parent), 16 services across 3 sub-folders (Analytics, Links, Onboarding) plus 2 at the root, 1 active repository (`LinkRepository`; `Chart` and `Word` deleted in Phase 2), 7 Eloquent models, 4 background jobs. The team needed a separation that:

- Keeps controllers thin (HTTP concerns only).
- Makes business logic testable in isolation.
- Allows mocking of persistence in tests.
- Matches the Laravel idiom while staying simple enough for fast onboarding.

PHP 8.2 + Laravel 12 baseline.

## Considered Options

- Option 1 — Active Record (thin controllers + fat models holding queries + business logic). Common in Laravel-first projects.
- Option 2 — Layered (chosen): Controllers → Services → Repositories → Models, with Contracts in `app/Contracts/` and DTOs for typed I/O between layers.
- Option 3 — Hexagonal/Ports & Adapters with use-case classes. Maximal testability but heavy boilerplate.

## Decision Outcome

Chosen: **Option 2 — layered** — because it provides clear seams between HTTP, business, and persistence concerns without incurring the boilerplate cost of a full hexagonal architecture, and aligns with the Laravel idiom the team already knows.

### Positive Consequences

- Clear seams: Controllers test → integration; Services test → unit; Repositories test → DB-backed; Models test → schema-backed.
- Dependency injection boundaries are explicit in `app/Providers/AppServiceProvider.php::register()`.
- New developers can find logic predictably: business rules live in Services, queries live in Repositories.
- Cross-cutting concerns (logging, request_id propagation, rate limiting) live in dedicated layers (AppLogger, AssignRequestId middleware, RateLimiter::for in AppServiceProvider).

### Negative Consequences

- Boilerplate for trivial CRUD (a small Link create traverses Controller → DTO → Service → Repository → Model).
- Some services without contracts (`LinkAnalyticsOrchestrator` was the holdout until R-13 in 2026-05; addressed).
- Risk of "anemic services" where a service is a 1:1 wrapper around a repository — partially the case for some auth services.

## Links

- [Architecture diagram](../diagrams/architecture.md)
- [`app/Contracts/`](../../app/Contracts/) — interfaces
- [`app/Providers/AppServiceProvider.php`](../../app/Providers/AppServiceProvider.php) — binding location
