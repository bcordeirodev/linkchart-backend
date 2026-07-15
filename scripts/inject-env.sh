#!/bin/bash
# Injeta secrets e config em .env.production (bind-montado no container) e em
# .env (usado pelo compose para interpolar ${VAR} nos docker-compose*.yml).
#
# Separado do deploy.sh para manter aquele legivel — aqui e so plumbing.
#
# ⚠️ Gotcha: .env.production e bind-mount de ARQUIVO. `sed -i` recria o inode e
# quebra o mount de um container que ja esteja rodando. Por isso esta injecao
# roda ANTES de subir a cor nova, nunca contra um container no ar.
#
# Chamado por scripts/deploy.sh com os secrets no ambiente (vindos do
# release.yml via GitHub Secrets).
set -euo pipefail

PROJECT_PATH="/var/www/linkchartapi"
cd "$PROJECT_PATH"

# .env.production e excluido do rsync (a copia do servidor, com os secrets
# reais, persiste entre deploys). Em servidor novo ele nao existe — os sed -i
# abaixo abortariam sob `set -e` sem este touch.
touch .env.production

# ── SendGrid ──────────────────────────────────────────────────────────────────
if [ -n "${SENDGRID_API_KEY:-}" ]; then
    sed -i '/^SENDGRID_API_KEY=/d' .env.production
    sed -i '/^MAIL_PASSWORD=/d' .env.production
    printf 'SENDGRID_API_KEY=%s\n' "$SENDGRID_API_KEY" >> .env.production
    printf 'MAIL_PASSWORD=%s\n' "$SENDGRID_API_KEY" >> .env.production
    echo "SendGrid key injetada"
fi

# ── Google Safe Browsing ──────────────────────────────────────────────────────
# Remove espacos/quebras: um espaco colado no fim produz uma linha dotenv
# malformada e o Laravel rejeita o .env INTEIRO. Chave de API nao tem espaco.
if [ -n "${GOOGLE_SAFE_BROWSING_KEY:-}" ]; then
    SAFE_BROWSING_KEY_CLEAN=$(printf '%s' "$GOOGLE_SAFE_BROWSING_KEY" | tr -d '[:space:]')
    sed -i '/^GOOGLE_SAFE_BROWSING_KEY=/d' .env.production
    printf 'GOOGLE_SAFE_BROWSING_KEY=%s\n' "$SAFE_BROWSING_KEY_CLEAN" >> .env.production
    echo "Safe Browsing key injetada"
fi

# ── Auth0 ─────────────────────────────────────────────────────────────────────
# Nao e secret, mas .env.production e excluido do rsync e a copia do servidor
# pode ficar para tras. Garantir que esta sempre atual.
sed -i '/^AUTH0_DOMAIN=/d' .env.production
printf 'AUTH0_DOMAIN=%s\n' "login.linkcharts.com.br" >> .env.production
echo "Auth0 domain injetado"

# ── APP_VERSION (SHA do commit) ───────────────────────────────────────────────
# Carimba service.version do OTel com o release deployado, para correlacionar
# traces/metricas/logs no Grafana com o commit exato.
if [ -n "${APP_VERSION:-}" ]; then
    APP_VERSION_SHORT=$(printf '%s' "$APP_VERSION" | tr -d '[:space:]' | cut -c1-12)
    sed -i '/^APP_VERSION=/d' .env.production
    printf 'APP_VERSION=%s\n' "$APP_VERSION_SHORT" >> .env.production
    echo "App version ($APP_VERSION_SHORT) injetada"
fi

# ── OpenTelemetry → Grafana Cloud ─────────────────────────────────────────────
# Spans de redirect amostrados a 0.05 para proteger o hot path.
for otel_kv in \
    "OTEL_ENABLED=true" \
    "OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp-gateway-prod-sa-east-1.grafana.net/otlp" \
    "OTEL_SERVICE_NAME=linkcharts-backend" \
    "OTEL_TRACES_SAMPLER_RATIO=0.1" \
    "OTEL_REDIRECT_SAMPLER_RATIO=0.05"; do
    otel_key="${otel_kv%%=*}"
    sed -i "/^${otel_key}=/d" .env.production
    printf '%s\n' "$otel_kv" >> .env.production
done
if [ -n "${OTEL_EXPORTER_OTLP_HEADERS:-}" ]; then
    sed -i '/^OTEL_EXPORTER_OTLP_HEADERS=/d' .env.production
    printf 'OTEL_EXPORTER_OTLP_HEADERS="%s"\n' "$OTEL_EXPORTER_OTLP_HEADERS" >> .env.production
else
    echo "AVISO: OTEL_EXPORTER_OTLP_HEADERS vazio — export de telemetria vai falhar auth"
fi
echo "OpenTelemetry injetado"

# ── Pyroscope (profiling contínuo, amostrado) → Grafana Cloud ────────────────
# Reusa o MESMO token do OTLP (GCLOUD_OTLP_PASS) — sem segredo novo. PYROSCOPE_USER
# é a instância do Pyroscope (basicAuthUser do datasource grafanacloud-profiles).
# Amostra 2% dos requests; push roda no terminate() (fora do caminho do request).
# Se o token não tiver escopo profiles:write, o push dá 401 (inofensivo: no-op).
for pyro_kv in \
    "PYROSCOPE_ENABLED=true" \
    "PYROSCOPE_ENDPOINT=https://profiles-prod-017.grafana.net" \
    "PYROSCOPE_USER=1700936" \
    "PYROSCOPE_APP_NAME=linkcharts-backend" \
    "PYROSCOPE_SAMPLE_RATE=0.02"; do
    pyro_key="${pyro_kv%%=*}"
    sed -i "/^${pyro_key}=/d" .env.production
    printf '%s\n' "$pyro_kv" >> .env.production
done
if [ -n "${GCLOUD_OTLP_PASS:-}" ]; then
    sed -i '/^PYROSCOPE_PASSWORD=/d' .env.production
    printf 'PYROSCOPE_PASSWORD=%s\n' "$GCLOUD_OTLP_PASS" >> .env.production
    echo "Pyroscope injetado (token reusado do OTLP)"
else
    echo "AVISO: GCLOUD_OTLP_PASS vazio — Pyroscope sem auth, push vai falhar"
fi

# ── Observabilidade — interpolacao do compose ────────────────────────────────
# O compose le ./.env (raiz do projeto) para resolver ${VAR} nos yml. Este
# bloco e DISTINTO do .env.production, que e bind-montado no container.
# Idempotente: remove antes de anexar, para nao acumular duplicatas.
touch "$PROJECT_PATH/.env"
for obs_kv in \
    "PG_MONITORING_PASSWORD=${PG_MONITORING_PASSWORD:-}" \
    "GCLOUD_OTLP_ENDPOINT=${GCLOUD_OTLP_ENDPOINT:-}" \
    "GCLOUD_OTLP_USER=${GCLOUD_OTLP_USER:-}" \
    "GCLOUD_OTLP_PASS=${GCLOUD_OTLP_PASS:-}"; do
    obs_key="${obs_kv%%=*}"
    sed -i "/^${obs_key}=/d" "$PROJECT_PATH/.env"
    printf '%s\n' "$obs_kv" >> "$PROJECT_PATH/.env"
done
echo "Vars de observabilidade injetadas em .env (interpolacao do compose)"
