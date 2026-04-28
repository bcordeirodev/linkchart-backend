# Dashboard Analytics — Audit

## Scope

**Endpoint principal**
- `GET /api/analytics/link/{linkId}/dashboard` (auth JWT, `where linkId [0-9]+`) — `routes/api.php:116`.

**Backend**
- `app/Http/Controllers/Analytics/AnalyticsController.php` — método `getLinkDashboardData` (linhas 454-487), helper `enrichTimezoneAnalysis` (495-512).
- `app/Services/Analytics/LinkAnalyticsService.php` — método público `getLinkDashboardAnalytics` (1114-1196) e helpers privados `getOverviewMetricsOptimized` (45), `getTemporalAnalyticsOptimized` (92), `getGeographicAnalyticsOptimized` (79), `getAudienceAnalyticsOptimized` (107), `calculateRealResponseTime` (1201), `calculateRealSuccessRate` (1226).
- `app/Repositories/ChartRepository.php` — **não é usado** pelo endpoint `dashboard` (queries do dashboard são feitas inline via `\DB::table('clicks')` no service).

**Frontend**
- `src/pages/links/LinkAnalyticsPage.tsx` — usa `LinkAnalyticsTabsOptimized` que monta o `LinkDashboard` na primeira tab.
- `src/features/links/components/analytics/LinkAnalyticsTabs.tsx:124` — instancia `<LinkDashboard linkId={...} />`.
- `src/features/analytics/components/dashboard/LinkDashboard.tsx` (267 linhas — **acima do limite de 200 das regras `.cursorrules`**).
- `src/features/analytics/components/dashboard/cards/{LinkInfoCard,TimeframeSelector}.tsx`.
- `src/features/analytics/components/dashboard/charts/{HourlyClicksChart,DayOfWeekChart,DeviceBreakdownChart,TopCountriesChart}.tsx`.
- `src/features/analytics/hooks/useDashboardData.ts`.
- `src/services/analytics.service.ts` — método `getLinkPerformance` (55-120) consome o **mesmo endpoint**.
- `src/types/analytics/dashboard.ts` — `DashboardData`, `DashboardSummary`, `DashboardLink`, `ActivityData`, `GeographicSummary`, `DeviceSummary`, `PerformanceIndicator`.
- `src/types/analytics/performance.ts` — `LinkPerformanceDashboard` (forma alternativa do mesmo payload).
- `src/features/links/components/LinkMetrics.tsx` — render das 4 metric cards (cliques totais, links, etc.).
- `src/features/analytics/utils/dataValidation.ts` — `checkDataAvailability`.

## Data Flow

### Backend

1. `routes/api.php:116` → grupo `auth:api,verified` → `AnalyticsController@getLinkDashboardData($linkId)`.
2. `AnalyticsController.php:457` resolve `auth()->guard('api')->id()` e valida posse do link via `Link::where('id',$linkId)->where('user_id',$userId)->first()` (`:464-472`). Sem cache.
3. Em `:475`, chama `LinkAnalyticsService::getLinkDashboardAnalytics($linkId)`.
4. `LinkAnalyticsService.php:1114` busca `Link::find($linkId)` **de novo** (duplicação) e, se não existir, retorna shape vazio (`:1118-1143`). O controller já validou posse — esta segunda checagem é redundante e não respeita `user_id`.
5. `:1146-1152` — três queries independentes:
   - `Click::where('link_id',$linkId)->count()` (total).
   - `Click::where('link_id',$linkId)->distinct('ip')->count()` (uniqueVisitors).
   - `Click::where('link_id',$linkId)->whereNotNull('country')->where('country','!=','localhost')->distinct('country')->count()` (countriesReached).
6. `:1168` — `getTemporalAnalyticsOptimized($linkId)` chama em sequência (`:92-102`):
   - `getClicksByHourOptimized` — `EXTRACT(HOUR FROM created_at)` agrupado, preenche 24 buckets (`:273-293`).
   - `getClicksByDayOfWeekOptimized` — `EXTRACT(DOW)` em 7 buckets (`:298-320`).
   - `getHourlyPatternsLocal` — agrega por coluna pré-computada `hour_of_day` (`:1530-1553`).
   - `getWeekendVsWeekday` — duas queries com `is_weekend` (`:1558-1594`).
   - `getBusinessHoursAnalysis` — duas queries com `is_business_hours` (`:1599-1639`).
7. `:1171` — `getGeographicAnalyticsOptimized` dispara 4 queries: heatmap, top_countries, top_states, top_cities (`:79-87`).
8. `:1174` — `getAudienceAnalyticsOptimized` dispara 7 queries: device, browser, os, browser_distribution, os_distribution, device_performance, language (`:107-119`).
9. `:1177` — `calculateRealResponseTime([$linkId], $totalClicks)` (`:1201-1221`) — query adicional sobre últimas 24h e **retorna número fixo derivado de heurística**: `180 / 120 / 250 / 320 / etc`. **Não é tempo de resposta real.**
10. `:1178` — `calculateRealSuccessRate([$linkId])` (`:1226-1251`) — combina `is_active` + cliques nas últimas 6h em ratio ponderado. **Não é taxa de sucesso de redirect real.**
11. Controller envelopa em `{success: true, data: <payload>}` (`:478`) — `NormalizeApiResponse` middleware re-envelopa em `{data}` final.

**Total: 17-19 queries por request, sem cache aplicado.** Em picos, o controller concorre com o caminho crítico de redirect no mesmo Postgres.

### Frontend

1. Rota `/links/:id/analytics` → `LinkAnalyticsPage` (`:22`) → `LinkAnalyticsTabsOptimized` → `LinkDashboard`.
2. `LinkDashboard.tsx:55` chama `useDashboardData({linkId, enableRealtime, timeframe, refreshInterval:60000})`.
3. `useDashboardData.ts:48` monta query string `?hours={1|24|168|720}&include_charts=true` — **ambos os parâmetros são ignorados pelo backend** (controller não lê `Request`).
4. `:81` `api.get<ApiResponse>(fullEndpoint)` — o `ApiClient` desembrulha o envelope `{data}` automaticamente (comentário "Onda 0").
5. `:87` `mapResponseToDashboardData(response)` (`:228-265`):
   - Copia `summary`, `link_info`, `temporal_data`, `geographic_data`, `audience_data` direto.
   - Sintetiza `geographic_summary` a partir do **primeiro item** de `top_countries` (`:248`) e zera `cities_reached` e `coverage_percentage` (`:250-251`).
   - Sintetiza `device_summary` filtrando `device_breakdown` por strings hardcoded `'Desktop' | 'Mobile' | 'Tablet'` (`:253-256`) — mismatch com o que o backend grava (capitalização varia de acordo com user-agent parser).
   - `mobile_percentage` é sempre `0` (`:257`).
   - `top_links: response.top_links || []` — **backend nunca retorna esse campo** no payload de dashboard de link individual.
   - `recent_activity: response.recent_activity || []` — **idem**, sempre vazio.
   - `performance_indicators: []` — placeholder, sempre vazio.
6. `:90` `calculateStats(dashboardData)` (`:270-292`) — define `dataQuality` por buckets de `total_clicks` e zera `alertsCount`/`recommendationsCount`.
7. `LinkDashboard.tsx:108` passa `data?.summary` para `<LinkMetrics>` que mostra **4 cards**: total_links, active_links, total_clicks, avg_clicks_per_link. `avg_clicks_per_link` **não vem do backend** — é calculado no frontend (`LinkMetrics.tsx:38`).
8. `LinkDashboard.tsx:151-186` (função `renderCharts`) faz mais transformações:
   - `top_countries` é remapeado: `iso_code: c.country?.substring(0, 2).toUpperCase() || 'XX'` — **inventa um ISO code** descartando o `iso_code` real que o backend já retorna em `geographic_data.top_countries[].iso_code` (a tipagem em `dashboard.ts:33` não inclui `iso_code`, então o frontend nem enxerga).
   - `top_cities` recebe `state: 'Unknown', country: 'Unknown'` hardcoded (`:168-169`), descartando os campos reais retornados pelo backend.
9. Componentes de chart consomem `HourlyData`, `DayOfWeekData`, `CountryData`, `DeviceData` (importados de `@/types`).
10. `useDashboardData` é também chamado pela rota global (legacy) — o tipo `ApiMetrics`/`ApiCharts` em `:177-189` carrega forma antiga; é dead-code para o caminho de link individual.

**Caminho paralelo (`getLinkPerformance`):** `useLinkPerformance` → `analyticsService.getLinkPerformance(linkId)` (`analytics.service.ts:55`) consome o **mesmo endpoint** e devolve `LinkPerformanceDashboard` com **3 nomes para o mesmo conceito de cliques únicos**: `uniqueClicks`, `unique_visitors`, `summary.unique_visitors`. Pior: `clicksToday: metrics.total_clicks` (`:85`) atribui o total para "today" sem filtro temporal — campo cosmético sem semântica.

## Findings

### 🔴 Crítico

- **`success_rate` e `avg_response_time` são heurística disfarçada de métrica real** — `app/Services/Analytics/LinkAnalyticsService.php:1201-1251`
  Os métodos `calculateRealResponseTime` e `calculateRealSuccessRate` não medem nada real:
  - `calculateRealResponseTime` retorna constantes (`120 / 180 / 250 / 320`) escolhidas por buckets de volume e contagem de "horas de pico". Não há medição de latência HTTP em lugar nenhum no fluxo de redirect (`/r/{slug}` é a rota crítica e ela não persiste tempo de resposta na Click). O frontend exibe esse valor como `avg_response_time` em ms.
  - `calculateRealSuccessRate` mistura `is_active` do link com "atividade nas últimas 6h" — um link ativo sem cliques recentes vira "70%". Nenhuma resposta HTTP do redirect é amostrada para falha/sucesso. **`LinkPerformanceDashboard.success_rate` e `summary.success_rate` no frontend são placeholders que parecem dados.**
  - Impacto: usuário toma decisões com números fabricados. Viola a intenção de "Real Data" da auditoria.

- **Backend ignora `hours` e `include_charts` mas o frontend muda timeframe via URL** — `useDashboardData.ts:50-53` × `AnalyticsController.php:454-487`
  O hook envia `?hours=1|24|168|720&include_charts=true` ao trocar `TimeframeSelector`. O controller **não lê `Request`**, ignora os params e sempre retorna agregação histórica completa. Resultado: o seletor "1h / 24h / 7d / 30d" no UI é puramente visual — todas as opções devolvem o mesmo dataset. O `metadata={isRealtime ? 'Tempo Real' : timeframe}` exibido em `LinkDashboard.tsx:82` mente para o usuário.
  - Impacto: feature visível e clicável que não funciona.

- **Propriedade `$advancedAnalyticsService` não declarada no controller** — `app/Http/Controllers/Analytics/AnalyticsController.php:324, 352, 380, 408, 436`
  Cinco endpoints (`getBrowserAnalytics`, `getRefererAnalytics`, `getEngagementAnalytics`, `getPerformanceByRegion`, `getTrafficQualityReport`) chamam `$this->advancedAnalyticsService->...` mas a propriedade **não existe no construtor** (`:18-21`). Qualquer hit nesses endpoints lança `Error: Undefined property`. Não afeta o `getLinkDashboardData` em si, mas está no mesmo controller e qualquer refactor do dashboard tropeça nisso.
  - Impacto: rotas vizinhas quebradas em produção; risco de regressão em qualquer mudança no controller.

### 🟡 Importante

- **`device_summary` no frontend depende de strings exatas `'Desktop' / 'Mobile' / 'Tablet'`** — `useDashboardData.ts:253-256`
  O backend grava `device` direto da coluna em `clicks` (depende do parser `jenssegers/agent`, que pode devolver `'desktop'`, `'mobile'`, `'phone'`, `'tablet'` em casos diversos). O `.find((d) => d.device === 'Desktop')` é case-sensitive. Em muitas instalações isso devolve `0` para todos os 3 buckets.
  - Impacto: `device_summary.{desktop,mobile,tablet}` quase sempre zero; `mobile_percentage` é literalmente hardcoded a `0` (`:257`).

- **`top_links`, `recent_activity`, `performance_indicators` nunca são populados pelo backend para link individual** — `LinkAnalyticsService.php:1180-1195` × `dashboard.ts:9-15`
  O contrato `DashboardData` exige esses campos como obrigatórios (não-opcionais). O backend nunca os envia em `getLinkDashboardAnalytics`. O hook preenche com `[]` e o `calculateStats` lê `recent_activity?.length` para `trendsAvailable` (sempre `false`). Tipos contratuais não refletem o payload real.
  - Impacto: tipo TS mente; consumidores que confiam em `top_links.length > 0` como guard quebram silenciosamente.

- **`LinkPerformanceDashboard` tem 3 nomes para cliques únicos** — `src/types/analytics/performance.ts:13,19,42`
  No mesmo tipo coexistem `uniqueClicks?`, `unique_visitors?` e `summary.unique_visitors?`. Análogo: `totalClicks?` vs `total_redirects_24h?` vs `summary.total_redirects_24h?`. O service os preenche **todos com o mesmo `metrics.total_clicks`** (`analytics.service.ts:83-106`), mistura camelCase e snake_case, e marca tudo opcional. É um "tipo cobertor" que aceita qualquer shape.
  - Impacto: ambiguidade no consumo, bug-fertile, dificulta migração para Prisma onde DTOs precisam ser explícitos.

- **`top_countries[].iso_code` é descartado e re-inventado pelo frontend** — `LinkDashboard.tsx:163`
  Backend (`LinkAnalyticsService.php:190-198`) já devolve `iso_code` real (`'BR'`, `'US'`). O tipo `dashboard.ts:33` declara apenas `{ country, clicks }`, omitindo `iso_code`. O `renderCharts` faz `iso_code: c.country?.substring(0, 2).toUpperCase()` — ou seja, "Brasil" vira `'BR'` (coincidência), mas "Estados Unidos" vira `'ES'` e "Reino Unido" vira `'RE'`. Bug silencioso quando algum chart renderizar bandeira via ISO.

- **Duplicação de fonte de truth: dashboard endpoint × `getLinkPerformance`** — `analytics.service.ts:55` vs `useDashboardData.ts:77`
  Os dois caminhos batem no mesmo `GET /api/analytics/link/{id}/dashboard` mas mapeiam o response em formatos diferentes (`DashboardData` vs `LinkPerformanceDashboard`). Mantém duas tipagens para o mesmo payload — qualquer mudança no backend exige alterar dois mappers.

- **Sem cache no service** — `LinkAnalyticsService.php:1114-1196`
  17-19 queries são executadas a cada request, mesmo para o mesmo link no mesmo segundo. Outros caminhos do projeto usam `Cache::remember` (ex.: `Link::findActiveBySlugCached`, preview). O dashboard concorre com o redirect no Postgres em links virais.
  - Impacto: latência alta + carga concorrente com a rota crítica `/r/{slug}`.

- **Validação de posse executada duas vezes** — `AnalyticsController.php:464-472` + `LinkAnalyticsService.php:1117-1143`
  Controller filtra `Link::where('id',...)->where('user_id',$userId)`. Service refaz `Link::find($linkId)` (sem filtro de owner) e degrada para shape vazio em caso de não existir. Lógica conflitante: se alguém chamar o service direto (ex.: artisan, queue, teste), recupera dados sem auth check.

- **Componente acima do limite de linhas** — `LinkDashboard.tsx` tem 267 linhas; `.cursorrules` define teto de 200.
  A função `renderCharts` (`:149-265`) deveria ser um componente próprio. A transformação inline duplica lógica que já existe em `dataValidation.ts`.

### 🟢 Minor

- **`distinct('ip')->count()` em Postgres é `COUNT(*) OVER DISTINCT ip)` semanticamente, mas via Eloquent gera `SELECT COUNT(DISTINCT ip)`** — `LinkAnalyticsService.php:1147`. OK funcionalmente, mas em links com muitos cliques fica caro sem índice em `ip`. Confirmado que `2025_09_14_140100_add_performance_indexes_simple.php` cria `idx_clicks_link_date`, `idx_clicks_geo`, `idx_clicks_user_agent`, `idx_clicks_referer` — **não há índice em `(link_id, ip)`** nem em colunas usadas no dashboard como `device`, `browser`, `os`, `is_weekend`, `is_business_hours`, `hour_of_day`.

- **`refresh()` ignora `enableRealtime`** — `useDashboardData.ts:107-111`. Aciona `setLoading(true)` mesmo quando atualização é em background; pode causar skeleton flash em modo realtime.

- **`HourlyClicksChart` importa `HourlyData` de `@/types`, não do tipo do dashboard** — `HourlyClicksChart.tsx:12`. O backend devolve `{ hour, clicks, label }` e o tipo `dashboard.ts:28` casa exatamente, mas o chart consome `HourlyData` de outro arquivo (`temporal.ts`). Acoplamento cruzado.

- **`AnalyticsService.getAnalytics()` não é usado pelo dashboard** mas convive no mesmo arquivo (`analytics.service.ts:22-50`). Fallback "AnalyticsData" é shape antigo. Apenas ruído visual.

- **Chave `is_active`, `created_at` em `link_info` não tipadas como Date no TS** — `dashboard.ts:23`. `created_at: string`, ok, mas `LinkInfoCard.tsx` não formata.

- **Catch genérico em controller** (`AnalyticsController.php:481-486`) — vaza `$e->getMessage()` para o cliente. Em produção pode expor query/path. Boa prática: log + mensagem genérica.

- **`recent_activity?.length || 0 > 0`** em `useDashboardData.ts:289` é dead code: backend nunca envia. `trendsAvailable` é hardcoded `false`.

## Recommendations (priorizadas)

1. **[HIGH] Decidir o destino de `success_rate` e `avg_response_time`.** Ou (a) instrumenta-se o redirect (`RedirectController` + `ProcessLinkClickJob`) para gravar latência real e flag de sucesso na Click, e o dashboard agrega isso; ou (b) remove-se os campos do payload e do tipo. Manter o estado atual é dívida técnica que vira fraude analítica para o usuário.

2. **[HIGH] Honrar `?hours=` no backend ou remover o `TimeframeSelector`.** Adicionar `Request $request` ao controller, validar `hours ∈ {1,24,168,720}`, propagar para o service como `->where('created_at','>=', now()->subHours($hours))`. Caso contrário, esconder o seletor.

3. **[HIGH] Corrigir/remover propriedade `$advancedAnalyticsService` no controller.** Ou injetar o serviço (e criá-lo) ou comentar/remover os 5 endpoints quebrados. Bloqueia qualquer refactor no arquivo.

4. **[HIGH] Unificar tipagem de cliques únicos / total.** Eliminar `LinkPerformanceDashboard` ou redesenhá-lo como wrapper minimalista de `DashboardData`. Padronizar para `snake_case` (alinhado ao backend) ou camelCase (com mapper único). Atual mistura é insustentável na migração para Prisma/NestJS.

5. **[MEDIUM] Tipar `iso_code`, `state`, `country`, `state_name` em `geographic_data` e remover transformação em `LinkDashboard.renderCharts`.** Backend já entrega; frontend só precisa propagar.

6. **[MEDIUM] Remover ou popular `top_links`, `recent_activity`, `performance_indicators` em `getLinkDashboardAnalytics`.** Como é dashboard de **link individual**, `top_links` provavelmente deve sumir; `recent_activity` (cliques agrupados por dia nos últimos 7d) é candidato natural a popular usando `ChartRepository::clicksByDay($days=7, linkId=$linkId)` que já existe.

7. **[MEDIUM] Padronizar device matching.** Backend deveria normalizar para lowercase em `getDeviceBreakdownOptimized`; frontend consome com `.toLowerCase()`. Hoje `desktop !== Desktop` quebra `device_summary`.

8. **[MEDIUM] Adicionar cache TTL curto (30-60s) em `getLinkDashboardAnalytics`.** Chave `link:{id}:dashboard:{userId}`. Invalida em `Click::saved`/`Link::saved`. Reduz contenção com a rota crítica.

9. **[MEDIUM] Quebrar `LinkDashboard.tsx` em `LinkDashboardCharts.tsx` + `useDashboardChartData.ts`.** O componente está em 267 linhas, viola `.cursorrules` (teto 200). Mover `renderCharts` + transformações para um hook ou componente filho. Reutilizar `checkDataAvailability` que já existe.

10. **[MEDIUM] Adicionar índices Postgres para colunas de agregação:** `(link_id, device)`, `(link_id, browser)`, `(link_id, os)`, `(link_id, is_weekend)`, `(link_id, is_business_hours)`, `(link_id, hour_of_day)`. Considerar índice parcial em `latitude IS NOT NULL AND country != 'localhost'` para o heatmap.

11. **[LOW] Consolidar validação de posse no service**: aceitar `userId` como parâmetro ou retornar `null`/exception em vez de payload zerado. Hoje há divergência entre service e controller.

12. **[LOW] Substituir `catch (\Exception $e) { ... 'message' => $e->getMessage() }` por log + mensagem genérica.** Padrão `NormalizeApiResponse` já formata erros — controller pode `throw` e deixar o middleware fazer o trabalho.

13. **[LOW] Remover dead-code legacy** em `useDashboardData.ts` (`ApiMetrics`, `ApiCharts`) e a função `getEmptyPerformanceData` (`analytics.service.ts:125-159`) duplicada com o constructor de fallback.

14. **[LOW] Cobrir com testes**: backend `LinkAnalyticsServiceTest` para `getLinkDashboardAnalytics` (caminho com cliques, sem cliques, link inexistente, posse de outro user). Frontend pode ao menos testar o `mapResponseToDashboardData` (puro, fácil) — instalar Vitest é decisão pendente.

## For the Fix Agent

- **Files**:
  - Backend: `app/Http/Controllers/Analytics/AnalyticsController.php`, `app/Services/Analytics/LinkAnalyticsService.php`, `database/migrations/<new>_add_dashboard_indexes.php`.
  - Frontend: `src/features/analytics/hooks/useDashboardData.ts`, `src/features/analytics/components/dashboard/LinkDashboard.tsx`, `src/types/analytics/dashboard.ts`, `src/types/analytics/performance.ts`, `src/services/analytics.service.ts`, `src/features/analytics/hooks/useLinkPerformance.ts`.
- **Tests**:
  - Backend: criar `tests/Unit/Services/Analytics/LinkAnalyticsServiceTest.php` cobrindo `getLinkDashboardAnalytics` (cenários: link sem cliques, link com cliques, link inexistente). Suite roda com SQLite `:memory:` — heatmap e queries com `EXTRACT(DOW)` precisam de adaptação ou skip em SQLite.
  - Backend: feature test `Analytics/DashboardEndpointTest` cobrindo 401 (sem auth), 404 (link de outro user), 200 com payload completo.
  - Frontend: sem runner; gate é `npm run quality`. Validação manual obrigatória do TimeframeSelector pós-fix.
- **Migration**: **yes** — nova migration de índices `(link_id, device)`, `(link_id, browser)`, `(link_id, os)`, `(link_id, is_weekend)`, `(link_id, is_business_hours)`, `(link_id, hour_of_day)`, `(link_id, ip)`. Sem `DROP/ALTER` em coluna existente, baixo risco.
- **Estimated effort**: **M** (3-5 dias)
  - HIGH 1+2+3: 1.5 dia (decisão de produto + remoção/instrumentação + validação `Request`).
  - HIGH 4 + MEDIUM 5+6+7: 1.5 dia (refactor de tipos + mappers).
  - MEDIUM 8+9+10: 1 dia (cache, split de componente, índices).
  - LOW 11-14: 0.5-1 dia.
- **Dependencies**:
  - Decisão de produto sobre `success_rate` / `avg_response_time` (instrumentar redirect ou remover). **Bloqueante** para HIGH 1.
  - Confirmar se `top_links` deve mesmo sair do payload de dashboard de link individual (audit do consumer global pendente).
  - Migração para Prisma/NestJS — qualquer mudança em DTOs é custo direto na reescrita; preferir snake_case alinhado ao schema.
  - Eventual fix do `$advancedAnalyticsService` pode ter dependência circular se exigir restaurar um service deletado — verificar histórico do git.

## Out of Scope

- Endpoints `/comprehensive`, `/heatmap`, `/geographic`, `/insights`, `/temporal`, `/audience` (auditados separadamente).
- `getComprehensiveLinkAnalytics` e o gerador de insights (`generateBusinessInsightsOptimized`) — usados em outras tabs.
- Telemetria do redirect e schema da tabela `clicks` (cobertos no audit do redirect).
- Migração 1:1 para NestJS+Prisma — apontamentos relevantes ficam como nota nos itens HIGH 4 e MEDIUM 8.
- Frontend test runner (Vitest/Jest) — decisão de tooling fora do escopo.
- Componente `LinkAnalyticsTabsOptimized` e demais tabs — só o ponto de instanciação foi observado.
