# Refactor do monolito `LinkAnalyticsService` — Audit cross-cutting

## Scope

`LinkAnalyticsService.php` tem **1656 linhas** e concentra responsabilidades de 8 sub-módulos analíticos diferentes (dashboard, heatmap, geographic, temporal, audience, insights, performance, executive summary). Viola SRP e o limite de complexidade do projeto. Este doc propõe split em domain services menores.

## Estado atual

**Arquivo:** `app/Services/Analytics/LinkAnalyticsService.php`

**Métodos públicos (15+):**
- `getComprehensiveLinkAnalytics`
- `getLinkDashboardAnalytics`
- `getLinkTemporalAnalytics`
- `getLinkGeographicAnalytics`
- `getLinkAudienceAnalytics`
- `getLinkInsightsAnalytics`
- `getOverviewMetricsOptimized`
- ... (mais ~10 métodos `*Optimized`)

**Métodos privados (~40+):**
- 6 helpers temporais (`getHourlyPatternsLocal`, `getWeekendVsWeekday`, `getBusinessHoursAnalysis`, ...)
- 5 helpers de audience (`getBrowserDistribution`, `getOSDistribution`, `getDevicePerformance`, `getLanguageDistribution`, `extractPrimaryLanguage`)
- 4 helpers geo (`getTopCountriesOptimized`, `getTopStatesOptimized`, `getTopCitiesOptimized`, `getHeatmapDataOptimized`)
- 3 calculadores de score (`calculateRealResponseTime`, `calculateRealSuccessRate`, `calculatePerformanceScore`, `calculateUptimePercentage`)
- 1 gerador de insights monolítico (`generateBusinessInsightsOptimized` — ~10 tipos de insight num só método)
- 3 análises avançadas (`getReturnVisitorRate`, `getSessionDepthAnalysis`, `getTrafficSourceAnalysis`)
- 2 parsers UA (`extractBrowserFromUserAgent`, `extractOSFromUserAgent` — duplicados em outros services)

## Problemas

1. **Tamanho** (1656 linhas) — fora do limite de cognição da maioria das ferramentas e do humano. PRs de mudança são impossíveis de revisar adequadamente.
2. **SRP violado** — agrega 8 domínios analíticos não relacionados.
3. **Acoplamento implícito** — métodos privados misturados, fica difícil saber qual helper serve qual feature.
4. **Duplicação** — parsing de UA está em 3 lugares (`LinkTrackingService::parseUserAgent` + `UserAgentAnalyticsService::extractBrowser/OS` + `LinkAnalyticsService::extractBrowserFromUserAgent`); helpers temporais duplicam funcionalidade de `UserAgentAnalyticsService`.
5. **Testabilidade** — qualquer teste unitário precisa do construtor inteiro, e mocking de dependências fica complexo.
6. **Cache strategy mal compartilhada** — algumas queries têm cache, outras não, sem padrão claro.

## Proposta de split

### Estrutura proposta

```
app/Services/Analytics/
├── LinkAnalyticsOrchestrator.php    (era LinkAnalyticsService — ~150 linhas, só fan-out)
├── Modules/
│   ├── DashboardAnalyticsService.php       (~250 linhas)
│   ├── HeatmapAnalyticsService.php         (~200 linhas)
│   ├── GeographicAnalyticsService.php      (~250 linhas)
│   ├── TemporalAnalyticsService.php        (~300 linhas, consolida com UserAgentAnalyticsService temporais)
│   ├── AudienceAnalyticsService.php        (~250 linhas)
│   ├── PerformanceAnalyticsService.php     (~200 linhas — heurísticas renomeadas para `estimate*`)
│   └── ExecutiveSummaryService.php         (~150 linhas)
├── Insights/
│   ├── BusinessInsightGeneratorRegistry.php   (registry pattern)
│   ├── Generators/
│   │   ├── GeographicInsightGenerator.php
│   │   ├── AudienceInsightGenerator.php
│   │   ├── TemporalInsightGenerator.php
│   │   ├── PerformanceInsightGenerator.php
│   │   ├── DiversityInsightGenerator.php
│   │   ├── SecurityInsightGenerator.php
│   │   ├── EngagementInsightGenerator.php
│   │   ├── RetentionInsightGenerator.php
│   │   ├── SessionDepthInsightGenerator.php
│   │   └── TrafficSourceInsightGenerator.php
│   └── Contracts/
│       └── InsightGenerator.php (interface)
├── Support/
│   ├── UserAgentParser.php       (consolida parsing de UA — fonte única)
│   ├── ClickAggregator.php       (queries genéricas reutilizáveis em todos os services)
│   └── EmptyStateBuilder.php     (helper para estruturas vazias consistentes)
└── Contracts/
    ├── DashboardAnalyticsServiceInterface.php
    ├── HeatmapAnalyticsServiceInterface.php
    ├── ... (uma por module service)
```

### Princípios

1. **Cada module service é autocontido** — depende apenas de `Repository` + `Support/*` + `Cache`. Sem chamadas cruzadas entre module services.
2. **`LinkAnalyticsOrchestrator`** apenas fan-out: recebe a request agregada (ex: `getComprehensiveLinkAnalytics`) e chama os module services em paralelo.
3. **`InsightGenerator` strategy pattern** — adicionar novo insight é criar nova classe, sem tocar no service. Registry resolve por contexto.
4. **`UserAgentParser`** é fonte única — `LinkTrackingService` (no momento da gravação) e os module services (na hora do read se precisar) usam o mesmo parser.
5. **`ClickAggregator`** centraliza queries comuns (`countByLinkId`, `groupByCountry`, `groupByDevice`, etc.) com cache configurável por chamador.

### Migração incremental

**Fase 1 — Extração isolada (cada PR ~1 service):**

| PR | Escopo | Riscos |
|----|--------|--------|
| #1 | Extrair `UserAgentParser` (Support) e fazer todos os 3 lugares atuais consumirem | Baixo — refactor mecânico com regression tests |
| #2 | Extrair `ClickAggregator` (Support) — métodos `groupBy*` reutilizáveis | Baixo |
| #3 | Extrair `DashboardAnalyticsService` | Médio — endpoint dashboard precisa de regression test |
| #4 | Extrair `HeatmapAnalyticsService` | Baixo — endpoint isolado |
| #5 | Extrair `GeographicAnalyticsService` | Médio — preserva top_countries/states/cities |
| #6 | Consolidar Temporal (Link + UserAgent) em `TemporalAnalyticsService` único | Alto — controller atual mergeia 2 services. Ver `05-temporal.md` |
| #7 | Extrair `AudienceAnalyticsService` (consolidar UA parsing) | Médio |
| #8 | Refactor de Insights → registry + generators | Alto — 10 tipos de insight, lógica complexa. Ver `07-insights.md` |
| #9 | Extrair `PerformanceAnalyticsService` + renomear `calculate*Real*` → `estimate*` | Médio |
| #10 | Renomear `LinkAnalyticsService` → `LinkAnalyticsOrchestrator`, simplificar para fan-out | Baixo (após todos os outros) |

**Fase 2 — Pós split:**
- Adicionar interfaces (`*ServiceInterface`) e bind no container Laravel.
- Adicionar testes unitários por service.
- Adicionar feature tests para preservar shape de cada endpoint.

### Rollback plan

Cada PR é incremental. Se um module service introduz regressão, revert só do PR específico. O orchestrator mantém retrocompatibilidade via fan-out durante toda a migração.

## Recommendations (priorizadas)

1. **[HIGH]** Aprovar este plano antes de executar fixes pontuais — alguns fixes (ex: temporal DOW, audience UA parsing) são naturalmente endereçados pelo split. Ordem importa.
2. **[HIGH]** Começar por `UserAgentParser` (PR #1) — desbloqueia múltiplos audits sem mudar comportamento.
3. **[MEDIUM]** Definir interfaces antes da extração (TDD-friendly).
4. **[MEDIUM]** Criar feature test snapshot do response de **cada endpoint analytics** antes de começar — preserva regressão em shape e valores.
5. **[LOW]** Após split, considerar se faz sentido mover para `app/Domain/Analytics/` no estilo DDD (separar de outros services).

## For the Fix Agent

- **Files** (impacto principal):
  - `backend/app/Services/Analytics/LinkAnalyticsService.php` (1656 linhas — split)
  - `backend/app/Services/Analytics/UserAgentAnalyticsService.php` (consolida em TemporalAnalyticsService)
  - `backend/app/Services/Links/LinkTrackingService.php` (consume novo `UserAgentParser`)
  - `backend/app/Http/Controllers/Analytics/AnalyticsController.php` (atualiza injeções)
  - `backend/app/Providers/AppServiceProvider.php` ou novo `AnalyticsServiceProvider.php` (bindings)
- **Tests**: snapshot tests por endpoint antes de começar (feature). Unit tests por module service depois.
- **Migration**: nenhuma (refactor puro).
- **Estimated effort**: **XL** (~3-4 sprints se executado em fases). Cada PR é S-M individualmente.
- **Dependencies**:
  - Fixes de `06-audience.md` (Math.random) **independem** do refactor — fazer antes.
  - Fixes de `01-redirect-tracking.md` (click_limit, response_time) **independem** — fazer antes.
  - Audit `11-naming-conventions.md` (humps no ApiClient) — pode ser feito em paralelo.
  - Outros audits (dashboard, heatmap, geographic, temporal, audience, insights, performance) **devem ser endereçados durante a fase de extração** correspondente, não em PRs separados — evita refazer.

## Out of Scope

- Migrar para DDD layout (`app/Domain/...`).
- Eventos de domínio (publicar `LinkClickRecorded` etc).
- Cache strategy unificada (CQRS, materialized views) — discussão futura.
