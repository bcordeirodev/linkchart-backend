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

# ── Auth0 injection ───────────────────────────────────────────────────────────
# AUTH0_DOMAIN is not secret but .env.production is excluded from rsync, so
# the server copy can fall behind. Ensure it is always current.
sed -i '/^AUTH0_DOMAIN=/d' .env.production
printf 'AUTH0_DOMAIN=%s\n' "dev-w4znncuexg628diu.us.auth0.com" >> .env.production
echo "Auth0 domain injected into .env.production"

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

# ── Reset PHP-FPM OPcache ─────────────────────────────────────────────────────
# opcache.validate_timestamps=0 means FPM never auto-invalidates cached bytecode.
# After deploying new files we must explicitly reset so FPM recompiles from disk.
echo "Resetting PHP-FPM OPcache..."
# Write the probe file INSIDE the container (not on the host filesystem).
docker exec linkchartapi sh -c "printf '<?php opcache_reset(); echo OK;' > /var/www/public/.opcache_reset.php"
if curl -s --max-time 5 http://localhost:8000/.opcache_reset.php | grep -q OK; then
    echo "OPcache reset OK"
else
    echo "OPcache reset via HTTP failed — falling back to supervisorctl reload..."
    docker exec linkchartapi supervisorctl signal SIGUSR2 php-fpm 2>/dev/null || true
fi
docker exec linkchartapi rm -f /var/www/public/.opcache_reset.php

# ── Queue worker health ───────────────────────────────────────────────────────
# Workers may enter FATAL state on first boot if they crashed during the brief
# window between container start and caches being warmed (e.g. transient boot
# error). supervisord will NOT restart FATAL processes automatically — we must
# detect and recover them here, after all artisan steps have completed.
echo "Checking queue worker status..."
FATAL_PROGRAMS=$(docker exec linkchartapi \
    supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status 2>/dev/null \
    | grep FATAL | awk '{print $1}' | tr '\n' ' ' || true)

if [ -n "$FATAL_PROGRAMS" ]; then
    echo "  FATAL programs detected: $FATAL_PROGRAMS"
    echo "  Restarting..."
    docker exec linkchartapi \
        supervisorctl -c /etc/supervisor/conf.d/supervisord.conf start \
        $FATAL_PROGRAMS 2>&1 || true
    sleep 4
    # Verify they are now running
    STILL_FATAL=$(docker exec linkchartapi \
        supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status 2>/dev/null \
        | grep FATAL | awk '{print $1}' | tr '\n' ' ' || true)
    if [ -n "$STILL_FATAL" ]; then
        echo "WARNING: programs still in FATAL after restart: $STILL_FATAL"
        docker exec linkchartapi cat /var/www/storage/logs/worker.log 2>/dev/null | tail -30 || true
    else
        echo "  All programs recovered — RUNNING"
    fi
else
    echo "All queue workers are healthy"
fi

# ── Health check ──────────────────────────────────────────────────────────────
# Note: /health is handled by nginx directly (return 200) — it does NOT test PHP.
# We also check a PHP-routed endpoint to confirm FPM is actually working.
echo "Running health check (nginx)..."
attempt=1
until curl -fsS --max-time 10 http://localhost:8000/health > /dev/null 2>&1; do
    if [ $attempt -ge 5 ]; then
        echo "Nginx health check failed after $attempt attempts"
        docker logs linkchartapi --tail 30
        exit 1
    fi
    echo "  Attempt $attempt failed, retrying in 10s..."
    attempt=$((attempt + 1))
    sleep 10
done
echo "Nginx health check passed (attempt $attempt)"

echo "Running PHP-FPM health check..."
PHP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://localhost:8000/api/public/link/__health_probe_nonexistent__)
if [ "$PHP_STATUS" = "404" ] || [ "$PHP_STATUS" = "200" ]; then
    echo "PHP-FPM health check passed (HTTP $PHP_STATUS)"
else
    echo "PHP-FPM health check FAILED (HTTP $PHP_STATUS — expected 404, got 5xx)"
    docker logs linkchartapi --tail 30
    exit 1
fi

# ── Cleanup unused images ─────────────────────────────────────────────────────
docker image prune -f

echo ""
echo "Deploy complete — $(date)"
docker compose -f "$COMPOSE_FILE" ps
