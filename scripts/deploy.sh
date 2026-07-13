#!/bin/bash
# Deploy blue/green do backend.
#
# A cor nova sobe FORA do trafego, e aquecida (config:cache etc.), roda as
# migrations e passa no health check ANTES de receber qualquer requisicao. Se
# algo falhar, o deploy aborta e a cor antiga continua servindo — um release
# quebrado nunca vai ao ar.
#
# A stack `infra` (postgres, redis, alloy, exporters) NAO e tocada aqui. Antes,
# o `docker compose down` do deploy antigo derrubava o banco junto com a app.
#
# blue = :8000   green = :8001. A cor ativa e a que estiver no upstream
# `backend_app` de /etc/nginx/conf.d/upstreams.conf (unica fonte de verdade).
#
# MIGRATION_MODE:
#   online  (padrao) migra com o codigo antigo servindo -> exige migration
#                    retrocompativel (garantido por tests/Unit/MigrationSafetyTest)
#   offline          para a app, migra e sobe de novo (~20s de downtime). So
#                    para migration destrutiva marcada com @offline-migration.
set -euo pipefail

PROJECT_PATH="/var/www/linkchartapi"
UPSTREAMS="/etc/nginx/conf.d/upstreams.conf"
IMAGE="ghcr.io/bcordeirodev/linkchart-backend"
: "${IMAGE_TAG:?IMAGE_TAG obrigatorio}"
MIGRATION_MODE="${MIGRATION_MODE:-online}"

cd "$PROJECT_PATH"

# ── Secrets em .env.production e .env ────────────────────────────────────────
chmod +x ./scripts/inject-env.sh
./scripts/inject-env.sh

# ── Descobrir a cor ativa lendo o nginx ──────────────────────────────────────
if grep -qE '^\s*server\s+127\.0\.0\.1:8000;' "$UPSTREAMS"; then
    ACTIVE_COLOR="blue"
    ACTIVE_PORT=8000
    TARGET_COLOR="green"
    TARGET_PORT=8001
else
    ACTIVE_COLOR="green"
    ACTIVE_PORT=8001
    TARGET_COLOR="blue"
    TARGET_PORT=8000
fi
echo "Cor ativa: $ACTIVE_COLOR (:$ACTIVE_PORT) -> subindo $TARGET_COLOR (:$TARGET_PORT)"

echo "Baixando ${IMAGE}:${IMAGE_TAG}..."
docker pull "${IMAGE}:${IMAGE_TAG}"

# ── Infra de pe (idempotente; nao recria o que ja esta rodando) ──────────────
# ⚠️ `-p linkchartapi` e OBRIGATORIO: preserva os volumes linkchartapi_postgres_data
# e linkchartapi_redis_data. Com outro -p o Docker cria volumes VAZIOS e o banco
# parece apagado.
echo "Garantindo que a infra esta de pe (postgres/redis/alloy)..."
docker compose -p linkchartapi -f docker-compose.infra.yml up -d

# ── OFFLINE: derruba a app antes de migrar (migration destrutiva) ────────────
if [ "$MIGRATION_MODE" = "offline" ]; then
    echo "MIGRATION_MODE=offline — parando a cor ativa e o worker antes de migrar."
    docker rm -f "linkchartapi-${ACTIVE_COLOR}" 2>/dev/null || true
    docker rm -f linkchartapi-worker 2>/dev/null || true
fi

# ── Subir a cor alvo, FORA do nginx (ainda sem trafego) ──────────────────────
TARGET_CONTAINER="linkchartapi-${TARGET_COLOR}"
docker rm -f "$TARGET_CONTAINER" 2>/dev/null || true
echo "Subindo o container $TARGET_COLOR..."
IMAGE_TAG="$IMAGE_TAG" COLOR="$TARGET_COLOR" APP_PORT="$TARGET_PORT" \
    docker compose -p "linkchartapi-${TARGET_COLOR}" -f docker-compose.app.yml up -d

# ── Aquecer a cor alvo ───────────────────────────────────────────────────────
# Toda a parte lenta acontece aqui, com a cor antiga servindo normalmente.
# Nao ha reset de OPcache: o container e novo, o bytecode ja nasce novo.
echo "Aquecendo $TARGET_COLOR..."
docker cp docker/scripts/fix-permissions.sh "$TARGET_CONTAINER":/var/www/fix-permissions.sh
docker exec "$TARGET_CONTAINER" chmod +x /var/www/fix-permissions.sh
docker exec "$TARGET_CONTAINER" /var/www/fix-permissions.sh
docker exec "$TARGET_CONTAINER" php /var/www/artisan config:clear
docker exec "$TARGET_CONTAINER" php /var/www/artisan storage:link || true

# ── Migrations ───────────────────────────────────────────────────────────────
# Em modo online isto roda com a cor ANTIGA ainda servindo -> a migration
# precisa ser retrocompativel (MigrationSafetyTest garante isso no CI).
echo "Rodando migrations (modo: $MIGRATION_MODE)..."
docker exec "$TARGET_CONTAINER" php /var/www/artisan migrate --force

# ── Cache warm (depois das migrations) ───────────────────────────────────────
echo "Aquecendo caches do Laravel..."
docker exec "$TARGET_CONTAINER" php /var/www/artisan config:cache
docker exec "$TARGET_CONTAINER" php /var/www/artisan route:cache
docker exec "$TARGET_CONTAINER" php /var/www/artisan view:cache
docker exec "$TARGET_CONTAINER" /var/www/fix-permissions.sh

# ── Health check na porta da cor alvo ────────────────────────────────────────
echo "Health check em 127.0.0.1:${TARGET_PORT}..."
healthy=0
for attempt in $(seq 1 30); do
    if curl -fsS --max-time 5 "http://127.0.0.1:${TARGET_PORT}/health" >/dev/null 2>&1; then
        healthy=1
        echo "  nginx saudavel (tentativa $attempt)"
        break
    fi
    sleep 2
done

if [ "$healthy" -ne 1 ]; then
    echo "ERRO: $TARGET_COLOR nao respondeu. Abortando — $ACTIVE_COLOR segue servindo."
    docker logs "$TARGET_CONTAINER" --tail 40 || true
    docker rm -f "$TARGET_CONTAINER" 2>/dev/null || true
    exit 1
fi

# /health e servido pelo nginx (return 200) e NAO testa o PHP. Batemos tambem
# numa rota roteada pelo Laravel para confirmar que o PHP-FPM esta de pe.
PHP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 \
    "http://127.0.0.1:${TARGET_PORT}/api/public/link/__health_probe_nonexistent__")
if [ "$PHP_STATUS" != "404" ] && [ "$PHP_STATUS" != "200" ]; then
    echo "ERRO: PHP-FPM de $TARGET_COLOR retornou HTTP $PHP_STATUS (esperado 404)."
    docker logs "$TARGET_CONTAINER" --tail 40 || true
    docker rm -f "$TARGET_CONTAINER" 2>/dev/null || true
    exit 1
fi
echo "PHP-FPM saudavel (HTTP $PHP_STATUS)"

# ── Virar o trafego ──────────────────────────────────────────────────────────
# O range do sed limita a troca ao bloco `upstream backend_app` — o
# `frontend_app` NAO pode ser tocado aqui.
# `nginx -s reload` e graceful: os workers antigos drenam as conexoes em curso.
echo "Apontando o nginx para $TARGET_COLOR (:$TARGET_PORT)..."
sed -i -E "/upstream backend_app/,/}/ s#server 127\.0\.0\.1:[0-9]+;#server 127.0.0.1:${TARGET_PORT};#" "$UPSTREAMS"

if ! nginx -t 2>/dev/null; then
    echo "ERRO: config do nginx invalida. Revertendo o upstream."
    sed -i -E "/upstream backend_app/,/}/ s#server 127\.0\.0\.1:[0-9]+;#server 127.0.0.1:${ACTIVE_PORT};#" "$UPSTREAMS"
    docker rm -f "$TARGET_CONTAINER" 2>/dev/null || true
    exit 1
fi
nginx -s reload
echo "Trafego agora vai para $TARGET_COLOR"

# ── Recriar o worker com a imagem nova ───────────────────────────────────────
# Instancia unica, fora do blue/green. Durante a troca os jobs aguardam no Redis.
echo "Recriando o worker (filas + scheduler)..."
IMAGE_TAG="$IMAGE_TAG" docker compose -p linkchartapi-worker \
    -f docker-compose.worker.yml up -d --force-recreate

# ── Drenagem e desligamento da cor antiga ────────────────────────────────────
# 30s: o frontend fala com a API por DNS de container (nao pelo nginx do host),
# entao pode haver socket keep-alive apontando para a cor antiga. A drenagem da
# tempo dessas conexoes terminarem antes de o container sumir.
echo "Drenando $ACTIVE_COLOR por 30s..."
sleep 30
echo "Desligando $ACTIVE_COLOR..."
IMAGE_TAG="$IMAGE_TAG" COLOR="$ACTIVE_COLOR" APP_PORT="$ACTIVE_PORT" \
    docker compose -p "linkchartapi-${ACTIVE_COLOR}" -f docker-compose.app.yml down 2>/dev/null || true
docker rm -f "linkchartapi-${ACTIVE_COLOR}" 2>/dev/null || true

# ── Container legado (pre-blue/green) ────────────────────────────────────────
# O deploy antigo criava `linkchartapi` (sem sufixo de cor). Ele nao segue o
# esquema de cores, entao os comandos acima o ignoram — e continuaria segurando
# a porta 8000, fazendo o PROXIMO deploy (alvo = blue :8000) falhar no bind.
if docker ps -a --format '{{.Names}}' | grep -qx 'linkchartapi'; then
    echo "Removendo o container legado 'linkchartapi'..."
    docker rm -f linkchartapi || true
fi

docker image prune -f >/dev/null 2>&1 || true

echo ""
echo "Deploy concluido — $TARGET_COLOR ativa na :$TARGET_PORT (tag: $IMAGE_TAG)"
docker ps --filter "name=linkchart" --format "  {{.Names}}  {{.Status}}"
