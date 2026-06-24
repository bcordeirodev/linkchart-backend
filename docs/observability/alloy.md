# Alloy / Observability runbook

Phase 1 observability: Grafana Alloy acts as the collection hub for infrastructure
metrics and logs, forwarding everything to Grafana Cloud via OTLP.

---

## Prerequisite — Postgres monitoring role

The `postgres_exporter` container connects to PostgreSQL as a dedicated
read-only `monitoring` role. **This role must be created once on the database
before (or alongside) the first deploy**, or `postgres_exporter` will start with
`pg_up=0` and no Postgres metrics will appear in Grafana Cloud.

Apply the role script, substituting a real password:

```bash
# On the server (or from a machine with psql access to linkchartdb):
PGPASSWORD=CHANGE_ME_DB_PASSWORD psql -h 127.0.0.1 -p 5432 \
  -U linkchartuser -d linkchartprod \
  -v monitoring_password='<YOUR_STRONG_PASSWORD>' \
  -f ops/observability/postgres-monitoring-role.sql
```

Store the chosen password as the **`PG_MONITORING_PASSWORD`** secret in the
GitHub `production` environment. The `deploy.sh` script reads this secret and
injects it into `.env` for `docker-compose.prod.yml` interpolation.

> **If you skip this step:** `pg_up` will be `0`, all Postgres dashboards will
> be empty, and `postgres_exporter` logs will show authentication errors.

---

## What runs

Three containers are added to `docker-compose.prod.yml` under the `linkchartnet`
internal network. None of them publish ports to the host — Alloy reaches the
exporters by Docker DNS name.

| Container | Image | Role |
|---|---|---|
| `linkchart-alloy` | `grafana/alloy:v1.17.0` | Scrapes exporters + node metrics, tails logs, forwards to Grafana Cloud |
| `linkchart-pg-exporter` | `prometheuscommunity/postgres-exporter:v0.15.0` | Exposes PostgreSQL metrics on `:9187` |
| `linkchart-redis-exporter` | `oliver006/redis_exporter:v1.62.0` | Exposes Redis metrics on `:9121` |

Alloy is launched with `--stability.level=experimental` (required by the
`deltatocumulative` processor; this lowers the component-stability bar for the
whole agent) to unlock the `prometheus.exporter.unix` (node/host) component.

The application container (`linkchartapi`) continues to export its own OTLP
traces/metrics/logs directly to Grafana Cloud — it does **not** route through
Alloy in Phase 1.

---

## Phase 2 — app → Alloy

As of Phase 2, the application container (`linkchartapi`) exports OTLP
**to the local Alloy** at `http://alloy:4318` (no authentication required —
internal Docker network only) instead of directly to Grafana Cloud.

This is achieved by injecting two environment variables into the `app` service
in `docker-compose.prod.yml`:

```yaml
- OTEL_EXPORTER_OTLP_ENDPOINT=http://alloy:4318
- OTEL_EXPORTER_OTLP_HEADERS=
```

Docker env vars override the values in the mounted `.env.production` because
Laravel's Dotenv does not override real env vars, and `config:cache` runs after
the container starts.

### What Alloy does with app telemetry

- **Metrics:** converts DELTA temporality (emitted by the Laravel SDK) to
  CUMULATIVE before forwarding to Grafana Cloud (Mimir rejects DELTA).
- **Traces:** applies tail-based sampling before forwarding to Tempo.
- **Logs:** forwards OTLP logs as-is to Loki.

### Rollback

Remove the two lines from the `app` service `environment` block in
`docker-compose.prod.yml`:

```yaml
- OTEL_EXPORTER_OTLP_ENDPOINT=http://alloy:4318
- OTEL_EXPORTER_OTLP_HEADERS=
```

Then redeploy. The app falls back to the `OTEL_EXPORTER_OTLP_ENDPOINT` and
`OTEL_EXPORTER_OTLP_HEADERS` values still present in `.env.production`,
reverting to direct export to the Grafana Cloud gateway. No telemetry data is
lost during the switchover.

---

## Phase 3a — auto-instrumentation

The application now emits child spans automatically for:
- **Database operations** (PDO): table/query type spans.
- **Outbound HTTP** (Guzzle): spans for Safe Browsing, OG metadata fetch, Auth0, and SendGrid requests.
- **Laravel & cache operations**: framework request context and cache hit/miss spans.

Auto-instrumentation is enabled by `ext-opentelemetry` 1.2.1 (installed in `Dockerfile` and `Dockerfile.dev`) plus composer packages `opentelemetry-auto-{laravel,pdo,guzzle}`. Spans flow to the same manually-built global TracerProvider and through the existing Alloy tail-sampled pipeline (Phase 2).

**Activation model:** auto packages register hooks whenever `ext-opentelemetry` is loaded; they emit spans only if a global TracerProvider exists. When `OTEL_ENABLED=false`, no provider is registered, so no spans are emitted. Setting `OTEL_PHP_AUTOLOAD_ENABLED=false` keeps a single manually-built provider (prevents double-initialization).

**Performance tradeoff: PHP JIT** — The `ext-opentelemetry` extension overrides `zend_execute_ex()`, so PHP disables the JIT entirely at startup (prod sets `opcache.jit=tracing`, 128M in `docker/php/opcache-prod.ini`). This affects every request, including the redirect hot path, regardless of OTEL sampling. The `OTEL_PHP_DISABLED_INSTRUMENTATIONS` dial does not recover the JIT — that loss comes from loading the extension itself, not from the hooks. For an I/O-bound Laravel app the JIT gain is usually small, so the tradeoff is typically acceptable; weigh it in the deploy p95 comparison. The only way to fully recover JIT is to remove the extension entirely — note that "Rollback (fast): OTEL_ENABLED=false" stops spans but keeps the JIT disabled, whereas "Rollback (full): redeploy the previous image" re-enables JIT (the previous image has no extension).

**Tuning dial:** disable noisy instrumentations via `OTEL_PHP_DISABLED_INSTRUMENTATIONS=<csv>`. Example values: `laravel` (framework spans), `pdo` (database spans—use if redirect hot path shows excessive spans). Set in `.env.production` or inject as Docker env var.

**Rollback (fast):** set `OTEL_ENABLED=false` (stops all emission). **Rollback (full):** redeploy the previous image (extension + packages are baked in).

---

## Verify

### 1 — Containers running on the server

```bash
ssh root@134.209.33.182 \
  'docker ps --format "{{.Names}}" | grep -E "alloy|exporter"'
```

Expected output (order may vary):

```
linkchart-alloy
linkchart-pg-exporter
linkchart-redis-exporter
```

### 2 — Exporters reachable from inside the Alloy container

```bash
ssh root@134.209.33.182 \
  'docker exec linkchart-alloy wget -qO- http://postgres_exporter:9187/metrics | head -1; \
   docker exec linkchart-alloy wget -qO- http://redis_exporter:9121/metrics | head -1'
```

Expected: each command returns a `# HELP …` line (Prometheus text format).

### 3 — Metrics present in Grafana Cloud (last 15 min)

Open **Explore → Prometheus** (or use the Grafana MCP) and run:

```promql
pg_up
```

```promql
redis_up
```

```promql
node_filesystem_avail_bytes
```

Expected: all three return `1` (or a value) with a recent timestamp.

### 4 — Infrastructure logs present in Loki

Open **Explore → Loki** and run:

```logql
{service_name="linkcharts-infra"}
```

Expected: lines arriving from nginx access/error logs and Docker container logs.

### 5 — Active-series budget check

Query active series in Grafana Cloud Billing / Usage, or use a rough proxy:

```promql
count({__name__=~".+"})
```

Phase 1 estimate is ~3–4 k series. Alert if the count approaches 8 k (80 % of the
10 k free-tier limit) — if a job is noisy, add a `metric_relabel` / `drop` rule
in `ops/observability/alloy/config.alloy` and redeploy.

---

## Rollback

Stop only the three new containers — the app container keeps exporting directly, so
application telemetry (traces, metrics, logs from `linkchartapi`) is unaffected:

```bash
ssh root@134.209.33.182 \
  'cd /var/www/linkchartapi && \
   docker compose -f docker-compose.prod.yml stop alloy postgres_exporter redis_exporter'
```

To remove them entirely (e.g. while debugging):

```bash
docker compose -f docker-compose.prod.yml rm -f alloy postgres_exporter redis_exporter
```

Re-enable by running a new deploy (triggers `deploy-production.yml`).

---

## Known checks

### Host vs container metrics from `prometheus.exporter.unix`

`prometheus.exporter.unix` runs **inside** the Alloy container. If it reports
metrics for the container's filesystem/CPU/memory instead of the host's, the
rootfs bind-mount may not be wired into the component.

Check: if `node_filesystem_avail_bytes{mountpoint="/"}` shows only a few GB (the
container root), the component is reading the container namespace rather than the
host.

Fix: add the following arguments to the `prometheus.exporter.unix` block in
`ops/observability/alloy/config.alloy`:

```alloy
prometheus.exporter.unix "node" {
  procfs_path = "/rootfs/proc"
  sysfs_path  = "/rootfs/sys"
  rootfs_path = "/rootfs"
}
```

The `/rootfs` bind-mount (`/:/rootfs:ro,rslave`) is already declared in
`docker-compose.prod.yml` for this purpose.
