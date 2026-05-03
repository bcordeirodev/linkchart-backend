# CI/CD Pipeline Improvement Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the broken backend deploy pipeline (git→rsync), fix the frontend container healthcheck, add missing quality gates (Pint + build verification), and extract server-side deploy logic into a maintainable shell script.

**Architecture:** Backend deploy switches from `git reset --hard origin/main` over HTTPS (broken because the production server's git remote has no credentials) to rsync-based sync — the same pattern the frontend already uses successfully. All server-side bash logic is extracted from the 400-line GitHub Actions YAML heredoc into `scripts/deploy.sh`, which is rsynced to the server and callable manually for debugging. Frontend container healthcheck replaces `wget -qO-` (unreliable BusyBox exit codes) with a `node -e` HTTP check. Both CI pipelines gain the quality gates they currently lack.

**Tech Stack:** GitHub Actions, rsync, appleboy/ssh-action v1.0.3, webfactory/ssh-agent v0.9.0, Laravel Pint, docker compose v2, Next.js 15 standalone, Node.js HTTP module.

---

## Context

**Production server:** `root@134.209.33.182`
- Backend path: `/var/www/linkchartapi` — container `linkchartapi`, port 8000
- Frontend path: `/var/www/linkchart-frontend-next` — container `linkcharts-frontend-next-prod`, port 3000
- Docker network: `linkcharts-network` (external, shared between frontend and backend)

**Current problems identified:**
1. Backend deploy broken: `git fetch origin && git reset --hard origin/main` fails with `fatal: could not read Username for 'https://github.com'` — the git remote on the server is HTTPS with no stored credentials. Last 3 deploys failed silently after tests passed.
2. Frontend container shows `unhealthy` despite `/api/health` returning `{"status":"ok"}` — healthcheck uses `wget -qO-` which has unreliable exit codes in BusyBox/Alpine.
3. Redis healthcheck uses `redis-cli --raw incr ping` without the password — on a `requirepass`-protected Redis, this command may return `NOAUTH` with an unreliable exit code.
4. Backend CI has no code style gate — Pint is available but not run.
5. Frontend CI has no build verification — type-check and lint pass but build can still fail.
6. Production server accumulates Docker build cache (~7.6 GB reclaimable as of 2026-05-03) with no cleanup strategy.

**Not in scope (future work):**
- Zero-downtime deploy (requires load balancer or rolling update infrastructure)
- Docker image registry (GHCR) — significant architecture change
- Moving away from committed `.env.production` pattern

---

## Files

**Modified:**
- `backend/docker-compose.prod.yml` — Fix Redis healthcheck (add `-a linkchartredis123 --no-auth-warning`, remove `incr ping`)
- `frontend-next/docker-compose.prod.yml` — Fix container healthcheck (`wget -qO-` → `node -e` HTTP request)
- `backend/.github/workflows/deploy-production.yml` — Full rewrite: rsync-based sync, call `scripts/deploy.sh`, add Pint to validate job
- `frontend-next/.github/workflows/deploy-frontend-next.yml` — Add `npm run build` quality gate + Docker cache cleanup

**Created:**
- `backend/scripts/deploy.sh` — Server-side deploy logic extracted from CI heredoc

---

## Task 1: Fix Redis Healthcheck

**Files:**
- Modify: `backend/docker-compose.prod.yml` (redis service, lines ~81–86)

The current `redis-cli --raw incr ping` increments a key named "ping" without supplying the password. With `requirepass linkchartredis123` set, this returns `NOAUTH Authentication required`. Depending on redis-cli version, exit code is 0 or 1 — unreliable for health checking.

- [ ] **Step 1: Update the redis healthcheck block**

In `backend/docker-compose.prod.yml`, find the `redis` service and replace its `healthcheck`:

```yaml
  redis:
    image: redis:7-alpine
    container_name: linkchartredis
    restart: unless-stopped
    command: redis-server --appendonly yes --requirepass linkchartredis123
    volumes:
      - redis_data:/data
    ports:
      - "127.0.0.1:6379:6379"
    networks:
      - linkchartnet
      - linkcharts-network
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "linkchartredis123", "--no-auth-warning", "ping"]
      interval: 10s
      timeout: 3s
      retries: 5
      start_period: 30s
```

- [ ] **Step 2: Verify YAML syntax**

```bash
cd /Users/bruno/Projects/link-charts/backend
docker compose -f docker-compose.prod.yml config --quiet && echo "✅ YAML valid"
```

Expected: `✅ YAML valid`

- [ ] **Step 3: Commit**

```bash
cd /Users/bruno/Projects/link-charts/backend
git add docker-compose.prod.yml
git commit -m "fix: corrige healthcheck do Redis com autenticação correta"
```

---

## Task 2: Fix Frontend Container Healthcheck

**Files:**
- Modify: `frontend-next/docker-compose.prod.yml` (healthcheck block, lines ~23–28)

The runtime image is `node:20-alpine` (standalone build). BusyBox `wget -qO-` downloads to stdout but its exit code behavior on HTTP errors is inconsistent. `node` is always available and provides reliable exit codes.

- [ ] **Step 1: Replace the healthcheck in docker-compose.prod.yml**

In `frontend-next/docker-compose.prod.yml`, replace the `healthcheck` block. Also increase `start_period` from 20s to 30s — the Next.js standalone server can take up to 25s to fully initialize under load.

Full file content after edit:

```yaml
version: '3.8'
services:
  frontend-next:
    build:
      context: .
      args:
        - NEXT_PUBLIC_APP_URL=https://linkcharts.com.br
        - NEXT_PUBLIC_GA_ID
        - NEXT_PUBLIC_ADSENSE_CLIENT
        # API_URL is a build arg because next.config.ts rewrites() is evaluated at build time
        - API_URL=http://linkchartapi:80
    container_name: linkcharts-frontend-next-prod
    restart: always
    ports:
      - '3000:3000'
    networks:
      - frontend-net
      - linkcharts-network
    environment:
      - NODE_ENV=production
      - API_URL=http://linkchartapi:80
      - GOOGLE_SAFE_BROWSING_KEY
    healthcheck:
      test:
        - 'CMD'
        - 'node'
        - '-e'
        - "require('http').get('http://localhost:3000/api/health', r => process.exit(r.statusCode === 200 ? 0 : 1)).on('error', () => process.exit(1))"
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s

networks:
  frontend-net:
    driver: bridge
  linkcharts-network:
    external: true
```

- [ ] **Step 2: Verify YAML syntax**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
python3 -c "import yaml,sys; yaml.safe_load(open('docker-compose.prod.yml'))" && echo "✅ YAML valid"
```

Expected: `✅ YAML valid`

- [ ] **Step 3: Commit**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add docker-compose.prod.yml
git commit -m "fix: corrige healthcheck do container Next.js com node HTTP check"
```

---

## Task 3: Auto-format Codebase with Pint (prerequisite for CI gate)

**Files:**
- Various PHP files in `backend/` (auto-detected by Pint)

Pint is in the repo but not in CI. Before adding `--test` to CI (which fails on violations), run Pint once to auto-format all existing code so the CI gate doesn't immediately fail.

- [ ] **Step 1: Run Pint in check mode first to see the scope**

```bash
cd /Users/bruno/Projects/link-charts/backend
docker compose exec app ./vendor/bin/pint --test 2>&1 | tail -10
```

If exit code is 0 (no violations), skip to Task 4. If exit code is 1, continue with Step 2.

- [ ] **Step 2: Auto-format with Pint**

```bash
cd /Users/bruno/Projects/link-charts/backend
docker compose exec app ./vendor/bin/pint
```

Expected: Pint reports which files were reformatted. Exit code 0.

- [ ] **Step 3: Verify no violations remain**

```bash
cd /Users/bruno/Projects/link-charts/backend
docker compose exec app ./vendor/bin/pint --test && echo "✅ No Pint violations"
```

Expected: `✅ No Pint violations`

- [ ] **Step 4: Commit formatting changes**

```bash
cd /Users/bruno/Projects/link-charts/backend
git add -p  # review changed PHP files
git commit -m "style: aplica formatação Pint em todos os arquivos PHP"
```

---

## Task 4: Create Server-Side Deploy Script

**Files:**
- Create: `backend/scripts/deploy.sh`

This script contains all the server-side deployment logic currently embedded in the CI heredoc. Living in the repo, it is version-controlled, rsynced to the server on each deploy, and executable manually (`ssh root@... /var/www/linkchartapi/scripts/deploy.sh`) for debugging.

Key design decisions:
- `.env.production` is **excluded** from rsync (server's file with real secrets persists across deploys)
- `SENDGRID_API_KEY` is passed as an env var from GitHub Secrets and injected into `.env.production` only when set — handles both fresh servers and existing ones
- `FORCE_REBUILD` env var controls whether to wipe Docker build cache
- The script exits non-zero on any failure (`set -euo pipefail`)

- [ ] **Step 1: Create scripts directory**

```bash
mkdir -p /Users/bruno/Projects/link-charts/backend/scripts
```

- [ ] **Step 2: Create deploy.sh**

Create `/Users/bruno/Projects/link-charts/backend/scripts/deploy.sh`:

```bash
#!/bin/bash
set -euo pipefail

PROJECT_PATH="/var/www/linkchartapi"
COMPOSE_FILE="docker-compose.prod.yml"

cd "$PROJECT_PATH"

# ── SendGrid injection ────────────────────────────────────────────────────────
# .env.production is excluded from rsync so the server's copy (with real secrets)
# persists across deploys. SENDGRID_API_KEY is passed from GitHub Secrets and
# injected here. On existing servers the key is already present; on fresh servers
# this writes it for the first time.
if [ -n "${SENDGRID_API_KEY:-}" ]; then
    sed -i '/^SENDGRID_API_KEY=/d' .env.production
    sed -i '/^MAIL_PASSWORD=/d' .env.production
    printf 'SENDGRID_API_KEY=%s\n' "$SENDGRID_API_KEY" >> .env.production
    printf 'MAIL_PASSWORD=%s\n' "$SENDGRID_API_KEY" >> .env.production
    echo "SendGrid key injected into .env.production"
fi

# ── Stop existing containers ──────────────────────────────────────────────────
echo "Stopping existing containers..."
docker compose -f "$COMPOSE_FILE" down --timeout 60 || true

# ── Docker build cache management ────────────────────────────────────────────
if [ "${FORCE_REBUILD:-false}" = "true" ]; then
    echo "Force rebuild: clearing all Docker build cache..."
    docker builder prune -af
else
    echo "Trimming Docker build cache to 2 GB..."
    docker builder prune --keep-storage 2g -f
fi

# ── Build ─────────────────────────────────────────────────────────────────────
echo "Building containers..."
if [ "${FORCE_REBUILD:-false}" = "true" ]; then
    docker compose -f "$COMPOSE_FILE" build --no-cache --parallel
else
    docker compose -f "$COMPOSE_FILE" build --parallel
fi

# ── Start ─────────────────────────────────────────────────────────────────────
echo "Starting containers..."
docker compose -f "$COMPOSE_FILE" up -d

# ── Wait for PostgreSQL ───────────────────────────────────────────────────────
echo "Waiting for PostgreSQL..."
timeout 120 bash -c "
    until docker compose -f $COMPOSE_FILE exec -T database pg_isready -U linkchartuser -d linkchartprod >/dev/null 2>&1; do
        echo '  PostgreSQL starting...'
        sleep 5
    done
"
echo "✅ PostgreSQL ready"

# ── Wait for Redis ────────────────────────────────────────────────────────────
echo "Waiting for Redis..."
timeout 60 bash -c "
    until docker exec linkchartredis redis-cli -a linkchartredis123 --no-auth-warning ping 2>/dev/null | grep -q PONG; do
        echo '  Redis starting...'
        sleep 5
    done
"
echo "✅ Redis ready"

# ── Permissions ───────────────────────────────────────────────────────────────
echo "Applying permissions..."
docker cp docker/scripts/fix-permissions.sh linkchartapi:/var/www/fix-permissions.sh
docker exec linkchartapi chmod +x /var/www/fix-permissions.sh
docker exec linkchartapi /var/www/fix-permissions.sh

# ── Laravel cache clear ───────────────────────────────────────────────────────
echo "Clearing Laravel caches..."
docker exec linkchartapi php /var/www/artisan config:clear
docker exec linkchartapi php /var/www/artisan cache:clear
docker exec linkchartapi php /var/www/artisan route:clear
docker exec linkchartapi php /var/www/artisan view:clear
docker exec linkchartapi php /var/www/artisan storage:link

# Re-apply permissions after cache:clear recreates framework directories
docker exec linkchartapi /var/www/fix-permissions.sh

# ── Migrations ────────────────────────────────────────────────────────────────
echo "Running migrations..."
docker exec linkchartapi php /var/www/artisan migrate --force
echo "✅ Migrations done"

# ── Laravel cache warm ────────────────────────────────────────────────────────
echo "Warming Laravel caches..."
docker exec linkchartapi php /var/www/artisan config:cache
docker exec linkchartapi php /var/www/artisan route:cache
docker exec linkchartapi php /var/www/artisan view:cache

# ── Health check ──────────────────────────────────────────────────────────────
echo "Running health check..."
attempt=1
until curl -fsS --max-time 10 http://localhost:8000/health > /dev/null 2>&1; do
    if [ $attempt -ge 5 ]; then
        echo "❌ Health check failed after $attempt attempts"
        docker logs linkchartapi --tail 30
        exit 1
    fi
    echo "  Attempt $attempt failed, retrying in 10s..."
    attempt=$((attempt + 1))
    sleep 10
done
echo "✅ Health check passed (attempt $attempt)"

# ── Cleanup unused images ─────────────────────────────────────────────────────
docker image prune -f

echo ""
echo "🎉 Deploy complete — $(date)"
docker compose -f "$COMPOSE_FILE" ps
```

- [ ] **Step 3: Make executable and verify shell syntax**

```bash
chmod +x /Users/bruno/Projects/link-charts/backend/scripts/deploy.sh
bash -n /Users/bruno/Projects/link-charts/backend/scripts/deploy.sh && echo "✅ Shell syntax valid"
```

Expected: `✅ Shell syntax valid`

- [ ] **Step 4: Commit**

```bash
cd /Users/bruno/Projects/link-charts/backend
git add scripts/deploy.sh
git commit -m "feat: adiciona scripts/deploy.sh — extrai lógica de deploy do CI heredoc"
```

---

## Task 5: Rewrite Backend Deploy Workflow

**Files:**
- Modify: `backend/.github/workflows/deploy-production.yml`

The rewrite replaces the 400-line heredoc with:
1. Rsync step (same approach as frontend — no git credential dependency)
2. SSH step that calls `scripts/deploy.sh` (the script created in Task 4)
3. Final health check from the CI runner via the public HTTPS URL
4. Pint added to the `validate` job

Rsync excludes `.env.production` so the server's copy (with real secrets) is never overwritten.

- [ ] **Step 1: Rewrite deploy-production.yml**

Replace the entire content of `backend/.github/workflows/deploy-production.yml`:

```yaml
name: Deploy backend to Production

on:
  push:
    branches: [main]
  workflow_dispatch:
    inputs:
      force_rebuild:
        description: 'Force complete rebuild (no cache)'
        required: false
        default: false
        type: boolean

env:
  DEPLOY_HOST: 134.209.33.182
  PROJECT_PATH: /var/www/linkchartapi

jobs:
  # ============================================================
  # Quality checks — tests + code style
  # ============================================================
  validate:
    name: Validate
    runs-on: ubuntu-latest
    timeout-minutes: 10

    env:
      APP_ENV: testing
      APP_KEY: "base64:6Te5B+hW2AgrwEQi9uaO4f72snjcV1X6mVzmVrE+xKQ="
      APP_DEBUG: "true"
      DB_CONNECTION: sqlite
      DB_DATABASE: ":memory:"
      CACHE_STORE: array
      SESSION_DRIVER: array
      QUEUE_CONNECTION: sync
      JWT_SECRET: test-secret-ci-not-for-production-use-only

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
          extensions: mbstring, xml, zip, pdo_sqlite, redis, bcmath
          coverage: none

      - uses: ramsey/composer-install@v3
        with:
          composer-options: "--prefer-dist --no-progress"

      - name: Tests
        run: php artisan test

      - name: Code style (Pint)
        run: ./vendor/bin/pint --test

  # ============================================================
  # Deploy — rsync source + build on server
  # ============================================================
  deploy:
    name: Deploy to Production
    runs-on: ubuntu-latest
    needs: validate
    if: github.ref == 'refs/heads/main'

    environment:
      name: production
      url: https://api.linkcharts.com.br

    steps:
      - uses: actions/checkout@v4

      - name: Setup SSH agent
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.PRODUCTION_SSH_KEY }}

      - name: Add server to known_hosts
        run: ssh-keyscan -H ${{ env.DEPLOY_HOST }} >> ~/.ssh/known_hosts

      - name: Sync source files to server
        run: |
          rsync -az --delete \
            --exclude='.git' \
            --exclude='vendor' \
            --exclude='node_modules' \
            --exclude='.env.production' \
            --exclude='.env' \
            --exclude='storage/logs' \
            --exclude='storage/app' \
            . root@${{ env.DEPLOY_HOST }}:${{ env.PROJECT_PATH }}/

      - name: Run deploy script on server
        uses: appleboy/ssh-action@v1.0.3
        with:
          host: ${{ env.DEPLOY_HOST }}
          username: root
          key: ${{ secrets.PRODUCTION_SSH_KEY }}
          script: |
            chmod +x ${{ env.PROJECT_PATH }}/scripts/deploy.sh
            SENDGRID_API_KEY="${{ secrets.SENDGRID_API_KEY }}" \
            FORCE_REBUILD="${{ inputs.force_rebuild || 'false' }}" \
            ${{ env.PROJECT_PATH }}/scripts/deploy.sh

      - name: Final health check (from CI runner)
        run: |
          sleep 15
          curl -fsS --max-time 15 https://api.linkcharts.com.br/health
          echo ""
          echo "✅ Deploy confirmed via public health check"
```

- [ ] **Step 2: Verify YAML syntax**

```bash
cd /Users/bruno/Projects/link-charts/backend
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/deploy-production.yml'))" && echo "✅ YAML valid"
```

Expected: `✅ YAML valid`

- [ ] **Step 3: Commit**

```bash
cd /Users/bruno/Projects/link-charts/backend
git add .github/workflows/deploy-production.yml
git commit -m "fix: substitui git-pull por rsync no deploy do backend; adiciona Pint ao CI"
```

---

## Task 6: Add Build Verification to Frontend CI

**Files:**
- Modify: `frontend-next/.github/workflows/deploy-frontend-next.yml`

Add `npm run build` to the `quality` job so build failures are caught before deploy. Also add Docker cache trimming to the server deploy step (production currently has ~7.6 GB reclaimable).

`NEXT_PUBLIC_APP_URL` and `API_URL` are set in the CI build step to match production values — the Next.js config uses `process.env.API_URL` in `rewrites()`, which has a default of `http://localhost:8000` but setting it explicitly avoids any warning.

- [ ] **Step 1: Rewrite deploy-frontend-next.yml**

Replace the entire content of `frontend-next/.github/workflows/deploy-frontend-next.yml`:

```yaml
name: Deploy frontend-next to Production

on:
  push:
    branches: [main]
    paths:
      - 'app/**'
      - 'src/**'
      - 'public/**'
      - 'middleware.ts'
      - 'package*.json'
      - 'next.config.ts'
      - 'tsconfig.json'
      - 'Dockerfile'
      - 'docker-compose.prod.yml'
      - '.github/workflows/deploy-frontend-next.yml'

  workflow_dispatch:

env:
  NODE_VERSION: '20'

jobs:
  # ============================================================
  # Quality checks — type-check + lint + build
  # ============================================================
  quality:
    name: Quality Checks
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - uses: actions/setup-node@v4
        with:
          node-version: ${{ env.NODE_VERSION }}
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: TypeScript type-check
        run: npm run type-check

      - name: Lint
        run: npm run lint

      - name: Build (catch build-time errors before deploy)
        env:
          NEXT_PUBLIC_APP_URL: https://linkcharts.com.br
          API_URL: http://localhost:8000
        run: npm run build

  # ============================================================
  # Deploy — rsync source + build on server
  # ============================================================
  deploy:
    name: Deploy to Production
    runs-on: ubuntu-latest
    needs: quality
    if: github.ref == 'refs/heads/main'

    environment:
      name: production
      url: https://linkchart.app

    steps:
      - uses: actions/checkout@v4

      - name: Setup SSH agent
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.PRODUCTION_SSH_KEY }}

      - name: Add server to known_hosts
        run: ssh-keyscan -H ${{ secrets.PRODUCTION_HOST }} >> ~/.ssh/known_hosts

      - name: Sync source files to server
        run: |
          rsync -az --delete \
            --exclude='.git' \
            --exclude='node_modules' \
            --exclude='.next' \
            --exclude='.env.local' \
            --exclude='.env' \
            . root@${{ secrets.PRODUCTION_HOST }}:/var/www/linkchart-frontend-next/

      - name: Build and start container on server
        uses: appleboy/ssh-action@v1.0.3
        with:
          host: ${{ secrets.PRODUCTION_HOST }}
          username: root
          key: ${{ secrets.PRODUCTION_SSH_KEY }}
          script: |
            set -e
            cd /var/www/linkchart-frontend-next

            echo "Trimming Docker build cache to 2 GB..."
            docker builder prune --keep-storage 2g -f

            echo "Stopping existing container..."
            docker compose -f docker-compose.prod.yml down --remove-orphans || true

            echo "Building and starting container..."
            docker compose -f docker-compose.prod.yml up --build -d

            echo "Waiting for container startup..."
            sleep 25

            echo "Running health check..."
            attempt=1
            until curl -sf http://localhost:3000/api/health; do
              if [ $attempt -ge 5 ]; then
                echo "Health check failed after $attempt attempts"
                docker compose -f docker-compose.prod.yml logs --tail=50
                exit 1
              fi
              echo "Attempt $attempt failed, retrying in 10s..."
              attempt=$((attempt + 1))
              sleep 10
            done
            echo "Health check passed on attempt $attempt"

            echo "Cleaning up unused Docker images..."
            docker image prune -f

            echo "Deploy complete."
```

- [ ] **Step 2: Verify YAML syntax**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/deploy-frontend-next.yml'))" && echo "✅ YAML valid"
```

Expected: `✅ YAML valid`

- [ ] **Step 3: Commit**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git add .github/workflows/deploy-frontend-next.yml
git commit -m "feat: adiciona build ao CI do frontend e limpeza de cache Docker no deploy"
```

---

## Task 7: Push and Verify Both Pipelines

Push both repos to main and confirm the pipelines succeed end-to-end. This is also the first deploy that brings production up to date (Wave 3 analytics + onboarding demo link feature, which have been queued since the git auth failure).

- [ ] **Step 1: Push backend to main**

```bash
cd /Users/bruno/Projects/link-charts/backend
git push origin main
```

Expected: push succeeds. Open https://github.com/bcordeirodev/linkchart-backend/actions — the `Deploy backend to Production` workflow should start within 30 seconds.

- [ ] **Step 2: Monitor backend validate job**

Watch the `Validate` job. If `Code style (Pint)` fails with formatting violations, it means Task 3 was skipped. Fix by running:

```bash
cd /Users/bruno/Projects/link-charts/backend
docker compose exec app ./vendor/bin/pint
git add -p
git commit -m "style: aplica formatação Pint"
git push origin main
```

- [ ] **Step 3: Monitor backend deploy job**

The `Sync source files to server` rsync step should complete in ~30s. The `Run deploy script on server` step runs `scripts/deploy.sh` — expect ~5–8 minutes for build + migrations + health check.

If the deploy step fails, SSH into the server and check:

```bash
ssh root@134.209.33.182
cd /var/www/linkchartapi
docker compose -f docker-compose.prod.yml ps
docker logs linkchartapi --tail 30
```

- [ ] **Step 4: Confirm backend production state**

After the pipeline completes:

```bash
curl -s https://api.linkcharts.com.br/health
```

Expected: `{"status":"ok"}` (or similar)

```bash
ssh root@134.209.33.182 "docker exec linkchartapi php /var/www/artisan migrate:status | grep is_demo"
```

Expected: `2026_04_30_000001_add_is_demo_to_links_table ... Ran`

- [ ] **Step 5: Push frontend to main**

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
git push origin main
```

Expected: push succeeds. The `Deploy frontend-next to Production` workflow starts.

- [ ] **Step 6: Monitor frontend quality job**

The `Build (catch build-time errors before deploy)` step runs `npm run build` — expect ~2–3 minutes. If it fails, fix the build error locally:

```bash
cd /Users/bruno/Projects/link-charts/frontend-next
NEXT_PUBLIC_APP_URL=https://linkcharts.com.br API_URL=http://localhost:8000 npm run build
```

- [ ] **Step 7: Confirm frontend container is now healthy**

After the pipeline completes:

```bash
ssh root@134.209.33.182 "docker inspect --format='{{.State.Health.Status}}' linkcharts-frontend-next-prod"
```

Expected: `healthy`

```bash
curl -s https://linkchart.app/api/health
```

Expected: `{"status":"ok"}`

---

## Self-Review

**Spec coverage:**
- ✅ Backend deploy broken (git→rsync): Task 4–5
- ✅ Frontend container unhealthy (wget→node): Task 2
- ✅ Redis healthcheck wrong (no auth): Task 1
- ✅ Pint not in backend CI: Task 3 (format) + Task 5 (gate)
- ✅ No build verification in frontend CI: Task 6
- ✅ 7.6 GB build cache waste: `docker builder prune --keep-storage 2g -f` in both deploy scripts
- ✅ Wave 3 + onboarding not deployed: handled automatically by Task 7 (first successful deploy)
- ✅ `.env.production` SendGrid injection preserved and moved to server-side script

**Placeholder scan:** None found. All steps contain exact commands and complete file contents.

**Type consistency:** No types — shell scripts and YAML only. Command names are consistent across tasks (e.g., `scripts/deploy.sh`, `docker-compose.prod.yml`, `fix-permissions.sh`).
