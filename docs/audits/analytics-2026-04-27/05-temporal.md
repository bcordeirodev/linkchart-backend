# Temporal Analytics — Audit

## Scope

Auditoria do módulo **temporal** do Link Charts:

- **Endpoint:** `GET /api/analytics/link/{linkId}/temporal` (route `routes/api.php:121` → `AnalyticsController@getTemporalAnalytics`).
- **Response unificado** com 2 origens diferentes:
  1. `LinkAnalyticsService::getLinkTemporalAnalytics` (base: `clicks_by_hour`, `clicks_by_day_of_week`, `hourly_patterns_local`, `weekend_vs_weekday`, `business_hours_analysis`).
  2. `UserAgentAnalyticsService::getAdvancedTemporalAnalytics` (advanced: `weekly_trends`, `monthly_trends`, `peak_analysis`, `timezone_analysis`).
- Pós-processamento `enrichTimezoneAnalysis` no controller calcula `percentage`.
- Frontend: hook `useTemporalData`, componentes `TemporalAnalysis`, `TemporalChart`, `TemporalInsights`, `TemporalTrendsChart`, `PeakAnalysisCard`, `TimezoneDistributionChart` e tipos em `temporal.ts`.

Fora de escopo: outras tabs do dashboard (geographic, audience, insights), `getLinkDashboardAnalytics` (apenas reusa `getTemporalAnalyticsOptimized`).

## Data Flow

### Backend

```
GET /api/analytics/link/{id}/temporal
        │
        ▼
AnalyticsController::getTemporalAnalytics
        │
        ├──▶ LinkAnalyticsService::getLinkTemporalAnalytics(linkId)
        │        │
        │        └──▶ getTemporalAnalyticsOptimized(linkId)
        │                  ├── getClicksByHourOptimized        → SQL EXTRACT(HOUR FROM created_at)  → 0-23, preenche zeros
        │                  ├── getClicksByDayOfWeekOptimized   → SQL EXTRACT(DOW FROM created_at)   → 0-6 (Postgres: Sun=0)
        │                  ├── getHourlyPatternsLocal          → coluna hour_of_day (timezone-aware), só horas com cliques
        │                  ├── getWeekendVsWeekday             → boolean is_weekend
        │                  └── getBusinessHoursAnalysis        → boolean is_business_hours
        │
        ├──▶ UserAgentAnalyticsService::getAdvancedTemporalAnalytics(linkId)
        │        │
        │        └──▶ Click::where('link_id', $linkId)->get()   ⚠️ CARREGA COLEÇÃO INTEIRA EM MEMÓRIA
        │                  ├── getHourlyPatterns($clicks)      → loop PHP em UTC      (returns array<int,int>)
        │                  ├── getDailyPatterns($clicks)       → loop PHP, format('w') (Sun=0..Sat=6)
        │                  ├── getWeeklyTrends($clicks)        → loop PHP, format('Y-W')
        │                  ├── getMonthlyTrends($clicks)       → loop PHP, format('Y-m')
        │                  ├── getPeakAnalysis($clicks)        → reusa hourly + daily patterns
        │                  └── getTimezoneAnalysis($clicks)    → coluna timezone (não local_time)
        │
        ├──▶ enrichTimezoneAnalysis(timezone_analysis)          → injeta percentage no controller
        │
        └──▶ array_merge(baseData, ['advanced' => […]])
```

Persistência: `LinkTrackingService::enrichTemporalData` grava em `clicks` colunas pré-calculadas `hour_of_day`, `day_of_week`, `local_time`, `is_weekend`, `is_business_hours`, `day_of_month`, `month`, `year` (ver migração `2025_09_11_130817_add_enhanced_tracking_to_clicks_table` + índice `idx_clicks_temporal_enhanced`).

### Frontend

```
TemporalAnalysis (page wrapper)
   ├── useTemporalData({ linkId })  → GET /api/analytics/link/{id}/temporal
   │     └── api client desembrulha { data } → TemporalData
   │     └── calculateStats() → peakHour/peakDay/avg/trend (recalcula localmente)
   └── TemporalChart
         ├─ Tab 0  Padrões Gerais  (clicks_by_hour + clicks_by_day_of_week)
         ├─ Tab 1  Hora Local       (hourly_patterns_local)
         ├─ Tab 2  Fim de Semana    (weekend_vs_weekday)
         ├─ Tab 3  Horário Comercial(business_hours_analysis)
         ├─ Tab 4  Picos            → PeakAnalysisCard         (advanced.peak_analysis)
         ├─ Tab 5  Tendências       → TemporalTrendsChart      (advanced.weekly_trends + monthly_trends)
         └─ Tab 6  Fusos            → TimezoneDistributionChart(advanced.timezone_analysis)
```

`TemporalInsights.tsx` existe mas não é renderizado (insights estão inlinados no `TemporalChart`). Cast `(data as any)` em `hourly_patterns_local`/`weekend_vs_weekday`/`business_hours_analysis` indica que o tipo `TemporalData` declara esses campos como opcionais, mas o componente não confia no tipo.

## Findings

### Crítico

1. **Inconsistência de `day_of_week` entre fontes (3 convenções diferentes co-existem!).**
   - `LinkTrackingService::enrichTemporalData` (linha 284, 303): `format('N')` → ISO 1-7 (Mon=1..Sun=7), e `is_weekend = in_array($dayOfWeek, [6, 7])` (Sáb+Dom em ISO).
   - `LinkAnalyticsService::getClicksByDayOfWeekOptimized` (linha 301): `EXTRACT(DOW FROM created_at)` → Postgres 0-6 (Sun=0..Sat=6), com `dayNames = ['Domingo', 'Segunda', ..., 'Sábado']` indexado por DOW. **Não usa a coluna `day_of_week` pré-calculada.**
   - `UserAgentAnalyticsService::getDailyPatterns` (linha 354): `format('w')` → 0-6 (Sun=0..Sat=6), em **UTC** (sem timezone do visitante). Também não usa coluna pré-calculada.
   - `getPeakAnalysis` consome `getDailyPatterns` e mapeia em `dayNames = ['Domingo'..'Sábado']` (indexado por w) — funciona por coincidência (DOW=w=0..6).
   - Resultado: `clicks_by_day_of_week` (DOW UTC), `peak_analysis.peak_day` (w UTC) e a coluna persistida `day_of_week` (N local) **não batem**. Um query analítico que use `WHERE day_of_week = 6` retorna sábado em uma fonte e sexta em outra.
   - `getWeekendVsWeekday` (`is_weekend = true/false`) depende do valor em escrita (ISO 6/7) — consistente com a definição em `enrichTemporalData`, mas divergente da resposta `clicks_by_day_of_week`.

2. **N+1 / scan completo: `getAdvancedTemporalAnalytics` carrega `Click::where('link_id', $linkId)->get()` inteiro em memória** (`UserAgentAnalyticsService.php:117`) e itera em PHP para gerar 6 estruturas (hourly, daily, weekly, monthly, peak, timezone). Em links com 100k+ cliques isso causa OOM e p95 catastrófico. Tudo poderia virar 4 queries SQL agrupadas (`GROUP BY EXTRACT(...)`, `to_char(created_at,'IYYY-IW')`, `to_char(created_at,'YYYY-MM')`, `timezone`).

3. **`hourly_patterns_local` é o ÚNICO consumidor das colunas pré-computadas (`hour_of_day`, `is_weekend`, `is_business_hours`).** Os charts principais (`clicks_by_hour`, `clicks_by_day_of_week`) re-extraem do `created_at` (UTC) e ignoram o `local_time` persistido. O esforço de enrichment em `LinkTrackingService` está sendo desperdiçado em ~80% dos endpoints temporais. Consequência: usuário do Brasil acessando às 23h UTC (20h local) cai no "horário comercial UTC" e não no "fora do comercial" local — exibição errada na tab "Padrões Gerais".

### Importante

4. **Controller mescla 2 services manualmente via `array_merge` + lógica de negócio embutida (`enrichTimezoneAnalysis`).** É um antipattern que vaza orquestração para a camada HTTP. Deveria existir um `TemporalAnalyticsService` (ou `TemporalAggregator`) que recebe `linkId` e devolve a estrutura final pronta. A migração para NestJS herda essa dívida diretamente — controllers no Nest devem ser thin handlers.

5. **Duplicação de "padrões por hora" e "padrões por dia da semana".**
   - `getClicksByHourOptimized` (LinkAnalyticsService) → SQL, formato `[{hour, clicks, label}]` 24 entradas, **fill com 0**.
   - `getHourlyPatterns` (UserAgentAnalyticsService) → PHP loop, retorna `array<int,int>` (24 valores), apenas no `peak_analysis`. Mesma semântica, dois formatos.
   - `getClicksByDayOfWeekOptimized` vs `getDailyPatterns` → mesma duplicação. Inconsistência de fill (DB SQL preenche 7 dias, PHP só observados).

6. **`AdvancedTemporalData.peak_analysis` é objeto, mas tipo TS está marcado como objeto puro — sem fallback se não houver dados.** Em `UserAgentAnalyticsService::getPeakAnalysis`, se `$clicks->isEmpty()`, `max($hourly)` retorna 0 e `array_keys($hourly, 0)[0] = 0`, gerando `peak_hour=0, peak_day='Domingo'` — falso positivo. Frontend (`PeakAnalysisCard`) calcula `(peak_hour_clicks / peak_day_clicks) * 100` → divisão por zero retorna `NaN%` na UI.

7. **`weekly_trends`/`monthly_trends` retornam `Record<string,number>` (objeto), não array ordenado.** Frontend faz `Object.entries().sort()` em runtime. Para ordenação estável e i18n, melhor o backend devolver array `[{period, clicks}]` ordenado.

8. **`enrichTimezoneAnalysis` no controller (linha 495–512)** existe só porque o `UserAgentAnalyticsService::getTimezoneAnalysis` retorna sem `percentage`. Em vez de fazer no service (junto da query SQL agregada), fica num helper privado da Controller — code smell. Aliás, ao migrar para SQL com `COUNT(*) * 100.0 / SUM(COUNT(*)) OVER()` (já usado em `getBrowserDistribution`, linha 1358), a função desaparece.

9. **`getTimezoneAnalysis` agrupa por coluna `timezone` (string IANA) sem normalização** — `America/Sao_Paulo` e `America/Sao_Paulo` (idêntico) ok, mas `UTC` vs `Etc/UTC` vs `null` entram como buckets distintos (null fica fora). E `formatArray` aplica `arsort` duas vezes (uma em `getTimezoneAnalysis`, outra em `formatArray`).

10. **Frontend faz cast `(data as any)?.hourly_patterns_local`** (`TemporalAnalysis.tsx:154-156`) apesar do tipo `TemporalData` ter os campos. Indica refator incompleto — esses casts devem ser removidos.

11. **`TemporalInsights.tsx` é dead code** — não é importado em lugar nenhum (apenas no `index.ts` da pasta). Toda a lógica foi inlineada no `TemporalChart.tsx` (linhas 583-752). Componente extraído mas nunca substituiu o original.

12. **`useTemporalData::calculateStats` recalcula no frontend o que `peak_analysis` do backend já fornece.** `peakHour` e `peakDay` ficam duas vezes (uma do stats, outra do `data.advanced.peak_analysis`). `TemporalAnalysis.tsx` linha 36-43 já mostra a tensão — usa fallback `data?.advanced?.peak_analysis?.peak_hour ?? stats?.peakHour`. Como o backend é fonte da verdade, o cálculo no hook é redundante.

13. **`is_business_hours` no backend é hardcoded `9–17` (linha 294 LinkTrackingService) e em `getBusinessHoursAnalysis` é apresentado como `09:00-17:00`** — mas em `TemporalChart.tsx:107-112` o cálculo de `isBusinessHoursActive` no frontend usa `9–18`. Definição inconsistente.

### Minor

14. **`TemporalChart.tsx` tem 1018 linhas** — viola a regra do projeto (`< 200 linhas por componente`, ver `frontend/.cursorrules`). Já tem extrações (`PeakAnalysisCard`, `TemporalTrendsChart`, `TimezoneDistributionChart`), mas as Tabs 0-3 deveriam virar 4 sub-componentes (`HourlyPatternsTab`, `WeekdayPatternsTab`, `WeekendTab`, `BusinessHoursTab`).

15. **`UseTemporalDataOptions` no hook tem `includeAdvanced: boolean` marcado como `@deprecated` mas ainda é prop obrigatório** consumido no `TemporalAnalysis.tsx:31`. Limpar.

16. **Tipos duplicados:** `TemporalStats` em `temporal.ts` (linhas 162-176, com `peak_hour/peak_day/most_active_period`) E `TemporalStats` em `useTemporalData.ts` (linhas 10-17, com `peakHour/peakDay/trendDirection`) — nomes idênticos, shapes diferentes, em arquivos diferentes. Confusão garantida ao importar.

17. **`UseTemporalDataReturn` em `temporal.ts` tem `changePeriod: (config) => void`** mas o hook não implementa. Tipo desatualizado.

18. **Hardcoded `dayNames` em pt-BR** em `LinkAnalyticsService.php:308` e `UserAgentAnalyticsService.php:395`. Backend deveria devolver índice + chave i18n; frontend traduz.

19. **`getAdvancedTemporalAnalytics` retorna `hourly_patterns` e `daily_patterns` mas o controller só consome `weekly_trends`, `monthly_trends`, `peak_analysis`, `timezone_analysis`** (linhas 219-222). Métodos `getHourlyPatterns`/`getDailyPatterns` ficam usados apenas internamente em `getPeakAnalysis`. Computação desperdiçada.

20. **`getTemporalAnalyticsOptimized` em `LinkAnalyticsService` é referenciado em 3 lugares (`getComprehensiveLinkAnalytics`, `getLinkTemporalAnalytics`, `getLinkDashboardAnalytics`)** — mas o `getLinkDashboardAnalytics` apenas extrai `clicks_by_hour/day_of_week`, ignorando hourly_patterns/weekend/business — faz overhead desnecessário no dashboard.

21. **`TemporalChart` calcula `isWeekendActive` localmente (`weeklyData[0].clicks + weeklyData[6].clicks > ...`)** assumindo DOW (Sun=0, Sat=6), mas o usuário pode receber DayOfWeekData com day=0..6 (DOW) ou outra convenção — frágil. Já existe `weekend_vs_weekday` no payload, deveria ser preferido.

22. **`PeakAnalysisCard` divide `peak_hour_clicks / peak_day_clicks * 100`** semanticamente questionável: peak_hour é por hora e peak_day é por dia — não são comparáveis diretamente (a hora é um subconjunto do dia). Métrica enganosa em UI.

## Recommendations

**Refatoração dirigida (alinhada com migração NestJS):**

1. **Criar `TemporalAggregatorService` único** que orquestra base + advanced. Controller fica thin (auth → service → response). Em NestJS isso vira `TemporalAnalyticsService` injetável.
2. **Eliminar todas as queries `Click::get()->each(...)` em PHP** — converter `getHourlyPatterns/Daily/Weekly/Monthly/Peak/Timezone` para SQL agregado (4 queries, todas com `GROUP BY` + index nos campos relevantes). Reaproveitar índice existente `idx_clicks_temporal_enhanced(hour_of_day, day_of_week)`.
3. **Padronizar `day_of_week` em UM formato único** (sugestão: ISO 1-7 conforme já está persistido). Atualizar `getClicksByDayOfWeekOptimized` para `WHERE day_of_week IS NOT NULL GROUP BY day_of_week` em vez de `EXTRACT(DOW FROM created_at)`. Atualizar `peak_analysis.peak_day` para usar a mesma fonte. Documentar a convenção em `temporal.ts`.
4. **Usar `local_time`/`hour_of_day` como fonte primária** dos cliques por hora — não mais `EXTRACT(HOUR FROM created_at)` que é UTC. Caso contrário, o tracking enrichment vira teatro.
5. **Mover `enrichTimezoneAnalysis` para SQL** (`COUNT(*)*100.0/SUM(COUNT(*)) OVER()`) e deletar do controller.
6. **Consolidar tipos `TemporalStats`** num único arquivo, decidindo se vem do backend ou é derivado no frontend. Recomendado: backend devolve, frontend só renderiza.
7. **Trends devem ser arrays ordenados** `[{period: '2026-W17', clicks: 42}]` em vez de `Record<string,number>` para evitar `Object.entries().sort()` no frontend e permitir paginação.
8. **Quebrar `TemporalChart.tsx`** em 4 sub-componentes de tabs e mover lógica de "padrões" para utils puros (`temporalAnalysis.ts` em utils).
9. **Adicionar guards** em `getPeakAnalysis` (retornar `null` quando `$clicks->isEmpty()`) e fallback no frontend (`PeakAnalysisCard`).
10. **Remover `TemporalInsights.tsx` (dead code)** ou substituir o trecho inline em `TemporalChart`.
11. **Padronizar definição de horário comercial (9-17 ou 9-18) em uma constante única** (config) consumida tanto no enrichment quanto no frontend.

## For the Fix Agent

- **Files**:
  - Backend:
    - `/Users/bruno/Projects/link-charts/backend/app/Http/Controllers/Analytics/AnalyticsController.php` (remover `enrichTimezoneAnalysis`, simplificar `getTemporalAnalytics`)
    - `/Users/bruno/Projects/link-charts/backend/app/Services/Analytics/LinkAnalyticsService.php` (`getTemporalAnalyticsOptimized`, `getClicksByHourOptimized`, `getClicksByDayOfWeekOptimized`, `getHourlyPatternsLocal`, `getWeekendVsWeekday`, `getBusinessHoursAnalysis`)
    - `/Users/bruno/Projects/link-charts/backend/app/Services/Analytics/UserAgentAnalyticsService.php` (substituir loops PHP por SQL agregado em `getHourlyPatterns`, `getDailyPatterns`, `getWeeklyTrends`, `getMonthlyTrends`, `getPeakAnalysis`, `getTimezoneAnalysis`)
    - `/Users/bruno/Projects/link-charts/backend/app/Services/Links/LinkTrackingService.php` (validar `is_business_hours`, alinhar com config)
    - **NEW**: `/Users/bruno/Projects/link-charts/backend/app/Services/Analytics/TemporalAggregatorService.php` (extrair orquestração)
  - Frontend:
    - `/Users/bruno/Projects/link-charts/frontend/src/types/analytics/temporal.ts` (consolidar `TemporalStats`, remover `changePeriod` órfão)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/hooks/useTemporalData.ts` (remover `includeAdvanced`, simplificar `calculateStats`)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/temporal/TemporalChart.tsx` (split em 4 sub-componentes; preferir `weekend_vs_weekday` aos cálculos locais)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/temporal/TemporalAnalysis.tsx` (remover casts `as any`)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/temporal/PeakAnalysisCard.tsx` (guard contra `peak_day_clicks=0`)
    - **DELETE**: `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/temporal/TemporalInsights.tsx` (dead code)
- **Tests**:
  - Backend (PHPUnit):
    - `tests/Feature/Analytics/TemporalAnalyticsTest.php` (atualmente inexistente) — assegurar que `clicks_by_day_of_week`, `peak_analysis.peak_day` e coluna persistida `day_of_week` apontam para o mesmo dia para um mesmo cliente.
    - Cobrir caso `clicks empty` retornando shape vazio (não erro NaN).
    - Cobrir timezone-aware: cliente com `timezone='America/Sao_Paulo'` clicando às 23h UTC deve cair em hora local 20h.
  - Frontend: não há suite. Validar manualmente as 7 tabs com dados reais.
- **Migration**: **no** (schema atual já tem todas as colunas necessárias — `idx_clicks_temporal_enhanced` cobre `hour_of_day`, `day_of_week`). Eventualmente adicionar índice `(link_id, day_of_week)` e `(link_id, hour_of_day)` se SQL agregado virar quente.
- **Estimated effort**: **L** (Large)
  - Refator do backend: ~2-3 dias (consolidar service, reescrever 6 métodos PHP→SQL, unificar `day_of_week`).
  - Refator do frontend: ~1-2 dias (split do `TemporalChart`, consolidar tipos).
  - Testes Feature do agregador: ~0.5 dia.
- **Dependencies**:
  - Auditoria de **Audience** (compartilha conceito `is_business_hours`/`is_weekend`).
  - Auditoria de **Insights** (`generateBusinessInsightsOptimized` consome `peak_hour` — pegada de `clicks` em UTC vs local).
  - Migração para NestJS: o novo `TemporalAggregatorService` deve já nascer com interface `ITemporalAnalyticsService` em `app/Contracts/Services/` para facilitar o port.

## Out of Scope

- Análises de retenção, sessões, fontes de tráfego (`getReturnVisitorRate`, `getSessionDepthAnalysis`, `getTrafficSourceAnalysis`) — pertencem ao módulo Insights.
- `getLinkDashboardAnalytics` (Dashboard tab) — auditoria do Dashboard.
- Cache layer (`Link::findActiveBySlugCached`) e Redis — fora de analytics.
- Performance global de redirect (`/r/{slug}`) — não relacionado a temporal.
- `RedirectMetricsCollector` middleware — telemetria, não analytics.
- Persistência (`ProcessLinkClickJob`, `LinkTrackingService::registrarCliqueFromPayload`) — auditoria de Tracking.
- Frontend `chartFormatters.ts`, `ApexChartWrapper`, `MetricCard` — componentes shared, fora do escopo deste módulo.
