# Performance Analytics — Audit

## Scope

Módulo **LinkPerformance** — métricas de performance do redirect (response time, success rate, uptime, performance score, clicks/hora, retenção). Cobre:

- **Backend:** `LinkAnalyticsService::getLinkDashboardAnalytics`, `MetricsService::getUserPerformanceMetrics`/`getUserBasicMetrics`/`getUserLinkMetrics`, `LinkMetaController` (sparkline, trend, preview, health, batchMeta).
- **Frontend:** `features/analytics/components/perfomance/` (typo) — `PerformanceAnalysis.tsx`, `PerformanceMetrics.tsx`; hook `useLinkPerformance`; `analyticsService.getLinkPerformance`; tipo `LinkPerformanceDashboard`.
- **Endpoint consumido:** `GET /api/analytics/link/{linkId}/dashboard` (mesmo do dashboard — `AnalyticsController::getLinkDashboardData`).
- **Rotas auxiliares:** `GET /api/links/{id}/sparkline|trend|preview|health` e `POST /api/links/batch-meta` (vivem em domínio Link, mas geram dados de natureza performance).

## Data Flow

### Backend

`AnalyticsController::getLinkDashboardData(linkId)` (linha 454) →
`LinkAnalyticsService::getLinkDashboardAnalytics(linkId)` (linha 1114) →
- `Click::count` / `distinct ip` count para `total_clicks` e `unique_visitors`.
- `getTemporalAnalyticsOptimized` / `getGeographicAnalyticsOptimized` / `getAudienceAnalyticsOptimized` agregam por hora/dia/país/cidade/device.
- **`calculateRealResponseTime([linkId], totalClicks)`** (linha 1201) — retorna constante (`120/180/250/320` ms) em função de buckets de volume. **Não consulta `clicks.response_time`.**
- **`calculateRealSuccessRate([linkId])`** (linha 1226) — combina `(active_links / total_links) * 0.7` com `(linksWithRecentClicks_6h / total_links) * 0.3`. Não há erro real medido.
- Resposta: `{ summary: { total_clicks, total_links, active_links, unique_visitors, success_rate, avg_response_time, countries_reached, links_with_traffic }, link_info, temporal_data, geographic_data, audience_data }`.

`MetricsService::getUserPerformanceMetrics` (linha 93) — escopo de **usuário**, não de link individual; usa `calculateAverageResponseTime` (linha 265) que **lê dados reais** do cache `redirect_metrics:hour:{hour}` populado pelo middleware `RedirectMetricsCollector`. Não é invocado pelo endpoint dashboard de link — vive em paralelo.

**Real instrumentation existe em dois lugares e está sendo ignorada pelo endpoint:**
1. `app/Http/Middleware/RedirectMetricsCollector.php` mede `microtime`-based response time da rota `/r/{slug}` e grava em `redirect_metrics:hour:{hour}` (Redis cache). Consumido por `MetricsService::calculateAverageResponseTime` e `MetricsController`, **mas não pelo dashboard do link**.
2. `clicks.response_time` (column populada por `LinkTrackingService` linha 384) é dado real por clique. **Já é lido** em `getDevicePerformance`, `getSessionDepthAnalysis`, `getTrafficSourceAnalysis`, mas **ignorado** no `getLinkDashboardAnalytics`.

### Frontend

`PerformanceAnalysis.tsx` (304 linhas) → `useLinkPerformance({ linkId, enableRealtime, refreshInterval: 60000 })` → `analyticsService.getLinkPerformance(linkId)`.

`getLinkPerformance` chama `GET /api/analytics/link/${linkId}/dashboard` e **adapta** a resposta:
- Lê `response.summary.{total_clicks, unique_visitors, success_rate, avg_response_time}`.
- Mapeia para um objeto `LinkPerformanceDashboard` com **17 campos** — incluindo `performance_score: metrics.success_rate || 0` (proxy enganoso), `uptime_percentage: 100` (hardcoded), `clicks_per_hour: 0`, `visitor_retention: 0`, `total_links: 1`.
- `summary` aninhado é reembrulhado novamente.

`PerformanceAnalysis` lê `performanceData.{total_redirects_24h, unique_visitors, success_rate, avg_response_time, uptime_percentage, performance_score, clicks_per_hour, visitor_retention, total_links}` — todos campos que **nunca chegam preenchidos** do backend exceto via fallback hardcoded em `getLinkPerformance`. UI exibe `100% uptime`, `0 cliques/hora`, `0% retenção`, `score = success_rate` para todos os links.

`PerformanceMetrics.tsx` consome 6 cards: Performance Score, Uptime Real, Cliques/Hora, Retenção, Tempo Resposta, Taxa de Sucesso — cinco dos seis exibem dados que **não existem** ou são heurísticas grosseiras.

`LinkMetaController` (sparkline/trend/health/batchMeta) é consumido por `MetaService` (não auditado aqui — pertence ao módulo Link), e fornece dados reais de `clicks.created_at` agregados — esse fluxo está saudável.

## Findings

### Critico

- **"Real" prefix é mentira (Real != Real).** `LinkAnalyticsService::calculateRealResponseTime` (linha 1201) retorna `180/250/320 ms` por buckets de volume, **sem consultar `clicks.response_time` nem `redirect_metrics:hour`**, apesar de ambos existirem. `calculateRealSuccessRate` (linha 1226) é uma média ponderada de `is_active` com `linksWithRecentClicks_6h`, sem nenhuma medição de erros. Os números mostrados na UI são determinísticos e enganam o usuário a achar que o redirect está em ~180ms. Renomear obrigatoriamente para `estimateResponseTime`/`estimateSuccessRate` ou substituir pela leitura real de `clicks.response_time` + cache `redirect_metrics:hour`.

- **`performance_score`, `uptime_percentage`, `clicks_per_hour`, `visitor_retention` são fantasmas no endpoint dashboard.** Verificado via grep: nenhum dos quatro campos é retornado por `LinkAnalyticsService::getLinkDashboardAnalytics`, `MetricsService` ou `AnalyticsController`. O frontend declara-os no tipo `LinkPerformanceDashboard` e a UI mostra-os (cards "Performance Score", "Uptime Real", "Cliques/Hora", "Retenção"). O adapter em `analytics.service.ts:81-99` os preenche com valores hardcoded:
  - `performance_score: metrics.success_rate || 0` — completamente desconectado de `calculatePerformanceScore` (linha 1256), que existe mas **nunca é chamado**.
  - `uptime_percentage: 100` — hardcoded. `calculateUptimePercentage` (linha 1288) existe mas **nunca é chamado**.
  - `clicks_per_hour: 0`, `visitor_retention: 0` — sempre zero.
  
  UI exibe valores enganosos para todo link (uptime 100%, 0 cliques/hora, score = success_rate). Considerar este caso pior que dados sintéticos: é dado **falso publicado** ao usuário.

- **`MetricsService::getLinkMetrics` tem bug de método inexistente** (linha 162): `now()->va()` no lugar de `now()->subDay()`. Se invocado, dispara `BadMethodCallException`. Atualmente não há consumidor (verificar antes de remover, mas é dead-or-broken code).

### Importante

- **Endpoint compartilhado entre dashboard e performance.** `useLinkPerformance` chama `/api/analytics/link/{id}/dashboard`, o mesmo do `useDashboardData` (módulo dashboard). Coerente em volume de fetch (1 request serve ambos) mas o nome do endpoint sugere "dashboard agregado" — não há endpoint dedicado a performance. Adapter em `analytics.service.ts` faz o trabalho que o backend deveria fazer (preencher `performance_score` etc.). Recomendado: ou (a) endpoint dedicado `/performance` que devolve performance score real, ou (b) `getLinkDashboardAnalytics` retorna **um único shape coerente** com todos os campos que o frontend já espera.

- **`LinkPerformanceDashboard` (TS) — 5 nomes para mesmos conceitos.**
  - `totalClicks` (camelCase) ↔ `total_redirects_24h` (snake_case) — mesmo valor.
  - `uniqueClicks` ↔ `unique_visitors` — mesmo valor.
  - `summary.success_rate` duplica `success_rate` no root.
  - `summary.avg_response_time` duplica `avg_response_time` no root.
  - `summary.total_redirects_24h` duplica `total_redirects_24h` (que já duplica `totalClicks`).
  - 17 campos no root + 6 em `summary` + 6 arrays vazios (`hourly_data`, `link_performance`, `traffic_sources`, `geographic_data`, `device_data`, `clicksOverTime`) que **nunca são populados** pelo `getLinkPerformance`. Dead schema mascarando que o tipo foi desenhado para outra coisa (parece ter sido um aggregate de user, depois forçado para link).

- **`PerformanceAnalysis.tsx` tem 304 linhas** — viola a regra do `.cursorrules` (componente < 200 linhas). Mistura: cálculo de métricas, cards de status atual, cards de sistema, insights de performance — todos inline. Quebrar em `PerformanceStatusCard`, `PerformanceSystemCard`, `PerformanceInsightsCard`.

- **Pasta `perfomance/` (typo).** `frontend/src/features/analytics/components/perfomance/`. Renomear para `performance/` e atualizar imports:
  - `frontend/src/features/links/components/analytics/LinkAnalyticsTabs.tsx:12`
  - `frontend/src/features/analytics/components/index.ts:11`
  - imports internos do `index.ts`/componentes

- **Cache key inconsistency em `MetricsService::getUserPerformanceMetrics`.** O `clearUserMetricsCache` (linha 250) usa `Cache::forget("metrics:user:{userId}:*")` — `Cache::forget` não suporta wildcard no driver Redis padrão. Para invalidar metrics o flush nunca acontece efetivamente. (Isso vaza para outros módulos, mas afeta também as métricas de performance cacheadas por 5min.)

### Minor

- **Typo na pasta `perfomance/`** — `frontend/src/features/analytics/components/perfomance/`. Renomear para `performance/`. Impacto: cosmético, mas confunde imports e busca.

- **`PerformanceAnalysis.tsx:105` exibe título com emoji `📊` direto no JSX** (`title='📊 Métricas de Performance'`); usar `TabDescription` ou ícone MUI alinhado ao design system (já tem `ICON_LG`).

- **`performance.ts` (TS type) tem `LinkPerformanceData` (camelCase) e `LinkPerformanceDashboard` (mistura camel+snake)** — duas formas de tipar performance. `LinkPerformanceMetric`, `PerformanceFilters`, `PerformanceApiResponse` declarados mas **não importados em nenhum lugar** (verificado via grep). Dead types.

- **Comentários enganosos em `analytics.service.ts:81`**: "Adaptar resposta para formato esperado pelo frontend" — o "formato esperado" inclui campos que o backend não envia. O adapter está mascarando o problema, não resolvendo.

- **`PerformanceMetrics.tsx` recebe `data: unknown`** (prop não usada, comentada com `_data`) e `performanceData` com tipo inline duplicado. O tipo já existe em `@/types/analytics/performance.ts` — deveria reusar `Pick<LinkPerformanceDashboard, ...>`.

- **`AnalyticsController::getLinkDashboardData` retorna `{ success: true, data: ... }`** (envelopado), mas o middleware `NormalizeApiResponse` já padroniza isso. Dupla camada de envelope; o cliente em `BaseService` está desembrulhando como espera (Onda 0). Confirmar que não há regressão se retornar só `data: $analytics`.

## Recommendations

1. **Decidir e documentar:** as 4 métricas fantasma (`performance_score`, `uptime_percentage`, `clicks_per_hour`, `visitor_retention`) são (a) features deletadas e a UI deveria removê-las, (b) features pendentes e o backend deveria implementá-las, ou (c) sintéticas e devem ser explicitamente etiquetadas? Sem essa decisão a UI mente para o usuário.

2. **Substituir `calculateRealResponseTime` por leitura real** de `AVG(response_time)` em `clicks` (últimas 24h), com fallback para `redirect_metrics:hour:*` cache. Renomear o método. `LinkAnalyticsService` linha 1201.

3. **Substituir `calculateRealSuccessRate`** — sem instrumentação real de erros (ex: 4xx/5xx no redirect), manter só taxa baseada em `is_active`/expiração e renomear para `linkAvailabilityRate`. Ou instrumentar erros no `RedirectController` e `RedirectMetricsCollector` para gravar `error_count`/`success_count` por hora.

4. **Renomear pasta `perfomance/` → `performance/`** e atualizar 3 imports.

5. **Quebrar `PerformanceAnalysis.tsx`** em `PerformanceStatusCard`, `PerformanceSystemCard`, `PerformanceInsightsCard` (cada um < 100 linhas). Mover lógica de "score color/label" para utilitário.

6. **Consolidar `LinkPerformanceDashboard` (TS)** removendo:
   - duplicatas (`totalClicks`/`total_redirects_24h`, `uniqueClicks`/`unique_visitors`, `summary.*` duplicado).
   - arrays nunca populados pelo `getLinkPerformance` (`hourly_data`, `link_performance`, `traffic_sources`, `geographic_data`, `device_data`, `clicksOverTime`, `topReferrers`, `topCountries`).
   - Manter apenas o subset que o endpoint efetivamente devolve.

7. **Corrigir bug `now()->va()`** em `MetricsService.php:162` — substituir por `now()->subDay()` ou remover método se não consumido.

8. **Endpoint dedicado `/api/analytics/link/{id}/performance`** que retorne shape canônico `{ avg_response_time_ms, success_rate, uptime_pct, clicks_per_hour, return_visitor_rate, performance_score, data_source: 'measured'|'estimated' }` com `data_source` etiquetando origem dos números. Ou expandir `getLinkDashboardAnalytics` para incluir esses campos com a marca de origem.

## For the Fix Agent

- **Files:**
  - `backend/app/Services/Analytics/LinkAnalyticsService.php` — métodos `calculateRealResponseTime` (1201), `calculateRealSuccessRate` (1226), `calculatePerformanceScore` (1256), `calculateUptimePercentage` (1288), `getLinkDashboardAnalytics` (1114).
  - `backend/app/Services/Analytics/MetricsService.php` — bug linha 162 (`now()->va()`).
  - `backend/app/Http/Controllers/Analytics/AnalyticsController.php` — método `getLinkDashboardData` (454) caso decidir ampliar payload.
  - `frontend/src/features/analytics/components/perfomance/` — renomear pasta + atualizar barrel.
  - `frontend/src/features/analytics/components/perfomance/PerformanceAnalysis.tsx` — split em 3 componentes.
  - `frontend/src/features/analytics/components/perfomance/PerformanceMetrics.tsx` — usar tipo central.
  - `frontend/src/features/analytics/components/index.ts` — atualizar export.
  - `frontend/src/features/links/components/analytics/LinkAnalyticsTabs.tsx:12` — atualizar import.
  - `frontend/src/services/analytics.service.ts` — `getLinkPerformance` (55-120) e `getEmptyPerformanceData` (125-159) — remover hardcodes (`uptime_percentage: 100`, `performance_score: success_rate`).
  - `frontend/src/types/analytics/performance.ts` — limpar `LinkPerformanceDashboard`, remover types dead.
  - `frontend/src/features/analytics/hooks/useLinkPerformance.ts` — sem mudança a menos que contrato TS mude.

- **Tests:**
  - Backend tem PHPUnit (RedirectTest, ProcessLinkClickJobTest). Adicionar `LinkAnalyticsServiceTest::getLinkDashboardAnalytics_returnsRealResponseTimeFromClicksTable` cobrindo o caso de `clicks.response_time` populado.
  - Frontend não tem suite. Confiar em `npm run quality` (type-check pega regressões de tipo) + smoke manual: abrir tab Performance de um link com cliques → conferir que valores conferem com `clicks.response_time` no banco.

- **Migration:** **no.** Schema atual já tem `clicks.response_time` (column existente). Mudança é apenas de leitura/agregação no service.

- **Estimated effort:** **M** (medium).
  - Rename de pasta + imports: ~15 min.
  - Bug `now()->va()`: 2 min.
  - Substituir `calculateRealResponseTime`/`calculateRealSuccessRate` por leitura real + renomear: ~1h (com testes).
  - Decisão sobre as 4 métricas fantasma + implementação ou remoção: ~2-4h.
  - Split de `PerformanceAnalysis.tsx`: ~45 min.
  - Limpar tipo `LinkPerformanceDashboard`: ~30 min.
  - Total: ~5-7h se ampliar para implementação real das 4 métricas; ~2-3h se for só remoção da UI fantasma.

- **Dependencies:**
  - Auditoria do dashboard (mesmo endpoint) — alinhar shape de `summary` para não quebrar consumidores duplos.
  - Auditoria de insights — `getReturnVisitorRate` + `getSessionDepthAnalysis` no `LinkAnalyticsService` já calculam dados que poderiam alimentar `visitor_retention`.
  - `RedirectMetricsCollector` middleware — caso decidir trocar fonte de `avg_response_time` para o cache `redirect_metrics:hour`, validar que está habilitado em produção.
  - Migração NestJS — ao reimplementar, evitar replicar os métodos `calculateReal*`; usar Prisma `aggregate({ _avg: { responseTime: true } })` direto na tabela `clicks`.

## Out of Scope

- Implementar instrumentação de erros HTTP no `RedirectController` (registro de 4xx/5xx por link) — necessário para taxa de sucesso real, mas é trabalho de infra, não de analytics.
- Refatorar `MetricsService::clearUserMetricsCache` para suportar wildcards no Redis (cross-cutting; afeta múltiplos módulos).
- Remover/mover `LinkMetaController` para módulo de analytics — atualmente vive em `Links/`. Funciona; não é foco do módulo Performance.
- Substituir `LinkPerformanceData` por `LinkPerformanceDashboard` e remover tipos órfãos (`LinkPerformanceMetric`, `PerformanceFilters`, `PerformanceApiResponse`) — limpeza de TS types morto não afeta runtime.
- Endpoint público de health check / status page (`/health`, `/uptime`) — feature nova, não correção.
