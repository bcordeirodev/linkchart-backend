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
echo "PostgreSQL ready"

# ── Wait for Redis ────────────────────────────────────────────────────────────
echo "Waiting for Redis..."
timeout 60 bash -c "
    until docker exec linkchartredis redis-cli -a linkchartredis123 --no-auth-warning ping 2>/dev/null | grep -q PONG; do
        echo '  Redis starting...'
        sleep 5
    done
"
echo "Redis ready"

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
echo "Migrations done"

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
        echo "Health check failed after $attempt attempts"
        docker logs linkchartapi --tail 30
        exit 1
    fi
    echo "  Attempt $attempt failed, retrying in 10s..."
    attempt=$((attempt + 1))
    sleep 10
done
echo "Health check passed (attempt $attempt)"

# ── Cleanup unused images ─────────────────────────────────────────────────────
docker image prune -f

echo ""
echo "Deploy complete — $(date)"
docker compose -f "$COMPOSE_FILE" ps
