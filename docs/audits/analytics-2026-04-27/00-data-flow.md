# Data flow end-to-end — Audit cross-cutting

## Scope

Mapa de alto nível de como os dados de analytics fluem desde o clique no link curto até a renderização nos componentes do frontend. Documento de orientação — para detalhes específicos, consulte os audits por módulo.

## Pipeline de tracking (write path)

```
1. GET /r/{slug}                                    [routes/web.php]
   ↓
2. RedirectController::redirect                     [app/Http/Controllers/Links/RedirectController.php]
   - Cache lookup: Link::findActiveBySlugCached(slug)  (Redis, TTL 10min)
   - Bot detection (UA patterns + Agent lib)
   - Bot/preview branch: render HTML com Open Graph, NÃO conta clique
   - Humano branch:
     a. DB::table('links')->increment('clicks')    [contador denormalizado]
     b. dispatch(ProcessLinkClickJob)               [queue]
     c. Render HTML com countdown 2s + redirect JS
   ↓
3. ProcessLinkClickJob (queue worker)               [app/Jobs/ProcessLinkClickJob.php]
   ↓
4. LinkTrackingService::registrarCliqueFromPayload  [app/Services/Links/LinkTrackingService.php]
   - extractUtm           (query params + referer)
   - resolveDetailedLocation (torann/geoip)
   - parseUserAgent       (jenssegers/agent + regex)
   - enrichTemporalData   (Carbon + timezone)
   - analyzeVisitorBehavior (is_return_visitor, session_clicks, click_source)
   - collectPerformanceData (response_time, accept_language)
   ↓
5. INSERT INTO clicks (...)                         [tabela com 30+ colunas enriquecidas]
   - Se há UTM: INSERT INTO link_utms (...)
```

**Pontos de atenção (cross-referência):**

| Ponto | Issue | Audit |
|-------|-------|-------|
| 1→2 | `click_limit` não é checado em `/r/{slug}` (só na criação) | `01-redirect-tracking.md` |
| 2a vs 5 | `links.clicks` (counter) ≠ `count(clicks WHERE link_id=X)` (rows) — sync drift | `01-redirect-tracking.md` |
| 4 (`response_time`) | Mede latência da fila, não do redirect HTTP real | `01-redirect-tracking.md`, `08-performance.md` |
| 4 (UA parsing) | Lógica triplicada em 3 services | `06-audience.md`, `12-monolith-refactor.md` |
| 4 (`day_of_week`) | Convenção ISO 1-7 aqui, mas queries de read usam DOW 0-6 | `05-temporal.md`, `11-naming-conventions.md` |
| 4 (geo) | `default_location` retorna stub se IP local/falha — mascara dados ausentes | `01-redirect-tracking.md`, `04-geographic.md` |
| 5 (Click + LinkUtm) | Não há `DB::transaction` envolvendo os 2 INSERTs | `01-redirect-tracking.md` |

## Pipeline de leitura (read path)

```
Frontend                                            Backend
─────────────────────────                           ──────────────────────────────────
LinkAnalyticsPage                                   routes/api.php
  ├ EmailVerificationGuard                          ├ middleware: api.auth, verified
  └ LinkAnalyticsTabs (legacy)                      └ AnalyticsController@*
      ├ <Tab dashboard>
      │   └ LinkDashboard                              GET /api/analytics/link/{id}/dashboard
      │       └ useDashboardData ──────────────►       ↓
      │           └ api.get(...)                       AnalyticsController::getLinkDashboardData
      │                                                ↓
      │                                                LinkAnalyticsService::getLinkDashboardAnalytics
      │                                                ↓
      │                                                ChartRepository + LinkAnalyticsService::*Optimized
      │                                                ↓
      │                                                SELECT ... FROM clicks WHERE link_id=X GROUP BY ...
      │
      ├ <Tab heatmap>
      │   └ HeatmapAnalysis
      │       └ useHeatmapData ─────────────────►      GET /api/analytics/link/{id}/heatmap
      │                                                → AnalyticsController::getHeatmapData
      │                                                → LinkAnalyticsService::getHeatmapDataOptimized
      │
      ├ <Tab geographic>
      │   └ GeographicAnalysis
      │       └ useGeographicData ──────────────►      GET /api/analytics/link/{id}/geographic
      │
      ├ <Tab temporal>
      │   └ TemporalAnalysis
      │       └ useTemporalData ────────────────►      GET /api/analytics/link/{id}/temporal
      │                                                → AnalyticsController::getTemporalAnalytics
      │                                                → LinkAnalyticsService::getLinkTemporalAnalytics (base)
      │                                                + UserAgentAnalyticsService::getAdvancedTemporalAnalytics (advanced)
      │                                                + AnalyticsController::enrichTimezoneAnalysis (private!)
      │
      ├ <Tab audience>
      │   └ AudienceAnalysis
      │       └ useAudienceData ────────────────►      GET /api/analytics/link/{id}/audience
      │           ⚠ Math.random() para newVisitorRate, bounceRate, avgSessionDuration
      │
      ├ <Tab insights>
      │   └ InsightsAnalysis
      │       └ useInsightsData ────────────────►      GET /api/analytics/link/{id}/insights
      │                                                → LinkAnalyticsService::generateBusinessInsightsOptimized
      │                                                → 10 generators inline
      │
      └ <Tab performance>
          └ PerformanceAnalysis (pasta `perfomance/` typo)
              └ useLinkPerformance ────────────────►   (mesmo endpoint do dashboard)
                  └ analyticsService.getLinkPerformance
                      └ adapter hardcoda 4 campos: uptime=100%, performance_score, clicks_per_hour=0, visitor_retention=0


PublicAnalyticsPage                                 routes/api.php
  └ usePublicAnalytics ─────────────────────────►   GET /api/public/analytics/{slug}
                                                    → PublicLinkController::basicAnalytics
                                                    ⚠ sem rate limit, sem filtro is_active/expires_at
```

## Camadas e responsabilidades atuais

```
┌──────────────────────────────────────────────────────────────────┐
│  ROUTES                                                          │
│   web.php          (/r/{slug})                                   │
│   api.php          (/api/analytics/*, /api/public/analytics/*)   │
└──────────────────────────────────────────────────────────────────┘
                                 │
┌──────────────────────────────────────────────────────────────────┐
│  CONTROLLERS                                                     │
│   AnalyticsController          (7 endpoints + 5 dead methods)    │
│   LinkController               (analytics, getClicksData)        │
│   LinkMetaController           (sparkline, trend, preview, ...)  │
│   PublicLinkController         (basicAnalytics)                  │
│   RedirectController           (write path)                      │
└──────────────────────────────────────────────────────────────────┘
                                 │
┌──────────────────────────────────────────────────────────────────┐
│  SERVICES                                                        │
│   LinkAnalyticsService     [1656 LoC! ⚠ 12-monolith-refactor]    │
│   UserAgentAnalyticsService                                      │
│   MetricsService                                                 │
│   LinkTrackingService                                            │
│   LinkService                                                    │
│   LinkAuditService                                               │
└──────────────────────────────────────────────────────────────────┘
                                 │
┌──────────────────────────────────────────────────────────────────┐
│  REPOSITORIES                                                    │
│   LinkRepository                                                 │
│   ChartRepository              (queries de agregação)            │
└──────────────────────────────────────────────────────────────────┘
                                 │
┌──────────────────────────────────────────────────────────────────┐
│  MODELS                                                          │
│   Click            (30+ colunas — geo, device, temporal, ...)    │
│   Link             (clicks counter denormalizado, slug cache)    │
│   LinkUtm                                                        │
│   LinkAudit                                                      │
└──────────────────────────────────────────────────────────────────┘
                                 │
┌──────────────────────────────────────────────────────────────────┐
│  DATABASE                                                        │
│   PostgreSQL 15                                                  │
│   Redis 7 (cache de slug, métricas, metadata)                    │
└──────────────────────────────────────────────────────────────────┘
```

## Pontos de divergência entre tracking (write) e analytics (read)

Tabela de campos enriquecidos pelo `LinkTrackingService` que **não são lidos** pelos services de analytics:

| Campo gravado | Onde é gravado | Lido por? |
|---------------|----------------|-----------|
| `local_time` | enrichTemporalData | **Não** — temporal queries usam `EXTRACT(HOUR FROM created_at)` em UTC |
| `is_weekend` | enrichTemporalData | Parcial — `getWeekendVsWeekday` calcula novamente em vez de usar |
| `is_business_hours` | enrichTemporalData | Parcial — `getBusinessHoursAnalysis` calcula novamente |
| `hour_of_day` | enrichTemporalData | Sim, `getHourlyPatternsLocal` |
| `day_of_week` | enrichTemporalData (ISO 1-7) | Conflito — queries usam DOW 0-6 |
| `is_return_visitor` | analyzeVisitorBehavior | `getReturnVisitorRate` (insights) |
| `session_clicks` | analyzeVisitorBehavior | `getSessionDepthAnalysis` |
| `click_source` | analyzeVisitorBehavior | `getTrafficSourceAnalysis` |
| `response_time` | collectPerformanceData | **Não** — `calculateRealResponseTime` usa heurística em vez do valor real |
| `accept_language` | collectPerformanceData | `getLanguageDistribution` |
| `iso_code` | resolveDetailedLocation | Sim, mas `LinkController::analytics` re-deriva via `substring(country, 0, 2)` (errado!) |
| `currency` | resolveDetailedLocation | Apenas em `getTopCountriesOptimized` (não exibido sempre) |
| `continent` | resolveDetailedLocation | **Sim aqui mas não exposto no response geographic** — FE espera `continents`, BE não envia |

**Conclusão**: o pipeline de tracking enriquece dados que os queries de leitura ignoram, recalculando em runtime. Significa custo extra de I/O e perda de coerência. Ver `12-monolith-refactor.md` para o caminho de fix (consolidar em `ClickAggregator`).

## Referências por audit

- **01-redirect-tracking.md** — write path detalhado
- **02-dashboard.md** — read dashboard
- **03-heatmap.md** — read heatmap
- **04-geographic.md** — read geographic
- **05-temporal.md** — read temporal (mais profundo, 3 conventions DOW)
- **06-audience.md** — read audience (Math.random crítico)
- **07-insights.md** — read insights
- **08-performance.md** — read performance (4 campos fantasma)
- **09-public-analytics.md** — read public (segurança)
- **10-broken-endpoints.md** — runtime errors
- **11-naming-conventions.md** — patterns
- **12-monolith-refactor.md** — split do LinkAnalyticsService
