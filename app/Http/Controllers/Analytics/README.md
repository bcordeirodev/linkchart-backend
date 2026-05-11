# Analytics

## Propósito

Expõe analytics avançados por link para o usuário autenticado. Cada endpoint corresponde a
uma visão analítica diferente (dashboard, geográfico, temporal, audiência, insights, legado)
e delega a computação para uma hierarquia de serviços especializados. O controller é thin:
verifica ownership, chama o orchestrator e devolve o payload JSON.

## Feature espelhada no frontend

`frontend-next/src/features/analytics/`, `frontend-next/src/features/public-analytics/`

## Endpoints

| Verb | Path | Controller@action | Middleware (route-specific) | Auth |
|---|---|---|---|---|
| GET | /api/analytics/link/{linkId}/dashboard | `AnalyticsController@getLinkDashboardData` | — | required (JWT + verified) |
| GET | /api/analytics/link/{linkId}/comprehensive | `AnalyticsController@getLinkAnalytics` | — | required (JWT + verified) |
| GET | /api/analytics/link/{linkId}/geographic | `AnalyticsController@getGeographicAnalytics` | — | required (JWT + verified) |
| GET | /api/analytics/link/{linkId}/insights | `AnalyticsController@getBusinessInsights` | — | required (JWT + verified) |
| GET | /api/analytics/link/{linkId}/temporal | `AnalyticsController@getTemporalAnalytics` | — | required (JWT + verified) |
| GET | /api/analytics/link/{linkId}/audience | `AnalyticsController@getAudienceAnalytics` | — | required (JWT + verified) |
| GET | /api/links/{id}/analytics | `AnalyticsController@getLinkLegacyAnalytics` | — | required (JWT + verified) |

> `GET /api/links/{id}/analytics` é declarado no grupo de rotas `links/` (linha 96 de
> `routes/api.php`) mas é tratado por este controller, não pelo `LinkController`. Ver nota
> em `app/Http/Controllers/Links/README.md`.
>
> O endpoint `/dashboard` aceita o query param `hours` com valores 1, 24, 168, 720 (0 = all time).
>
> Não existe mais um endpoint `/heatmap` — os dados foram incorporados ao `/geographic`
> no commit `00e6a3f`.

Todos os endpoints estão sob `api.auth:api` + `verified` (definidos no grupo fechado de
`routes/api.php` linha 84).

## Services e Repositories

- `LinkAnalyticsOrchestratorInterface` → `LinkAnalyticsOrchestrator` (bound em
  `AppServiceProvider`) — fan-out para os serviços especializados abaixo:
  - `DashboardAnalyticsService` (impl. de `DashboardAnalyticsInterface`) — métricas básicas
    + dados de charts para o dashboard.
  - `GeographicAnalyticsService` (impl. de `GeographicAnalyticsInterface`) — breakdowns por
    país, cidade e continente; inclui heatmap data (folded in desde commit `00e6a3f`).
  - `TemporalAnalyticsService` (impl. de `TemporalAnalyticsInterface`) — dados avançados:
    weekly/monthly trends, peak analysis, timezone analysis (enriquecido com percentuais
    no controller), heatmap_data, daily_timeline, device_by_period.
  - `AudienceAnalyticsService` (impl. de `AudienceAnalyticsInterface`) — device breakdown,
    browser/OS distribution, mobile/desktop/tablet split, engagement metrics.
  - `InsightsAnalyticsService` (impl. de `InsightsAnalyticsInterface`) — Strategy Pattern
    com `InsightGeneratorRegistry` e 8 generators instanciados inline:
    `GeographicInsightGenerator`, `DeviceInsightGenerator`, `TemporalInsightGenerator`,
    `PerformanceInsightGenerator`, `DiversityInsightGenerator`, `SecurityInsightGenerator`,
    `EngagementInsightGenerator`, `RetentionInsightGenerator`.
- `TemporalAnalyticsInterface` → `TemporalAnalyticsService` — injetado diretamente no
  controller para `getTemporalAnalytics`, que mescla o payload base do orchestrator com
  dados avançados.
- `MetricsService` — métricas de usuário/link para usos internos; `clearUserMetricsCache`
  usa `Cache::forget` com padrão de wildcard, o que é **no-op** em drivers de cache que não
  suportam listagem de chaves (ex: Redis sem LUA habilitado, ou `array` driver em testes).
- `ChartRepository` — agregações SQL usadas pelos serviços de analytics; pesado em `clicks`.

## Jobs disparados

Nenhum job é disparado diretamente pelo `AnalyticsController`. Os dados são computados
sob demanda nas queries dos serviços.

## Cache

Sem cache centralizado gerenciado por este controller. Cada serviço especializado pode
manter seu próprio cache interno. O `MetricsService` usa as chaves
`metrics:user:{userId}:*` e `metrics:link:*`.

## Pontos de atenção

- **Heatmap não é mais um endpoint separado**: `/api/analytics/link/{linkId}/heatmap` foi
  removido e os dados foram incorporados ao `/geographic` no commit `00e6a3f`. Não recriar
  a rota sem garantir que o frontend espera os campos no lugar certo.

- **Adicionar um novo insight**: implementar `InsightGeneratorInterface` em
  `app/Services/Analytics/Insights/Generators/`, adicionar ao `InsightGeneratorRegistry`
  em `InsightsAnalyticsService::__construct()`. O `InsightsAnalyticsService` instancia os
  generators inline (injeção via Service Container foi adiada — R-15 pendente). Os 8
  generators atuais são: Device, Diversity, Engagement, Geographic, Performance, Retention,
  Security, Temporal.

- **Queries pesadas na tabela `clicks`**: os serviços de analytics fazem agregações SQL
  extensas. Os índices de performance relevantes estão em
  `database/migrations/2025_09_14_140100_add_performance_indexes_simple.php`. Mudanças
  no schema de `clicks` devem verificar se os índices existentes ainda cobrem as queries.

- **Rate limit de analytics públicos**: o endpoint `GET /api/public/analytics/{slug}` do
  `PublicLinkController` (não deste controller) tem throttle `public-analytics` de 30/min
  por IP — definido em `AppServiceProvider::boot()`. Este controller não tem rate limit
  próprio além do middleware de autenticação.

- **`getLinkLegacyAnalytics` retorna JSON bruto**: diferente dos demais endpoints que usam
  `{ success: true, data: ... }`, o legado retorna diretamente os campos
  (`total_clicks`, `clicks_over_time`, etc.) sem envelope. O shape muda quando não há cliques
  (`has_sufficient_data: false`). Manter compatibilidade backward.

- **`clearUserMetricsCache` é no-op em stores sem suporte a wildcard**: `Cache::forget`
  não suporta glob/wildcard no driver padrão. Em produção com Redis, o comportamento
  depende da configuração do Redis. Não confiar nessa função para invalidação garantida.
