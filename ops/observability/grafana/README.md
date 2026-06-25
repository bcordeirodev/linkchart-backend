# Grafana — Dashboards & Alerts as Code

Point-in-time export of the live Grafana Cloud configuration (tenant `brightemu1407`)
captured on 2026-06-25, for backup and disaster recovery.

## What is here

### Dashboards (`dashboards/`)

| File | UID | Title |
|------|-----|-------|
| `link-charts-overview.json` | `link-charts-overview` | Link Charts — Visão Geral |
| `link-charts-app.json` | `link-charts-app` | Link Charts — App (RED / Safety / Jobs) |
| `link-charts-infra.json` | `link-charts-infra` | Link Charts — Infra (Host / Postgres / Redis) |
| `link-charts-otel.json` | `link-charts-otel` | Link Charts — Observability |

### Alerts (`alerts/`)

- `alert-rules.json` — 9 Grafana-managed alert rules in folder `Link Charts`
  (groups: `link-charts-prod`, `link-charts-infra`, `link-charts-app`)
- `contact-points.json` — 1 contact point: `Link Charts Email` (email delivery)

## How to re-apply

> Grafana Cloud is API-managed (no file provisioning). These files are a versioned
> snapshot, not auto-synced. Re-apply manually after a tenant reset or migration.

**Restore a dashboard:**
```bash
curl -X POST https://<grafana-host>/api/dashboards/db \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <sa-token>" \
  -d "{\"dashboard\": $(cat dashboards/<uid>.json), \"overwrite\": true, \"folderId\": <folder-id>}"
```

**Restore an alert rule** (repeat per rule in `alert-rules.json`):
```bash
curl -X POST https://<grafana-host>/api/v1/provisioning/alert-rules \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <sa-token>" \
  -d '<single-rule-json>'
```

**Restore a contact point** (repeat per entry in `contact-points.json`):
```bash
curl -X POST https://<grafana-host>/api/v1/provisioning/contact-points \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <sa-token>" \
  -d '<single-contact-point-json>'
```

> The `id` and `version` fields were stripped from dashboard exports to avoid
> conflicts on import. The `uid` field is preserved so existing links keep working.
