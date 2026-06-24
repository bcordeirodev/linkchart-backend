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

Alloy is launched with `--stability.level=public-preview` to unlock the
`prometheus.exporter.unix` (node/host) component.

The application container (`linkchartapi`) continues to export its own OTLP
traces/metrics/logs directly to Grafana Cloud — it does **not** route through
Alloy in Phase 1.

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
