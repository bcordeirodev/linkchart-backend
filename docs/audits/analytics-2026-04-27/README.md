# Analytics Audit — 2026-04-27

Auditoria estrutural do módulo analytics do Link Charts (encurtador + analytics) com mapeamento de bugs, dados sintéticos apresentados como reais, inconsistências FE↔BE e proposta de refactor.

**Escopo:** todos os endpoints e componentes envolvidos com analytics e tracking, em ambos os repos (`backend` Laravel + `frontend` Vite/React).

**Método:** estático — leitura de código, sem queries reais ao DB ou ping de endpoints. Validação real fica para os agentes de fix.

## Estrutura

| # | Doc | Foco |
|---|-----|------|
| 00 | [data-flow.md](./00-data-flow.md) | Pipeline write (tracking) + read (analytics), camadas, divergências |
| 01 | [redirect-tracking.md](./01-redirect-tracking.md) | `/r/{slug}` → ProcessLinkClickJob → INSERT clicks |
| 02 | [dashboard.md](./02-dashboard.md) | Endpoint `/dashboard`, summary, top_links, activity |
| 03 | [heatmap.md](./03-heatmap.md) | Mapa Leaflet, lat/lng, polling realtime |
| 04 | [geographic.md](./04-geographic.md) | Top countries/states/cities, continents |
| 05 | [temporal.md](./05-temporal.md) | clicks_by_hour, day_of_week, peak/timezone |
| 06 | [audience.md](./06-audience.md) | Device, browser, OS, languages |
| 07 | [insights.md](./07-insights.md) | Business Insights, retention, session_depth, traffic_source |
| 08 | [performance.md](./08-performance.md) | Métricas de performance e response time |
| 09 | [public-analytics.md](./09-public-analytics.md) | `/api/public/analytics/{slug}` (sem auth) |
| 10 | [broken-endpoints.md](./10-broken-endpoints.md) | Cross-cutting: 5 métodos quebrados + bug `now()->va()` |
| 11 | [naming-conventions.md](./11-naming-conventions.md) | Cross-cutting: snake_case/camelCase, redundâncias, "Real" prefix |
| 12 | [monolith-refactor.md](./12-monolith-refactor.md) | Cross-cutting: split do `LinkAnalyticsService` (1656 LoC) |

## Sumário executivo

### Saúde do módulo

- **Estrutura geral:** boa fundação (camadas Controller→Service→Repository, DTOs, contracts), mas o serviço principal (`LinkAnalyticsService`) virou monolito de 1656 LoC concentrando 8 domínios. Frontend feature-first é coerente, mas mistura camelCase/snake_case nos types e tem componentes acima do limite de 200 LoC do `.cursorrules`.
- **Cobertura de dados reais:** parcial. O pipeline de tracking enriquece dados ricos (geo, UA, temporal, behavior, performance) mas vários campos exibidos na UI são **sintéticos ou hardcoded**. Há também colunas pré-computadas que os queries de leitura **ignoram**, recalculando em runtime.
- **Naming FE↔BE:** divergente em pontos críticos (`day_of_week` com 3 convenções, `continents` consumido sem ser produzido, prefixo `Real` em métodos que são heurísticas).
- **Segurança:** endpoint `/api/public/analytics/{slug}` expõe analytics + `original_url` de **qualquer link** sem rate limit.

### Top 5 issues mais sérios

1. **Pipeline de Insights mortos no UI** — `getLinkInsightsAnalytics` no service nunca é chamado pelo controller. 3 charts pesados (Retention, SessionDepth, TrafficSource — ~1.7k LoC) estão renderizando vazios. Hotfix de 1h. (`07-insights.md`)
2. **Math.random fabrica métricas no FE** — `useAudienceData.ts:81-84` gera `bounceRate`, `newVisitorRate`, `avgSessionDuration` randomicamente. Hoje os componentes não exibem esses campos (sorte), mas o tipo `AudienceStats` os documenta como reais. (`06-audience.md`)
3. **4 campos fantasma no Performance** — adapter em `analytics.service.ts` hardcoda `uptime_percentage=100`, `performance_score`=success_rate, `clicks_per_hour=0`, `visitor_retention=0`. UI mostra como reais. (`08-performance.md`)
4. **Public analytics expõe `original_url` de qualquer slug** — sem rate limit, sem filtro `is_active`/`expires_at`. Risco de scraping e vazamento. (`09-public-analytics.md`)
5. **`day_of_week` com 3 convenções incompatíveis** — ISO 1-7 no tracking, DOW 0-6 nas queries Postgres, `format('w')` em UTC no UserAgent service. Gráficos podem renderizar pico no dia errado. (`05-temporal.md`)

### Top 5 bugs funcionais

1. **`click_limit` ignorado em `/r/{slug}`** — link com limite continua redirecionando após estourar. (`01-redirect-tracking.md`)
2. **Counter `links.clicks` dessincronizado de `count(clicks)`** — incrementado separado do INSERT, drift contínuo. (`01-redirect-tracking.md`)
3. **`response_time` mede latência da fila, não do redirect** — todas as métricas de "performance" usam esse valor errado. (`01-redirect-tracking.md`, `08-performance.md`)
4. **`TimeframeSelector` é filtro fantasma** — FE envia `?hours=`, BE ignora silenciosamente. Usuário pensa que filtra. (`02-dashboard.md`)
5. **Percentuais inflados no GeographicChart** — divisão contra cliques do país #1 (não do total), todos os outros países parecem maiores. (`04-geographic.md`)

### Inconsistências de runtime

- 5 métodos do `AnalyticsController` referenciam `$this->advancedAnalyticsService` não declarado (dead code, mas explode se rota for adicionada). (`10-broken-endpoints.md`)
- `now()->va()` em `MetricsService.php:162` — typo, fatal error. PHPStan já flagou. (`10-broken-endpoints.md`)
- `getHeatmapDataRealtime` sem rota → polling do FE bate no endpoint pesado a cada 30s. (`03-heatmap.md`, `10-broken-endpoints.md`)
- `getAdvancedTemporalAnalytics` carrega `Click::get()` inteiro em memória (OOM em links grandes). (`05-temporal.md`)

## Tabela de findings por módulo

| Módulo | 🔴 Crítico | 🟡 Importante | 🟢 Minor |
|--------|-----------|---------------|----------|
| 01 Redirect & Tracking | 4 | 7 | 7 |
| 02 Dashboard | 3 | 7 | 7 |
| 03 Heatmap | 3 | 6 | 7 |
| 04 Geographic | 3 | 4 | 3 |
| 05 Temporal | 3 | 6 | — |
| 06 Audience | 3 | — | — |
| 07 Insights | 4 | 4 | — |
| 08 Performance | 1+ | 4+ | — |
| 09 Public Analytics | 1 | 4 | — |

(Counts aproximados — alguns audits têm findings agrupados)

## Roadmap recomendado

Priorização sugerida — combina impacto, esforço e dependências entre módulos.

### Wave 1 — Quick wins independentes (1-3 dias)

PRs pequenos, sem dependências cruzadas, bugs visíveis ao usuário ou erros de runtime.

| Fix | Audit | Esforço | Status |
|-----|-------|---------|--------|
| Plugar `getLinkInsightsAnalytics` no controller (insights mortos) | 07 | S | ✅ `fix(insights)` commit b2ce74c |
| Remover `Math.random` em `useAudienceData.ts` | 06 | S | ✅ removido + `AudienceStats` limpo |
| Corrigir labels do `HeatmapMetrics` (Países/Cidades Únicas) | 03 | S | ✅ `uniqueCountries`/`uniqueCities` expostos |
| Corrigir percentual no `GeographicChart` (denominador) | 04 | S | ✅ usa `stats.totalClicks` (soma real) |
| Remover/marcar como hardcoded os 4 campos fantasma no adapter Performance | 08 | S | ✅ campos removidos do adapter |
| Corrigir `TimeframeSelector` (BE aceitar `?hours=` ou FE não enviar) | 02 | S | ✅ commit 170d88f — `?hours=` honrado no controller/service |
| Remover 5 métodos quebrados do `AnalyticsController` | 10 | S | ✅ `fix(analytics)` commit e499741 |
| Corrigir `now()->va()` em `MetricsService.php:162` | 10 | S | ✅ `fix(metrics)` commit c2d0402 |
| Renomear pasta `perfomance/` → `performance/` | 08, 11 | S | ✅ pasta renomeada |
| Adicionar `throttle:public-analytics` + filtros `is_active`/`expires_at` | 09 | S | ✅ `fix(public-analytics)` commit 4817dcd |
| Decisão de produto: `continents` no BE ou remover do FE | 04, 11 | S+decisão | ✅ gráfico hardcoded removido do `GeographicInsights` |

### Wave 2 — Funcional (1-2 semanas)

Bugs que exigem mudança em pipeline ou semântica.

| Fix | Audit | Esforço | Status |
|-----|-------|---------|--------|
| Validar `click_limit` em `/r/{slug}` antes de redirecionar | 01 | M | ✅ `hasReachedClickLimit()` adicionado ao `RedirectController::redirect` |
| Sincronizar `links.clicks` counter — mover para após `Click::create` no job | 01 | M | ✅ removido do controller, inserido em `LinkTrackingService::registrarCliqueFromPayload` |
| Instrumentar redirect para gravar `response_time` real | 01, 08 | M | ✅ `http_response_ms` calculado com `LARAVEL_START` no controller, passado ao job |
| Renomear `calculate*Real*` → `estimate*` (heurísticas explícitas) | 08, 11 | S | ✅ `estimateResponseTime` + `estimateSuccessRate` |
| Padronizar `day_of_week` ISO 1-7 em todo o pipeline | 05, 11 | M | ✅ `COALESCE(day_of_week, ...)` em `getClicksByDayOfWeekOptimized`; `getDailyPatterns` usa `format('N')`/coluna |
| Refazer queries de temporal para usar colunas pré-computadas (`hour_of_day`) | 05 | M | ✅ `COALESCE(hour_of_day, EXTRACT(HOUR...))` em `getClicksByHourOptimized` |
| Corrigir `getReturnVisitorRate` (clicks vs visitors) e `getTrafficSourceAnalysis` | 07 | M | — pendente |
| Cache do response de public-analytics + decisão de privacy model | 09 | M | — pendente |
| Tipar respostas `Promise<unknown>` no `analyticsService` | 03, 11 | S | — pendente |
| Aplicar humps middleware no `ApiClient` (camelCase normalizado) | 11 | M | — pendente |

### Wave 3 — Refactor estrutural (3-4 sprints)

Mudanças grandes, ordem importa, ver `12-monolith-refactor.md` para plano detalhado.

| Fase | Descrição | Esforço |
|------|-----------|---------|
| 3.1 | Extrair `UserAgentParser` (Support) — fonte única de parsing | S |
| 3.2 | Extrair `ClickAggregator` (Support) — queries reutilizáveis | M |
| 3.3 | Extrair `DashboardAnalyticsService`, `HeatmapAnalyticsService`, `GeographicAnalyticsService` | M+M+M |
| 3.4 | Consolidar Temporal (Link + UserAgent) em service único | M |
| 3.5 | Extrair `AudienceAnalyticsService` | M |
| 3.6 | Refatorar Insights → Strategy pattern com 10 generators dedicados | L |
| 3.7 | Extrair `PerformanceAnalyticsService` | M |
| 3.8 | Renomear `LinkAnalyticsService` → `LinkAnalyticsOrchestrator` (fan-out only) | S |

## Como usar este audit

### Para humanos

Comece pelo `00-data-flow.md` para entender o pipeline completo. Depois leia o módulo específico que vai tocar. Os cross-cutting (10, 11, 12) são referência transversal — leia-os quando atravessarem múltiplos módulos.

### Para agentes de fix

Cada doc por módulo tem uma seção **"For the Fix Agent"** no final, listando:

- **Files** — paths absolutos a modificar
- **Tests** — tipos de teste necessários
- **Migration** — yes/no + descrição
- **Estimated effort** — S (<2h), M (2-8h), L (>8h)
- **Dependencies** — outros módulos que precisam ser fixados antes

**Workflow recomendado por agente:**

1. Ler o doc do módulo + cross-cutting relevantes
2. Validar findings localmente (ler código atual nos paths citados — memória pode estar stale)
3. Criar branch `fix/analytics-<modulo>-<slug>`
4. Implementar Wave 1 fixes do módulo
5. Adicionar testes (snapshot do response antes de mudar shape)
6. PR — referenciar o audit doc no body

**Regras gerais:**

- **Não atravessar entre módulos no mesmo PR.** Cada PR resolve 1 wave de 1 módulo.
- **Não alterar shape de response sem snapshot test antes.**
- **Antes de remover código:** confirmar que não está em uso via grep no FE.
- **Hardcoded fallbacks (continents, uptime=100%, etc.):** preferir REMOVER e renderizar empty-state honesto, não silenciar com placeholder.

## Notas de método

- Audit estático: 9 subagents em paralelo (1 por módulo) lendo código + 4 docs cross-cutting compilados pelo agente principal.
- **Nada de queries reais foi rodado contra o DB.** Findings sobre dados ausentes/sintéticos foram inferidos do código, não de inspeção de tabela.
- Findings de `Math.random`, `hardcoded`, `now()->va()`, e dead code são **certos**.
- Findings de "queries não usam coluna pré-computada" foram validados por leitura — mas o impacto de performance pode variar com volume real.
- **Próximo passo recomendado pós-audit:** rodar feature tests com snapshot de cada endpoint antes de qualquer fix, para baseline de regressão.

## Geração

Branch: `chore/analytics-audit-2026-04-27` (após commit)
Auditor: agente principal + 9 subagents `general-purpose` em paralelo
Modelo: Opus 4.7 (1M context)
Data: 2026-04-27
