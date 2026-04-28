# Heatmap Analytics — Audit

## Scope

Auditoria do módulo **heatmap** de analytics do Link Charts: geração no backend (Laravel) a partir de `Click.latitude/longitude` (preenchidos via GeoIP) e renderização no frontend (React + Leaflet) com polling realtime. Cobre data flow, integridade dos dados (filtros geográficos, tratamento de clicks sem GeoIP), estrutura dos componentes, alinhamento de naming/contrato BE↔FE, tipagem do service e eficiência do polling.

Foco em:
- `GET /api/analytics/link/{linkId}/heatmap` (autenticado)
- `getHeatmapDataRealtime` (sem auth, public-ish)
- `useHeatmapData` hook + componentes em `features/analytics/components/heatmap/`

Out of scope explícito: paleta de cores SP2, regras de zoom/centroide, reprodução manual via browser, métricas de Open Graph, CDN do Leaflet (apenas observado como minor).

## Data Flow

### Backend

1. **Origem dos dados**: cada clique é gravado em `clicks` via `LinkTrackingService::registrarCliqueFromPayload`, enfileirado por `ProcessLinkClickJob`. Os campos `latitude`/`longitude` vêm de `LinkTrackingService::resolveDetailedLocation` em `app/Services/Links/LinkTrackingService.php:159-210` (mapeia `$location->lat` → `latitude`, `$location->lon` → `longitude`).
2. **IPs locais/sem GeoIP**: quando GeoIP não resolve, o serviço retorna `latitude=null, longitude=null, country='localhost'` (`LinkTrackingService.php:161-177`). Esses registros existem na tabela `clicks` mas serão filtrados depois.
3. **Endpoint autenticado**: `routes/api.php:118` → `AnalyticsController@getHeatmapData` (`AnalyticsController.php:57-101`):
   - Verifica posse do link (`user_id == auth()->id()`)
   - Chama `LinkAnalyticsService::getComprehensiveLinkAnalytics($linkId)` e extrai `geographic.heatmap_data`
   - Calcula metadados (`total_clicks`, `unique_countries`, `unique_cities`, `max_clicks`, `total_locations`, `last_updated`, `link_info`)
   - Responde `{ success, data, metadata }`
4. **Endpoint realtime**: `AnalyticsController@getHeatmapDataRealtime` (`AnalyticsController.php:106-134`) — comentário diz "sem autenticação para polling rápido" mas o método continua chamando o mesmo `getComprehensiveLinkAnalytics` (que processa overview + geographic + temporal + audience + insights — caro). **Não há rota registrada para esse método** (`grep` não encontra em `routes/api.php` nem `web.php`).
5. **Service**: `LinkAnalyticsService::getHeatmapDataOptimized` (`LinkAnalyticsService.php:124-165`) — query agregada em `clicks`:
   - `selectRaw` de `latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone, COUNT(*) as clicks, MAX(created_at) as last_click`
   - Filtros: `whereNotNull('latitude')`, `whereNotNull('longitude')`, `whereNotNull('country')`, `country != 'localhost'`, `country != ''`
   - `GROUP BY` de todas as colunas selecionadas (latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone)
   - Mapeia para array com `lat` (float), `lng` (float), `city` (com fallback "Cidade Desconhecida"), `country`, `clicks` (int), `iso_code`, `currency`, `state_name`, `continent`, `timezone`, `last_click`
6. **Caminho mais caro**: `getHeatmapData` invoca `getComprehensiveLinkAnalytics`, que calcula overview + geographic completo + temporal + audience + insights — **só usa `geographic.heatmap_data`**. Desperdício significativo a cada request (e a cada 30s no polling).

### Frontend

1. **Endpoint mapeado**: `lib/api/endpoints.ts:64` define `ANALYTICS_HEATMAP: (linkId) => '/api/analytics/link/${linkId}/heatmap'`.
2. **Service**: `services/analytics.service.ts:225-234` — `getLinkHeatmap(linkId): Promise<unknown>` (sem tipagem). O método **não é chamado** pelo hook real (o hook chama `api.get` direto em `useHeatmapData.ts:167`). Service está órfão.
3. **Hook**: `useHeatmapData.ts` (`features/analytics/hooks/useHeatmapData.ts`):
   - `useHeatmapData({ linkId, enableRealtime, refreshInterval=30000, minClicks=1 })`
   - `getEndpoint()` retorna `/api/analytics/link/${linkId}/heatmap` (linha 129)
   - `fetchHeatmapData` faz `api.get<HeatmapPoint[]>(endpoint)` esperando array desembrulhado (linha 167)
   - Filtra `point.clicks >= minClicks` (linha 183)
   - Calcula `stats` localmente: `totalPoints`, `totalClicks`, `maxClicks`, `topCountry` (primeiro do `Set`, sem ordenação por clicks), `topCity` (idem), `avgClicksPerPoint`, `coveragePercentage = countries.length / 195 * 100`
   - Polling: `setInterval(fetchHeatmapData, refreshInterval)` em `useEffect` (linha 240). Sempre re-busca o payload completo
   - `AbortController` para cancelar requests pendentes
4. **Componentes**:
   - `HeatmapAnalysis.tsx` (105 linhas) — orquestra `HeatmapMetrics` + `HeatmapStats` + `RealTimeHeatmapChart`
   - `RealTimeHeatmapChart.tsx` (**680 linhas** — viola `.cursorrules: componente < 200 linhas`) — carrega Leaflet dinamicamente, renderiza `MapContainer`, `TileLayer`, `CircleMarker`, `Popup`; controles de min_clicks, mapStyle, fullscreen, showClusters (não usado no render)
   - `HeatmapMap.tsx` (270 linhas) — versão alternativa do mapa, **nunca é importada** (apenas re-export em `index.ts:15`). Duplicação morta
   - `HeatmapMetrics.tsx` (107 linhas) — usa `MetricCardOptimized`. Bug: card "Países Únicos" exibe `stats.totalPoints` e "Cidades Únicas" exibe `stats.totalClicks` (linhas 45 e 53 — labels não batem com valores)
   - `HeatmapStats.tsx` (453 linhas — **viola `.cursorrules`**) — calcula top countries/cities locamente, recalcula stats se não recebidas, exibe métricas avançadas (`unique_visitors`, `active_days`, `avg_hour`, `weekend_percentage`, `total_timezones`, `total_continents`) que **o backend não retorna** no payload do heatmap (sempre `undefined`/zero)
   - `HeatmapControls.tsx` (142 linhas) — componente bem isolado mas **nunca importado** em nenhum lugar (apenas re-exportado em `index.ts:13`). Duplicação morta com os controles inline em `RealTimeHeatmapChart.tsx:504-563`

## Findings

### 🔴 Crítico

- **Endpoint realtime declarado mas sem rota** — `app/Http/Controllers/Analytics/AnalyticsController.php:106-134`
  O método `getHeatmapDataRealtime` existe e foi pensado para polling rápido sem auth, mas **nenhuma rota o registra** (verificado em `routes/api.php` e `routes/web.php`). Código morto. Pior: caso fosse exposto, ele permite enumeração de qualquer `linkId` ativo sem autenticação, vazando geolocalização de cliques de usuários alheios. Decidir entre: (a) remover o método e o conceito; (b) registrar a rota e refatorar para usar uma query enxuta (ver próximo item) com rate limit + token assinado.

- **Polling de 30s recalcula analytics completos** — `app/Services/Analytics/LinkAnalyticsService.php:16-40` + `AnalyticsController.php:68`
  `getHeatmapData` chama `getComprehensiveLinkAnalytics`, que executa overview + geographic completo (top_countries, top_states, top_cities) + temporal (clicks_by_hour, clicks_by_day_of_week, hourly_patterns_local, weekend_vs_weekday, business_hours_analysis) + audience (device, browser, os, languages) + insights — quando o consumer só usa `geographic.heatmap_data`. Com `enableRealtime=true` e `refreshInterval=30000` (default), cada link aberto dispara essa cascata a cada 30s. Sem cache. Custo desnecessário em CPU + DB. Expor um método `getHeatmapDataOnly($linkId)` que faz apenas a query de `getHeatmapDataOptimized`.

- **Cards "Países Únicos" e "Cidades Únicas" mostram valores errados** — `frontend/src/features/analytics/components/heatmap/HeatmapMetrics.tsx:45,53`
  Bug visível ao usuário:
  ```tsx
  // id 'unique_countries' → mostra stats.totalPoints (localizações, não países)
  value: stats.totalPoints.toString(),
  // id 'unique_cities' → mostra stats.totalClicks (cliques totais, não cidades)
  value: stats.totalClicks.toString(),
  ```
  O hook calcula `countries` e `cities` mas não os expõe em `HeatmapStats`. Solução: adicionar `uniqueCountries` e `uniqueCities` ao retorno de `calculateStats` em `useHeatmapData.ts:91-119`, ou consumir `metadata.unique_countries`/`unique_cities` que o backend já retorna em `AnalyticsController.php:73-74` mas que o hook ignora (lê apenas `data` desembrulhado).

### 🟡 Importante

- **`getLinkHeatmap` retorna `Promise<unknown>` e está órfão** — `frontend/src/services/analytics.service.ts:225-234`
  Método sem tipagem (`Promise<unknown>`), sem `metadata`, e **nunca é chamado** (o hook usa `api.get` direto). Tipar como `Promise<{ data: HeatmapPoint[]; metadata: HeatmapMetadata }>` (criando `HeatmapMetadata` em `types/analytics/geographic.ts`) e usar do hook, OU remover método para reduzir superfície.

- **`HeatmapStats` exibe campos que o backend nunca retorna** — `frontend/src/features/analytics/components/heatmap/HeatmapStats.tsx:64-71`
  Calcula `uniqueVisitors`, `totalActiveDays`, `avgPeakHour`, `weekendPercentage`, `totalTimezones` (só timezone existe no payload), `totalContinents` (existe) acessando propriedades opcionais (`point.unique_visitors`, `point.active_days`, `point.avg_hour`, `point.weekend_percentage`) tipadas em `types/core/api.ts:144-160` mas **não preenchidas pelo `getHeatmapDataOptimized`** (`LinkAnalyticsService.php:149-163` retorna apenas lat, lng, city, country, clicks, iso_code, currency, state_name, continent, timezone, last_click). Resultado: cards sempre exibem `0`, `0:00`, `0.0%` — UI poluída com métricas mortas. Solução: ou enriquecer a query backend (cuidado: cardinalidade explode se agrupar por mais coisas), ou remover esses cards.

- **`HeatmapMap.tsx` (270 linhas) e `HeatmapControls.tsx` (142 linhas) são código morto** — `frontend/src/features/analytics/components/heatmap/`
  Apenas re-exportados em `index.ts:13,15`, nunca importados em nenhum lugar (`grep` confirma). `HeatmapMap` duplica responsabilidade do `RealTimeHeatmapChart`. Remover ambos OU promover `HeatmapMap` a primário e refatorar `RealTimeHeatmapChart` para reutilizá-lo (atual viola `.cursorrules` com 680 linhas).

- **`RealTimeHeatmapChart.tsx` com 680 linhas** — `frontend/src/features/analytics/components/heatmap/RealTimeHeatmapChart.tsx`
  Viola regra `.cursorrules: componente < 200 linhas`. Mistura: load do Leaflet, controles (slider, switches, mapStyle), renderização do mapa, popup com detalhes do ponto, fullscreen. Quebrar em `HeatmapMap` (renderização Leaflet) + `HeatmapMapControls` (sliders/switches já existem em `HeatmapControls.tsx` mortinho) + `HeatmapPointPopup`. Hook `useLeafletLoader` ajudaria.

- **Filtro `min_clicks` aplicado apenas no frontend** — `useHeatmapData.ts:183`
  O backend retorna **todos** os pontos (mesmo com 1 clique). O filtro acontece no client após receber payload completo. Para links com longa cauda (centenas de cidades com 1 clique), payload é grande e desperdiça banda no polling. Considerar query param `?min_clicks=N` no backend para reduzir payload (especialmente útil no realtime).

- **`coveragePercentage = countries.length / 195 * 100` está desalinhado** — `useHeatmapData.ts:117` vs `HeatmapStats.tsx:60`
  Hook usa `/195` (países do mundo). `HeatmapStats` no fallback recalcula como `(data.length / 100) * 100` (sem nenhum sentido — divide número de pontos por 100). Métrica inconsistente. Padronizar em uma única fonte (idealmente backend).

### 🟢 Minor

- **CSS do Leaflet carregado de CDN externa em runtime** — `RealTimeHeatmapChart.tsx:127-133` e `HeatmapMap.tsx:50-56`
  `unpkg.com/leaflet@1.9.4/dist/leaflet.css`. Quebra se a CDN cair, viola CSP estrita, atrasa render. Como `react-leaflet` já é dependência npm, importar CSS via `import 'leaflet/dist/leaflet.css'` no entry do bundle.

- **Naming inconsistente BE/FE para um mesmo conceito** — `AnalyticsController.php:69` vs `useHeatmapData.ts:167`
  Backend tem chave interna `heatmap_data` mas resposta usa `data` (envelope `{ success, data, metadata }`). Frontend desembrulha via `api.get` para `HeatmapPoint[]` direto. Comentário em `useHeatmapData.ts:165-166` ("Client já desembrulha envelope { data } (Onda 0)") esclarece — alinhamento OK em runtime, mas a interface `HeatmapApiResponse` definida em `useHeatmapData.ts:40-49` está **morta** (nunca tipa `response`) e usa nomes (`total_points`, `total_clicks`, `countries`, `cities`) que **divergem** dos reais (`total_clicks`, `unique_countries`, `unique_cities`, `max_clicks`, `total_locations`, `last_updated`, `link_info`). Remover ou alinhar.

- **`group by` com 9 colunas pode duplicar pontos** — `LinkAnalyticsService.php:146`
  Agrupa por `latitude, longitude, city, country, iso_code, currency, state_name, continent, timezone`. Dois cliques na mesma `(lat, lng)` mas com `timezone` levemente diferente (ex.: GeoIP retorna `America/Sao_Paulo` vs `Brazil/East`) viram dois pontos separados no mapa. Idem para `currency` (raríssimo, mas possível). Considerar agrupar só por `(lat, lng)` e pegar `MAX` ou `MODE` dos campos descritivos.

- **`maxClicks` calculado dentro do `.map` em loop quente** — `HeatmapMap.tsx:95,101`
  `Math.max(...filteredData.map(p => p.clicks))` recalculado em `getPointRadius` e `getPointColor` para cada ponto renderizado (O(n²)). Mover para fora dos handlers via `useMemo`.

- **`getEndpoint()` callback sem necessidade** — `useHeatmapData.ts:124-130`
  Para um único endpoint dinâmico apenas em `linkId`, não precisa `useCallback`. Inline `const endpoint = `/api/analytics/link/${linkId}/heatmap`` resolve.

- **`useEffect` ignora warning de `fetchHeatmapData` nas deps** — `useHeatmapData.ts:225,253`
  Dois `useEffect` removem `fetchHeatmapData` das deps com comentário "Removido das dependências". Funciona porque `fetchHeatmapData` é estável o suficiente (depende só de refs internas), mas é frágil. Se algum dia `minClicks` ou `getEndpoint` mudarem entre renders, polling fica desatualizado. Refatorar para usar `useRef(fetchHeatmapData)` e estabilizar via ref.

- **`stats?.maxClicks || 100` no slider** — `RealTimeHeatmapChart.tsx:519`
  Fallback de 100 cria slider com escala estranha quando `stats` é nulo. Usar `stats?.maxClicks ?? 1` (consistente com defaults).

- **`RealTimeHeatmapChart` recebe `stats: any`** — `RealTimeHeatmapChart.tsx:52`
  Tipagem perdida. Tipar com `HeatmapStats` (mover interface do hook para `types/analytics/geographic.ts`).

## Recommendations

1. **[HIGH]** Adicionar endpoint enxuto `getHeatmapDataOnly($linkId)` no service que executa apenas `getHeatmapDataOptimized` e expor via controller — eliminar a chamada em cascata para `getComprehensiveLinkAnalytics` no fluxo de polling. Ganho imediato em CPU/DB sob qualquer link aberto em tempo real.
2. **[HIGH]** Decidir destino de `getHeatmapDataRealtime`: remover o método (preferido) ou registrar rota com auth + rate limit + tokenização. Não deixar código morto que vaza intenção de "endpoint público".
3. **[HIGH]** Corrigir bug visível em `HeatmapMetrics.tsx` (cards de países e cidades mostram totalPoints/totalClicks). Adicionar `uniqueCountries` e `uniqueCities` ao `HeatmapStats` calculado no hook.
4. **[MED]** Tipar `getLinkHeatmap` (criar `HeatmapMetadata` em `types/analytics/geographic.ts`) e usá-lo no hook em vez de `api.get` direto — ou remover o método órfão.
5. **[MED]** Remover `HeatmapMap.tsx` e `HeatmapControls.tsx` (código morto), ou refatorar `RealTimeHeatmapChart` (680 linhas) para reusá-los, ficando abaixo de 200 linhas conforme `.cursorrules`.
6. **[MED]** Remover ou enriquecer cards "avançados" em `HeatmapStats` (`uniqueVisitors`, `avgPeakHour`, `weekendPercentage`, etc.) — backend nunca preenche esses campos no heatmap, então sempre exibem zero/12:00/0%.
7. **[LOW]** Aplicar filtro `min_clicks` server-side via query param `?min_clicks=N` para reduzir payload no polling.
8. **[LOW]** Importar CSS do Leaflet via bundle (`import 'leaflet/dist/leaflet.css'`) em vez de CDN runtime.
9. **[LOW]** Padronizar `coveragePercentage` numa única fonte (atualmente hook usa /195 e fallback do `HeatmapStats` usa /100).
10. **[LOW]** Remover `HeatmapApiResponse` morta em `useHeatmapData.ts:40-49` ou alinhar com a resposta real do backend.

## For the Fix Agent

- **Files**:
  - Backend:
    - `/Users/bruno/Projects/link-charts/backend/app/Services/Analytics/LinkAnalyticsService.php` (extrair método público enxuto, possivelmente cache 30s por linkId)
    - `/Users/bruno/Projects/link-charts/backend/app/Http/Controllers/Analytics/AnalyticsController.php` (refatorar `getHeatmapData`, remover/expor `getHeatmapDataRealtime`)
    - `/Users/bruno/Projects/link-charts/backend/routes/api.php` (rota enxuta opcional)
  - Frontend:
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/hooks/useHeatmapData.ts` (expor `uniqueCountries`/`uniqueCities`, padronizar `coveragePercentage`, limpar interface morta)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/heatmap/HeatmapMetrics.tsx` (corrigir bug de valor errado nos cards)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/heatmap/HeatmapStats.tsx` (remover métricas mortas)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/heatmap/RealTimeHeatmapChart.tsx` (quebrar em sub-componentes < 200 linhas)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/heatmap/HeatmapMap.tsx` (decidir: remover OU promover a primário)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/heatmap/HeatmapControls.tsx` (decidir: remover OU usar)
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/heatmap/index.ts` (atualizar exports)
    - `/Users/bruno/Projects/link-charts/frontend/src/services/analytics.service.ts` (tipar `getLinkHeatmap` ou remover)
    - `/Users/bruno/Projects/link-charts/frontend/src/types/analytics/geographic.ts` (criar `HeatmapMetadata`, `HeatmapStats`)
- **Tests**:
  - Backend: adicionar PHPUnit cobrindo `getHeatmapDataOptimized` (filtro de `null` lat/lng, `country='localhost'`, agrupamento), e cenário sem cliques. Hoje não há teste do método.
  - Frontend: smoke test manual via dashboard de link com cliques reais (sem suite automatizada). Verificar polling não derruba performance, cards exibem valores corretos, fullscreen funciona.
- **Migration**: no
- **Estimated effort**: M (correções pontuais HIGH são pequenas; refatoração de `RealTimeHeatmapChart` e decisão sobre componentes mortos sobem para M)
- **Dependencies**:
  - Decisão de produto sobre `getHeatmapDataRealtime` (manter polling autenticado vs endpoint público com token)
  - Auditoria 02 (Geographic) pode compartilhar a refatoração do helper `getGeographicAnalyticsOptimized`

## Out of Scope

- Auditoria de paleta `chartByType.heatmap` (delegada ao design system).
- Tunning de queries em `clicks` para grandes volumes (índices, partial index sobre lat/lng — endereçado em audit de DB).
- Internacionalização das strings hardcoded em PT-BR ("Cidade Desconhecida", "🏆 Top Países", etc.).
- Validação de fluxo Open Graph (não toca heatmap).
- Migração para NestJS+Prisma (cabe ao tracker de migração; o método `getHeatmapDataOptimized` traduz 1:1 para Prisma `groupBy` + `where`).
