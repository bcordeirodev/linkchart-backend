# Link Chart — Backend API

Backend HTTP API for [linkcharts.com.br](https://linkcharts.com.br), a URL shortener with deep click analytics. Built on **Laravel 12 / PHP 8.2**, backed by **PostgreSQL 15** and **Redis 7**. Serves the Next.js front-end at linkcharts.com.br and the public redirect (`/r/{slug}` plus the clean `/{slug}` alias) with Open Graph previews for bots.

## Stack

- PHP 8.2, Laravel 12
- PostgreSQL 15, Redis 7
- JWT auth via `tymon/jwt-auth` (`dev-chore/laravel-12` branch — Laravel 12 compatibility)
- Geo: `torann/geoip` · UA parsing: `jenssegers/agent` · Holidays: `azuyalabs/yasumi`
- Mail: `sendgrid/sendgrid` (SendGrid HTTP transport)
- Tooling: `laravel/pint`, `larastan/larastan`, `phpunit/phpunit` (SQLite `:memory:` in CI)
- Containerization: Docker + Docker Compose

## Pré-requisitos e setup local

```bash
# 0. Pré-requisitos
#    - PHP 8.2 (extensions: mbstring, xml, zip, pdo_pgsql, redis, bcmath) — or use Docker exclusively.
#    - Composer 2.x
#    - Docker + Docker Compose v2

# 1. Clone and copy the env template
git clone git@github.com:bcordeirodev/linkchart-backend.git
cd linkchart-backend
cp .env.example .env

# 2. Bring up Postgres + Redis (mapped to alternate host ports 5433/6380 to avoid conflicts)
docker-compose up -d database redis

# 3. Install PHP deps; generate APP_KEY and JWT_SECRET
composer install
php artisan key:generate
php artisan jwt:secret

# 4. Run migrations
php artisan migrate

# 5. Bring up the dev stack (server + queue + logs + vite) or just the API
composer run dev
# or, just the API:
php artisan serve

# 6. Start the queue worker (required for click tracking, link preview, demo seed)
php artisan queue:work
```

If you prefer everything in Docker:

```bash
docker-compose up -d                      # brings up app + database + redis + nginx
docker exec linkchartapi-dev php artisan migrate
```

## Estrutura de pastas

```
backend/
├── app/
│   ├── Console/Commands/         # artisan commands (api:optimize, …)
│   ├── Contracts/                # interfaces (Repositories/, Services/, Analytics/)
│   ├── DTOs/                     # input/output DTOs
│   ├── Exceptions/               # ApiExceptionHandler
│   ├── Http/
│   │   ├── Controllers/          # Auth/, Links/, Analytics/ — see per-domain READMEs
│   │   ├── Middleware/           # ApiAuthenticate, AssignRequestId, NormalizeApiResponse, …
│   │   ├── Requests/             # FormRequests
│   │   └── Resources/            # API Resources
│   ├── Jobs/                     # ProcessLinkClickJob, SeedDemoLinkJob, FetchLinkPreviewJob, LinkHealthCheckJob — see app/Jobs/README.md
│   ├── Logging/                  # AppLogger facade + processors + taps (see CLAUDE.md "Logging")
│   ├── Models/                   # Eloquent models + Observers/ — see app/Models/README.md
│   ├── Providers/                # AppServiceProvider (DI bindings + rate limiters)
│   ├── Repositories/             # Eloquent persistence — see app/Repositories/README.md
│   └── Services/                 # business logic — see app/Services/README.md
├── bootstrap/app.php             # Laravel 12 app bootstrap (middleware, exceptions, schedule)
├── config/                       # config files (logging, tracking, geoip, …)
├── database/
│   ├── factories/
│   ├── migrations/               # 24 migrations — append-only — see database/migrations/README.md
│   └── seeders/
├── docs/                         # specs, plans, audits, ADRs, diagrams
│   ├── _audit/                   # snapshot inventories
│   ├── adr/                      # Architecture Decision Records (MADR format)
│   ├── diagrams/                 # Mermaid diagrams of critical flows
│   └── superpowers/{plans,specs}/  # design + implementation specs per feature
├── public/
├── routes/
│   ├── api.php                   # /api/* routes
│   ├── web.php                   # /r/{slug} + clean /{slug} alias (intentionally NOT under /api)
│   └── console.php               # artisan command shells (schedule lives in bootstrap/app.php)
├── scripts/deploy.sh             # production deploy script (called by GitHub Actions)
├── storage/logs/                 # 8 log channels (see CLAUDE.md "Logging")
└── tests/
    ├── Feature/                  # HTTP + integration tests
    └── Unit/                     # pure unit tests
```

## Como contribuir

See [CONTRIBUTING.md](CONTRIBUTING.md) for commit conventions, branching, code style, and the checks you must run before opening a PR.

## Comandos úteis

```bash
# Concurrent dev (server + queue + logs + vite)
composer run dev

# Server only
php artisan serve

# Tests
php artisan test                                      # full suite
vendor/bin/phpunit --filter RedirectTest              # single test class

# Lint / static analysis
vendor/bin/pint                                       # format
vendor/bin/pint --test                                # CI gate (no rewrites)
vendor/bin/phpstan analyse --memory-limit=2G          # larastan (baseline floor: ≤20 errors)

# Database
php artisan migrate
php artisan migrate:status
php artisan tinker

# Queue
php artisan queue:work
php artisan queue:listen --tries=1                    # dev: auto-reload

# Schedule (LinkHealthCheckJob runs hourly)
php artisan schedule:work                             # local scheduler

# Cache
php artisan optimize:clear                            # clear config + route + view cache
php artisan api:optimize                              # custom: see app/Console/Commands/OptimizeApiCommand.php

# Docker shortcuts (when using docker-compose)
docker exec linkchartapi-dev php artisan migrate
docker exec linkchartapi-dev vendor/bin/phpunit
```

## Documentação avançada

- [`CLAUDE.md`](CLAUDE.md) — internal reference for Claude Code (and humans): architecture, logging, hot path, debt.
- [`docs/_audit/backend-inventory.md`](docs/_audit/backend-inventory.md) — current inventory snapshot (2026-05-10).
- [`docs/adr/`](docs/adr/) — Architecture Decision Records (MADR).
- [`docs/diagrams/`](docs/diagrams/) — Mermaid diagrams of critical flows (redirect, jobs, cache, auth, error handling).
- [`docs/superpowers/specs/`](docs/superpowers/specs/) — feature design specs.
- [`docs/superpowers/plans/`](docs/superpowers/plans/) — implementation plans per feature.
- Per-domain READMEs:
  - `app/Http/Controllers/Auth/README.md`
  - `app/Http/Controllers/Links/README.md`
  - `app/Http/Controllers/Analytics/README.md`
  - `app/Services/README.md`
  - `app/Repositories/README.md`
  - `app/Jobs/README.md`
  - `app/Models/README.md`
  - `database/migrations/README.md`

## Deploy

Production runs on a single VPS (DigitalOcean). **Pushing to `main` does not deploy** — it only runs CI. Deploys are intentional and triggered by a tag.

```bash
git tag v2.1.0 && git push origin v2.1.0            # build + deploy
gh workflow run "Release (backend)" -f ref=v2.0.0   # rollback / manual deploy
```

- **Trigger:** tag `v*` (or `workflow_dispatch`) runs `.github/workflows/release.yml`.
- **The image is built on the GitHub runner** and pushed to GHCR (`ghcr.io/bcordeirodev/linkchart-backend:<sha>`). The VPS never compiles — it only pulls. (It is a 2 vCPU / 3.8 GB box: building there used to starve the live app and caused a ~2.5 min outage on every deploy.)
- **Blue/green cutover, zero downtime.** `scripts/deploy.sh` starts the idle colour (`8000` = blue, `8001` = green) *outside* nginx, warms it (`config:cache`, `route:cache`), runs migrations, and health-checks it. Only then does it rewrite `/etc/nginx/conf.d/upstreams.conf` and `nginx -s reload` (graceful). **If the new colour fails its health check, the deploy aborts and the old one keeps serving.**

### Three stacks, split by lifecycle

| Compose file | Project (`-p`) | Lifecycle |
| --- | --- | --- |
| `docker-compose.infra.yml` | **`linkchartapi`** | Postgres, Redis, Alloy, exporters — started once, **never touched by a deploy** |
| `docker-compose.app.yml` | `linkchartapi-{blue,green}` | web (nginx + php-fpm) — blue/green |
| `docker-compose.worker.yml` | `linkchartapi-worker` | queues + scheduler — **single instance** |

> ⚠️ The infra stack **must** be started with `-p linkchartapi`. Docker volumes are scoped by Compose project name (`linkchartapi_postgres_data`); any other `-p` creates **new, empty volumes** and the database appears wiped.

> ⚠️ Queues live in their own container. Two web colours running at once would run **two schedulers** and double-fire `LinkHealthCheckJob` and `clicks:anonymize-ips`.

### Migrations must be backward-compatible

With blue/green, `migrate` runs **while the old code is still serving traffic**. Add the new column → deploy → only remove the old one in the *next* release. Never rename or drop in one shot. `tests/Unit/MigrationSafetyTest.php` fails the build on `dropColumn`/`renameColumn`/`drop`/`rename` inside `up()`.

For a genuinely destructive migration, mark it `@offline-migration` and run the release with `migration_mode: offline` (stops the app, migrates, restarts — ~20 s of downtime).

- **Production cache strategy:** both `config:cache` and `route:cache` are active in production. `env()` calls outside `config/` return `null` after cache — always read env via `config('namespace.key')`.

- **Frontend:** the Next.js front-end lives in the sibling `frontend-next/` folder in the same monorepo root.
