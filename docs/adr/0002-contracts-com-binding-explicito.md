# 0002 — Contracts (interfaces) bound explicitly in AppServiceProvider

- **Status:** Accepted
- **Date:** 2026-05-10
- **Deciders:** Bruno Cordeiro

## Context and Problem Statement

Services and repositories that need to be mockable in tests, or have meaningful seams between layers, are declared as interfaces under `app/Contracts/<Layer>/` and bound to concrete implementations in `app/Providers/AppServiceProvider.php::register()`. After Phase 2 of the consolidation, the project has 8 contracts and 8 bindings — all centralized in one provider.

Alternatives considered when the convention was first introduced:

- Convention-based autoloading (e.g. `FooInterface` auto-binds to `Foo`).
- Per-feature service providers (`AuthServiceProvider`, `AnalyticsServiceProvider`, etc.).

## Considered Options

- Option 1 — Convention/autoloading. Magic, zero boilerplate, but hides DI from `grep`.
- Option 2 — Per-feature providers. Scales but adds files for the project's current size.
- Option 3 — Single `AppServiceProvider` with explicit bindings (chosen).

## Decision Outcome

Chosen: **Option 3 — single `AppServiceProvider` with explicit bindings** — because it keeps the entire DI graph readable in one place, makes test overrides trivial, and is proportional to the project's current binding count.

### Positive Consequences

- Single source of truth: `grep "->bind" app/Providers/AppServiceProvider.php` lists every contract in 20 lines.
- Trivial test overrides: `$this->app->bind(FooInterface::class, FakeFoo::class)` in a test bootstrap or `setUp()`.
- Onboarding: a new dev finds the DI graph by reading ONE file.

### Negative Consequences

- Provider grows over time. Trigger to split: when this file exceeds ~50 bindings (well below the current count of 8).
- Forgetting to add a binding produces a surprising runtime "Target class is not instantiable" — mitigated by tests exercising the binding indirectly.

## Links

- [Architecture diagram](../diagrams/architecture.md)
- [`app/Providers/AppServiceProvider.php`](../../app/Providers/AppServiceProvider.php)
- [`app/Contracts/`](../../app/Contracts/)
