# Contributing to linkcharts backend

Welcome. This file documents the conventions the codebase enforces — implicitly today, explicitly here.

## TL;DR

- Branch from `main`; open a PR back to `main`.
- Run `composer test` and `vendor/bin/pint --test` before opening the PR (CI enforces both).
- `composer analyse` runs `larastan/larastan` (phpstan level 5) — the baseline floor must not regress.
- Conventional Commit messages, lowercase, no trailing period. **Never** add `Co-Authored-By: Claude` or any AI/Anthropic reference.
- PHPDoc is mandatory on every new public method.
- Migrations are append-only; never edit a merged migration; never run `migrate:fresh` in production.

## Commit messages

Pattern: `type(scope): description`

- **Types** observed in `git log` (and enforced implicitly by convention):
  `feat`, `fix`, `refactor`, `chore`, `docs`, `test`, `perf`, `style`, `ci`, `build`.
- **Scopes** (open list, but prefer reused ones):
  `analytics`, `tracking`, `logging`, `auth`, `redirect`, `seeders`, `models`, `dto`, `contracts`,
  `services`, `repositories`, `controllers`, `jobs`, `migrations`, `adr`, `diagrams`, `audit`,
  `email`, `middleware`, `console`, `routes`, `phpstan`, `security`.
- **Subject**: lowercase, imperative, ≤72 chars, **no** trailing period.

Examples (recent):
- `feat(analytics): add quality breakdown to audience endpoint`
- `fix(tracking): degrade gracefully when Redis unavailable in ClickVelocityService`
- `refactor(logging): bootstrap exception handlers use AppLogger`
- `docs(adr): record decision on canonical redirect in web routes`

## Branching and PR flow

1. `git checkout main && git pull`
2. `git checkout -b <type>/<short-description>` (e.g. `feat/audit-quality-tier`)
3. Code in small, focused commits.
4. Run the local checks (next section).
5. `git push origin <branch>` and open a PR.
6. CI (`.github/workflows/ci.yml`) runs `php artisan test` + `vendor/bin/pint --test` on every PR and push (except pushes to `main`, which trigger the deploy pipeline instead).

## Local checks before opening a PR

```bash
composer test                                              # config:clear → phpunit → config:cache
vendor/bin/pint --test                                     # formatting check (no rewrites)
vendor/bin/phpstan analyse --memory-limit=2G               # baseline floor must not regress (currently 44 suppressed errors in phpstan-baseline.neon)
```

`composer test` is the canonical gate. It runs `php artisan config:clear` first — this is required because a cached config with pgsql settings causes the in-memory SQLite test database to be ignored (see the memory note in `CLAUDE.md`).

`composer lint` is an alias for `vendor/bin/pint --test`. `composer analyse` is an alias for the phpstan invocation above.

If you don't have PHP locally and use Docker:
```bash
docker exec linkchartapi-dev php artisan config:clear
docker exec linkchartapi-dev vendor/bin/phpunit
docker exec linkchartapi-dev vendor/bin/pint --test
docker exec linkchartapi-dev vendor/bin/phpstan analyse --memory-limit=2G
```

## Code conventions

### Layered architecture

- **Controllers** receive HTTP, validate via FormRequests, call Services, return Resources or redirects.
- **Services** own business logic. Inject Repositories or other Services via constructor.
- **Repositories** own Eloquent queries. No business logic.
- **Models** hold relationships, scopes, observers, casts. Read `app/Models/README.md` for the cross-reference table and Link's cache invariants.

See `docs/adr/0001-arquitetura-em-camadas.md` for the rationale.

### Contracts

- Anything with multiple implementations OR anything mocked in tests deserves a Contract in `app/Contracts/`.
- All bindings live in `app/Providers/AppServiceProvider.php::register()`. Keep them centralized.
- See `docs/adr/0002-contracts-com-binding-explicito.md` for the rationale.

### DTOs

- Use `app/DTOs/` for typed input/output between layers (especially Controllers ↔ Services).
- DTOs are immutable where possible (PHP 8.2 `readonly`).
- `LinkDTO` was deleted in commit `203f543` — it was superseded by `CreateLinkDTO`/`UpdateLinkDTO`/`CreatePublicLinkDTO`. Don't recreate it.

### PHPDoc

- Every new public method gets PHPDoc with side effects (mail, jobs, cache, GeoIP, external HTTP), `@throws`, and any context dependency (auth required, transaction required, etc.).
- See `app/Logging/AppLogger.php` for the in-house example — every semantic method has a docblock describing its channel and intent.

### Logging

- **Never** call `\Log::*` directly. Use `App\Logging\AppLogger` semantic methods (e.g. `AppLogger::redirectStarted`, `AppLogger::jobFailed`).
- If no semantic method fits, use the escape hatch `AppLogger::event(string $channel, string $level, string $event, array $context = [])`, OR extend `AppLogger` with a new method when the pattern recurs.
- Channels: `redirect` (7d), `tracking` (14d), `jobs` (14d), `auth` (4d — NOT redacted), `audit` (10d — NOT redacted), `http` (14d, 4xx/5xx only), `app` (14d default), `errors` (14d mirror). See `CLAUDE.md` "Logging" section.

## Where to put what

| New thing | Goes in | Notes |
|---|---|---|
| HTTP endpoint | `app/Http/Controllers/<Domain>/` | Use existing `Auth/`, `Links/`, `Analytics/` or create a new domain folder. Add route in `routes/api.php` (or `routes/web.php` only if there's a strong reason — see ADR 0003). |
| Business logic | `app/Services/<Domain>/` | Constructor-inject anything you need. No service locators. |
| Eloquent query | `app/Repositories/` | One repository per primary Model unless the model is small. |
| Background work | `app/Jobs/` | Must declare `tries` and `backoff`. Should be idempotent (or document why not). |
| New model | `app/Models/` | Add an Observer in `app/Models/Observers/` if it has lifecycle side effects; register it in `AppServiceProvider::boot()`. |
| Migration | `database/migrations/` | New file always. Never edit a merged migration. See `database/migrations/README.md`. |
| Contract | `app/Contracts/<Layer>/` | Required when there's a real second implementation or when the seam must be mockable for tests. |
| DTO | `app/DTOs/` | For typed I/O between layers. |
| Artisan command | `app/Console/Commands/` | Schedule it in `bootstrap/app.php::withSchedule()` if it should run periodically. |
| Test | `tests/Feature/` (HTTP / job behavior) or `tests/Unit/` (pure class) | Hot-path features (`/r/{slug}`, click tracking) need both. |
| Diagram | `docs/diagrams/` | Use Mermaid (renders natively on GitHub). One file per flow. |
| ADR | `docs/adr/` | MADR format. Number sequentially. Status `Accepted` once merged. |

## Pint policy

- Run `vendor/bin/pint` over **files you touched** before committing.
- **Do NOT** run `pint` over unrelated files in a feature PR — it ruins blame and history readability.
- A bulk reformat is a separate PR with the message `style: ...` and zero behavioral changes.

## Migrations policy

- **Append-only.** Never edit a migration that has been merged to `main`. To change a column, write a new migration.
- **Never run `php artisan migrate:fresh` in production.** Production migrations run via `scripts/deploy.sh` with `php artisan migrate --force`.
- Production rollback is via a forward migration that reverts the change, not via `migrate:rollback`.
- Commit `46bb550` (`feat(redirect): add clean URL alias /{slug} and move REDIRECT_URL to config`) is the cautionary example: `env()` was called directly inside a migration, which breaks under `config:cache`. Always use `config('namespace.key')` — never `env()` — outside of `config/*.php` files.
- See `database/migrations/README.md` for the chronological narrative and per-phase notes.

## Queue policy

- Every new job declares `public int $tries` and `public int $backoff` at the class level (see `app/Jobs/README.md` for the retry policy table).
- Idempotency is the default expectation. If a job is not idempotent, document why in its class PHPDoc (e.g. `ProcessLinkClickJob` is not idempotent on retry — that is acceptable because under-counting is acceptable).
- Always log via `AppLogger::jobStarted` / `jobSucceeded` / `jobFailed` so the lifecycle goes to the `jobs` channel and `request_id` propagates.
- Use the `HasLogContext` trait when the job needs the dispatcher's `request_id` (controllers pass it in the payload).

## Documentation rule

A PR that changes observable behavior also updates the relevant docs in the same PR:
- New endpoint → update the relevant `app/Http/Controllers/<Domain>/README.md`.
- New job → add it to `app/Jobs/README.md`.
- Architectural shift → write a new ADR in `docs/adr/`.
- New flow worth a diagram → add to `docs/diagrams/`.
- Schema change → add a migration AND update `database/migrations/README.md`.

## Configuration and secrets

- Use `config('namespace.key')` to read values; never call `env()` outside `config/`. Production runs with `config:cache` active, which makes `env()` return `null` everywhere except inside `config/*.php`.
- Secrets are kept out of `.env.example`. Use `php artisan key:generate` and `php artisan jwt:secret` to generate `APP_KEY` and `JWT_SECRET` locally. Production secrets are injected via GitHub Secrets at deploy time (`SENDGRID_API_KEY` etc.) — see `scripts/deploy.sh`.

## Where to find more

- [`README.md`](README.md) — setup, commands, deploy.
- [`CLAUDE.md`](CLAUDE.md) — architectural reference for AI agents and humans.
- [`docs/_audit/backend-inventory.md`](docs/_audit/backend-inventory.md) — current snapshot inventory.
- [`docs/adr/`](docs/adr/) — Architecture Decision Records (3 ADRs).
- [`docs/diagrams/`](docs/diagrams/) — 7 Mermaid diagrams of critical flows.
- Per-domain READMEs: `app/Http/Controllers/{Auth,Links,Analytics}/README.md`, plus layer READMEs in `app/Services/`, `app/Repositories/`, `app/Jobs/`, `app/Models/`, `database/migrations/`.
