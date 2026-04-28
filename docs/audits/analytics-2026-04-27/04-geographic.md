# Geographic Analytics — Audit

## Scope

Auditoria do módulo **geographic** do Link Charts: `GET /api/analytics/link/{linkId}/geographic`, fluxo `AnalyticsController@getGeographicAnalytics` → `LinkAnalyticsService::getLinkGeographicAnalytics` → `LinkAnalyticsService::getGeographicAnalyticsOptimized`, ingestão geo via `LinkTrackingService::resolveDetailedLocation` (`torann/geoip`), e camada FE em `features/analytics/components/geographic/*` + `useGeographicData`.

Cobertura:
- Backend: `AnalyticsController.php`, `LinkAnalyticsService.php` (métodos `getGeographicAnalyticsOptimized`, `getTopCountriesOptimized`, `getTopStatesOptimized`, `getTopCitiesOptimized`, `getHeatmapDataOptimized`, `getLinkGeographicAnalytics`), `LinkTrackingService.php`, migrations de `clicks`.
- Frontend: 4 componentes do diretório `geographic/`, hook `useGeographicData`, tipos em `types/analytics/geographic.ts` + `types/core/api.ts`, service `analytics.service.ts`.

Fora de escopo: módulo Heatmap dedicado (endpoints `/heatmap`, `/heatmap/realtime`), demais tabs (temporal, audience, insights), página/composição que monta `GeographicAnalysis`.

## Data Flow

### Backend

1. Rota: `routes/api.php:119` — `Route::get('/{linkId}/geographic', 'getGeographicAnalytics')->where('linkId', '[0-9]+')` dentro do grupo `analytics/link`.
2. Controller: `app/Http/Controllers/Analytics/AnalyticsController.php:139-162` — checa ownership (`Link::where('id', …)->where('user_id', auth()->guard('api')->id())`), retorna 404 se não encontrar, chama `LinkAnalyticsService::getLinkGeographicAnalytics`, embrulha em `{ success, data }`. Catch genérico (HTTP 500) expõe `e->getMessage()` no payload.
3. Service entrypoint: `LinkAnalyticsService.php:1022-1036` (`getLinkGeographicAnalytics`) — verifica se há ao menos 1 click; se não, retorna shape vazio **com apenas 3 chaves** (`top_countries`, `top_states`, `top_cities`) — sem `heatmap_data` nem `continents`. Caso contrário delega para `getGeographicAnalyticsOptimized`.
4. Aggregator: `LinkAnalyticsService.php:79-87` (`getGeographicAnalyticsOptimized`) — retorna 4 chaves: `heatmap_data`, `top_countries`, `top_states`, `top_cities`. **Nunca produz `continents`**.
5. Queries (todas via `\DB::table('clicks')` com `selectRaw`):
   - `getHeatmapDataOptimized` (`L124-165`): `selectRaw` com 9 colunas + `COUNT(*)`/`MAX(created_at)`, `whereNotNull(latitude/longitude/country)`, `where country != 'localhost'` e `!= ''`, groupBy de 9 colunas, `orderBy clicks desc`. Mapeia para shape `{lat, lng, city: 'Cidade Desconhecida' fallback, country, clicks, iso_code, currency, state_name, continent, timezone, last_click}`.
   - `getTopCountriesOptimized` (`L174-199`): `selectRaw country, iso_code, currency, COUNT(*) as clicks` + `whereNotNull('country')` + `country != 'localhost'`, groupBy de 3 colunas, `limit(10)`. Não filtra `country = ''`.
   - `getTopStatesOptimized` (`L204-228`): `selectRaw country, state, state_name, COUNT(*) as clicks` + `whereNotNull('state')`, groupBy de 3 colunas, `limit(10)`. **Não filtra `country = 'localhost'` nem ordena por país** — mistura estados de países diferentes mas a chave inclui country.
   - `getTopCitiesOptimized` (`L233-257`): `selectRaw city, state, country, COUNT(*) as clicks` + `whereNotNull('city')`, groupBy de 3 colunas, `limit(10)`. **Não filtra `country = 'localhost'`** — `city` legítimo + `country = 'localhost'` aparece se ip local foi gravado com city.
6. Ingestão: `LinkTrackingService.php:159-210` (`resolveDetailedLocation`) — IPs vazios/`127.0.0.1`/`::1` retornam `defaultData` com `country='localhost'`, `city='localhost'` e demais campos `null`. Lookup via `app('geoip')` (torann/geoip): se `$location->default === true` (IP não resolvido), grava `defaultData`; em exceção (timeout/db corrompido) também grava `defaultData`. Chamado dentro de `ProcessLinkClickJob` em `registrarCliqueFromPayload` (`L46`), antes do `Click::create`.
7. Schema: `database/migrations/2025_04_20_033001_create_clicks_table.php` — `country`, `city` nullable. `2025_08_19_160612_add_detailed_location_fields_to_clicks_table.php` adiciona `iso_code(2)`, `state(10)`, `state_name`, `postal_code(20)`, `latitude(10,7)`, `longitude(11,7)`, `timezone(50)`, `continent(20)`, `currency(3)`. Índices criados: `idx_clicks_location (country,state,city)`, `idx_clicks_iso_code (iso_code)`, `idx_clicks_continent (continent)`. **Não há índice composto com `link_id` em primeiro lugar** — todas as queries do módulo filtram por `link_id` mas só existe o FK index simples em `link_id`.

### Frontend

1. Componente raiz: `features/analytics/components/geographic/GeographicAnalysis.tsx:47-121` — recebe `linkId` (obrigatório, tipado `string`), monta `useGeographicData`, exibe `TabDescription` + `AnalyticsStateManager`. Em `success`: renderiza `GeographicMetrics`, `GeographicChart`, `GeographicInsights` lado a lado.
2. Hook: `features/analytics/hooks/useGeographicData.ts:39-181`:
   - `endpoint = /api/analytics/link/${linkId}/geographic` (`L90`).
   - Usa `api.get<GeographicData>(endpoint)`. Comentário no L92-93 indica que o client (Onda 0) já desembrulha `{data}`.
   - Filtragem client-side com `minClicks > 1` em `top_countries`, `top_states`, `top_cities`, `heatmap_data` (`L102-110`).
   - Recalcula `stats` localmente via `calculateStats` (`L58-71`): `totalCountries/States/Cities` baseados em `length` dos arrays já truncados a 10 pelo BE → métrica enganosa quando o usuário tem >10 países (ver Findings).
   - `coveragePercentage = countries.length / 195 * 100` cap a 100 — mas como BE retorna `limit(10)`, máximo prático é ~5,1%.
   - Realtime polling `setInterval(fetchGeographicData, refreshInterval)` (default 30s) quando `enableRealtime`.
   - **`useEffect` deps com array vazio** em 3 efeitos (`L133`, `L151`, `L171`) e closure capturando `linkId/minClicks/calculateStats` — comentários explicitam "Removido fetchGeographicData das dependências". Risco de stale closure caso `linkId` mude após o mount.
3. Service alternativo (não usado pelo hook acima): `services/analytics.service.ts:211-220` (`getLinkGeographicData`). Retorna `unknown` com `fallback: null`. **Dead code para esse caminho** — o hook chama o `api` cliente direto, sem passar pelo service.
4. Tipos: `types/analytics/geographic.ts:20-31` define `GeographicData { heatmap_data, top_countries, top_states, top_cities, continents? }`. `continents` é optional, mas BE nunca produz. `types/core/api.ts:24-67` define `CountryData`, `StateData`, `CityData`. Bate com o shape gerado nas queries BE.
5. `GeographicChart.tsx` (327 LoC) — três cards (Países top10, Estados top10, Cidades top20 chip-list). Calcula percentual local (`getPercentage`, L31-33) usando `totalClicks` injetado de fora — mas `GeographicAnalysis.tsx:104` passa `stats?.topCountryClicks` (clicks só do **país top1**), não a soma. Resultado: percentuais errados (sempre relativos ao país top, não ao total). Conversão `iso_code` → bandeira via codepoints.
6. `GeographicInsights.tsx` (428 LoC) — recalcula `totalClicks/uniqueCountries/uniqueCities` a partir de `data` (heatmap), mas o card "Distribuição por Continente" é **hardcoded** (L57-84): listas estáticas como `country === 'United States'`, `'Brazil'`, `['Germany','France','UK','Spain']`. Ignora `continent` que já vem do BE no `heatmap_data`. Falha para qualquer país fora dessas listas → contabilizados em "Outros" inflado. Também usa `country === 'UK'` mas o GeoIP normalmente devolve `'United Kingdom'`.
7. `GeographicMetrics.tsx` (119 LoC) — usa `props as any`, ignora a tipagem `GeographicData`. Aceita variantes `data.geographic.*` ou `data.*` ou `data.overview.*`. `totalGeographicClicks` deriva de `stats?.topCountryClicks` (que só representa cliques do país nº 1) **OR** `reduce` em top_countries — inconsistente.

Path completo:
1. FE chama `GET /api/analytics/link/123/geographic`
2. `AnalyticsController@getGeographicAnalytics` valida ownership
3. `LinkAnalyticsService::getLinkGeographicAnalytics` (early-return se 0 clicks)
4. `getGeographicAnalyticsOptimized` agrega 4 queries SQL agrupadas
5. JSON envelope `{ success, data: {heatmap_data, top_countries, top_states, top_cities} }`
6. `useGeographicData` parseia, filtra por `minClicks`, deriva `stats`
7. `GeographicAnalysis` distribui dados aos 3 sub-componentes

## Findings

### Crítico

1. **`continents` documentado/tipado mas nunca produzido** (BE sempre omite, FE mostra dados fake) — `LinkAnalyticsService::getGeographicAnalyticsOptimized` (`L79-87`) não inclui `continents`; `AnalyticsController@getExecutiveSummary` lê `$analytics['geographic']['continents']` (`AnalyticsController.php:290`) e sempre obtém `[]`; tipo `GeographicData.continents?` em `types/analytics/geographic.ts:30`; e `GeographicInsights.tsx:57-84` exibe gráfico "Distribuição por Continente" totalmente hardcoded com 4 categorias e 11 países. Dado **continent já existe na tabela** (gravado em todo click via GeoIP). Esse é o problema mais sensível de "real data" do módulo.

2. **`getLinkGeographicAnalytics` empty-state inconsistente com sucesso** — `LinkAnalyticsService.php:1027-1033` retorna `{ top_countries:[], top_states:[], top_cities:[] }` (3 chaves) quando 0 clicks, mas no caso success retorna 4 chaves (`heatmap_data` adicional). FE faz `data?.heatmap_data || []` (`GeographicAnalysis.tsx:110`) então não quebra, mas hooks/clientes não-defensivos quebram. Tipo `GeographicData` exige `heatmap_data: HeatmapPoint[]` (não-opcional).

3. **Catch handler vaza mensagens internas** — `AnalyticsController.php:156-161` retorna `'message' => $e->getMessage()` no JSON 500. Útil em dev, mas em produção pode expor stack traces / nomes de tabela / conexão DB. Mesmo padrão em **todos** os métodos do controller (12 ocorrências) — fora de escopo expandir, mas o módulo geographic herda o problema.

4. **Percentual de país no `GeographicChart` calculado contra base errada** — `GeographicAnalysis.tsx:104` passa `totalClicks={stats?.topCountryClicks || 0}` para `GeographicChart`, e `getPercentage` (`GeographicChart.tsx:31-33`) divide cada `country.clicks` por esse valor. `topCountryClicks` é o clicks do **país #1** (`useGeographicData.ts:67`). Resultado: country #1 sempre exibe `100.0%`, demais exibem fração relativa ao primeiro (não ao total). Métrica visível ao usuário, errada.

### Importante

5. **Falta filtro `country != 'localhost'` em `getTopStates` e `getTopCities`** — `LinkAnalyticsService.php:204-228` e `233-257`. Se um click foi gravado com `state` ou `city` não-nulo mas IP local (improvável em produção mas possível em dev/testes), poluí os top10. `getTopCountriesOptimized` e `getHeatmapDataOptimized` filtram corretamente.

6. **Falta índice composto `(link_id, country)` / `(link_id, city)` / `(link_id, state)`** — Todas as 4 queries do módulo filtram por `link_id` e agrupam por uma das 3 dimensões geo, mas Postgres só tem `idx_clicks_location (country, state, city)` (sem `link_id`) e o FK index automático em `link_id`. Para um link com 100k+ clicks, o planner faz seek no FK e depois Sort+Hash Aggregate em todos os clicks do link. Um índice como `idx_clicks_link_country (link_id, country) INCLUDE (iso_code, currency)` (Postgres 11+) cobre o `getTopCountriesOptimized` integralmente. Aplicável também ao temporal/audience — ganho não é exclusivo desse módulo.

7. **Top10 hardcoded sem parâmetro** — `limit(10)` em todas as funções (`L188`, `L217`, `L246`). FE recebe no máximo 10 países, mas `coveragePercentage = (countries.length / 195) * 100` (`useGeographicData.ts:68`) trata `length` como total real. `GeographicMetrics` exibe "Países Alcançados: 10" para qualquer link com ≥10 países distintos. O número correto está em `Click::distinct('country')->count()` mas não é exposto pelo endpoint.

8. **`stats.totalCountries/States/Cities` calculadas client-side a partir do array truncado** — `useGeographicData.ts:64-66`. Mesmo problema do item 7. Se o BE devolver `top_countries.length === 10`, FE acredita que `totalCountries === 10`. Para corrigir corretamente, BE precisa devolver contagens reais (ex.: campo `total_countries`, `total_states`, `total_cities` no payload).

9. **`useEffect` com deps `[]` + closures captura linkId stale** — `useGeographicData.ts:133`, `:151`, `:171`. Se o componente trocar de link sem unmount (ex.: navegação com `<GeographicAnalysis linkId={linkA}/>` → `linkB` no mesmo lugar), o efeito de mount não roda novamente e o realtime continua batendo no `linkA`. Os comentários "Removido X das dependências" mostram que houve regressão consciente (loop infinito provável) sem solução estrutural — provavelmente `useCallback` está sem deps estáveis. Bug de UX real, não só code-smell.

10. **`heatmap_data` carrega groupBy de 9 colunas** — `LinkAnalyticsService.php:146`. Sem filtro de tempo, todas as cidades distintas do histórico do link são retornadas. Para link viral com milhares de cidades, payload pode ficar volumoso. Falta paginação/`limit` ou filtro por janela temporal.

11. **`GeographicInsights` cidades sem fallback de `state` em key** — `GeographicChart.tsx:294` usa `key={`${city.country}-${city.state}-${city.city}`}` mas `state` pode ser `null` (BE não filtra `whereNotNull('state')` em `getTopCities`). Reaviva warnings React e potencial colisão de key com `null-null`.

### Minor

12. **Dead code `getGeographicAnalytics($clicks)`** — `LinkAnalyticsService.php:66-74` referencia `getHeatmapData/getTopCountries/getTopStates/getTopCities` que **não existem mais** na classe (apenas as versões `*Optimized`). Se algum código vier a chamar essa função, fatal error. Também `getTemporalAnalytics($clicks)` em `L262-268` tem o mesmo cheiro.

13. **`GeographicInsights.tsx` com 428 LoC** — viola a regra do `.cursorrules` (componentes < 200 LoC). Mistura cards de stats + 4 charts + lista de mercados + recomendações textuais. Candidato a split (`ContinentBreakdown`, `MarketRecommendations`, `GeographicStatsCards`).

14. **`GeographicChart.tsx` com 327 LoC** — também acima do limite de 200. Repete três blocos quase idênticos (top countries / states / cities). Refatoração para um `<TopGeographicList />` reutilizável reduziria o arquivo a ~120 LoC.

15. **Naming entre BE e FE: bate** — `top_countries`, `top_states`, `top_cities`, `heatmap_data` consistentes em ambos os lados. Itens internos: `country/iso_code/clicks/currency` (BE→FE ok), `country/state/state_name/clicks` (ok), `city/state/country/clicks` (ok). Único atrito: `continents` existe só no FE.

16. **`getLinkGeographicData` em `analytics.service.ts:211-220` é dead code** — hook chama `api.get` diretamente. Manter ambos cria dois caminhos divergentes. Service também tem fallback `null` que difere do hook (que faz throw).

17. **Risco zero de SQL injection nos `selectRaw`** — todos os `selectRaw` usam strings literais sem interpolação. Variáveis (`$linkId: int`) entram via parameter binding em `where('link_id', $linkId)`. Type hint `int` no parâmetro fecha a porta. **Não é vulnerabilidade**, mas a presença de `selectRaw` chamou atenção do reviewer — vale comentário de segurança no código.

18. **Empty state retorna shape inconsistente** — ver crítico #2.

19. **`continent VARCHAR(20)` apertado** — `'South America'` tem 13 chars, `'North America'` tem 13. Limite ok mas sem margem se torann/geoip retornar normalizações estendidas. Não-bloqueante.

20. **`coveragePercentage / 195` é mágica** — `useGeographicData.ts:68` divide por 195. Constante hardcoded. Correto teoricamente (193 estados-membro ONU + 2 observadores), mas merece comentário ou constante nomeada.

## Recommendations

Ordem por impacto:

1. **Adicionar `continents` ao backend** (corrige crítico #1 + remove fake do FE):
   - Em `getGeographicAnalyticsOptimized`, adicionar query `getContinentsOptimized(linkId)` com `selectRaw('continent, COUNT(*) as clicks, COUNT(DISTINCT country) as countries_count')` + `groupBy('continent')` + `whereNotNull('continent')`.
   - Substituir hardcoded em `GeographicInsights.tsx:57-84` por `data.continents` recebido do hook (estender `GeographicData` para tornar `continents` não-opcional ou opt-in via flag).

2. **Corrigir base do `getPercentage`** (crítico #4):
   - Em `GeographicAnalysis.tsx:104`, passar `totalClicks={data?.top_countries?.reduce((s, c) => s + c.clicks, 0) || 0}` (ou expor `total_clicks` no endpoint). Idealmente o BE devolve `total_clicks_geographic` no payload.

3. **Empty state alinhado** (crítico #2):
   - `getLinkGeographicAnalytics` deve retornar shape completo: `{ heatmap_data: [], top_countries: [], top_states: [], top_cities: [], continents: [] }`. Mais ainda, deletar a checagem `hasClicks` redundante (as queries agregadas já retornam `[]` em link sem clicks).

4. **Sanear catch handlers** (crítico #3):
   - Remover `'message' => $e->getMessage()` em produção (envelopar em `if (config('app.debug'))`).
   - Refator transversal — não exclusivo desse módulo.

5. **Adicionar `country != 'localhost'` em `getTopStates`/`getTopCities`** (importante #5).

6. **Migration: índices compostos `(link_id, country)`, `(link_id, state)`, `(link_id, city)`** (importante #6) — uma migration cobre o módulo inteiro.

7. **Expor totais reais no endpoint** (importante #7-8):
   - Adicionar `summary: { total_countries, total_states, total_cities, total_continents, total_clicks_geographic }` no payload, calculados via `distinct().count()` em queries dedicadas (ou subquery única).
   - FE consome `summary.*` em `useGeographicData` em vez de `array.length`.

8. **Refatorar deps dos `useEffect` em `useGeographicData`** (importante #9):
   - Estabilizar `fetchGeographicData` com deps explícitas (`[linkId, minClicks]`) e remover `calculateStats` da dep chain (mover para fora do hook ou usar `useRef`). Reativar deps nos efeitos.

9. **Limit/paginação para `heatmap_data`** (importante #10):
   - Adicionar `->limit(500)` ou parâmetro `?limit=` no controller. Documentar no service.

10. **Remover dead code** (minor #12, #16):
    - Deletar `getGeographicAnalytics($clicks)` e `getTemporalAnalytics($clicks)`.
    - Deletar `getLinkGeographicData` em `analytics.service.ts` se nada o consome (grep confirmou que não).

11. **Split de componentes >200 LoC** (minor #13-14) — `GeographicInsights.tsx` em 3 sub-componentes; `GeographicChart.tsx` em `<TopGeographicList />` reutilizável.

## For the Fix Agent

- **Files**:
  - Backend:
    - `/Users/bruno/Projects/link-charts/backend/app/Services/Analytics/LinkAnalyticsService.php` (adicionar `getContinentsOptimized`, incluí-lo em `getGeographicAnalyticsOptimized`, alinhar empty state em `getLinkGeographicAnalytics`, adicionar filtros `localhost` em `getTopStates/getTopCities`, expor `summary` com totais reais, remover `getGeographicAnalytics($clicks)` legado).
    - `/Users/bruno/Projects/link-charts/backend/app/Http/Controllers/Analytics/AnalyticsController.php` (mascarar `e->getMessage()` em produção).
    - `/Users/bruno/Projects/link-charts/backend/database/migrations/` (nova migration `add_link_scoped_geo_indexes_to_clicks_table.php` com `index(['link_id','country'])`, `index(['link_id','state'])`, `index(['link_id','city'])`, `index(['link_id','continent'])`).
  - Frontend:
    - `/Users/bruno/Projects/link-charts/frontend/src/types/analytics/geographic.ts` (tornar `continents` não-opcional; adicionar tipo `GeographicSummary`).
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/hooks/useGeographicData.ts` (consumir `summary` do BE para `stats`; refatorar deps dos `useEffect`).
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/geographic/GeographicAnalysis.tsx` (passar `totalClicks` correto para `GeographicChart`).
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/geographic/GeographicInsights.tsx` (substituir continent hardcoded por `data.continents`; split em sub-componentes).
    - `/Users/bruno/Projects/link-charts/frontend/src/features/analytics/components/geographic/GeographicChart.tsx` (extrair `TopGeographicList`; corrigir key fallback de `state=null`).
    - `/Users/bruno/Projects/link-charts/frontend/src/services/analytics.service.ts` (remover `getLinkGeographicData` se não usado).
- **Tests**:
  - PHPUnit feature: novo `tests/Feature/Analytics/GeographicAnalyticsTest.php` cobrindo (a) link sem clicks → shape completo vazio; (b) link com 3 clicks em 3 países → top_countries.length===3, summary.total_countries===3, continents agregado correto; (c) clicks com `country='localhost'` filtrados de top_states/top_cities; (d) ownership 404.
  - PHPUnit unit: `tests/Unit/LinkAnalyticsServiceTest.php` cobrindo `getContinentsOptimized` e `getGeographicAnalyticsOptimized` shape.
  - FE não tem suite — validar via `npm run quality` + verificação manual no browser.
- **Migration**: yes (índices `(link_id, country/state/city/continent)`).
- **Estimated effort**: M (1-2 dias)
  - Continents BE + FE: ~3h.
  - Indexes + migration + benchmark: ~1h.
  - Empty state + summary + total real: ~2h.
  - Split de componentes: ~3h.
  - Refator do hook deps: ~1h.
  - Testes: ~3h.
- **Dependencies**:
  - Migration nova é segura (apenas `CREATE INDEX CONCURRENTLY` se aplicada com cuidado em prod). Compatível com Postgres 12+.
  - Nenhuma dependência de pacote nova. `torann/geoip` já fornece `continent`.
  - Alterar shape do payload (`continents`, `summary`) requer alinhamento com FE no mesmo PR; o tipo `GeographicData` já tem `continents?` — mudança é minimamente quebradora.

## Out of Scope

- Endpoints `/api/analytics/link/{id}/heatmap` e `/heatmap/realtime` (módulo separado, mesmo `getHeatmapDataOptimized` mas controller diferente).
- Tabs Temporal, Audience, Insights, Browser, Referer (módulos pares).
- Refator transversal do catch handler do `AnalyticsController` — afeta 12 endpoints, não exclusivo do geographic.
- Substituir `torann/geoip` por solução self-hosted (MaxMind direct, IP2Location) — discussão de fornecedor, não de módulo.
- Cache de queries (`Cache::remember`) — não existe hoje em nenhum método; decisão arquitetural maior.
- Página/composição que monta `GeographicAnalysis` (provavelmente em `pages/links/[id]/analytics`) — fora do diretório auditado.
- Tipos `GeographicStats` duplicados (um em `types/analytics/geographic.ts:52`, outro em `hooks/useGeographicData.ts:10` com shape diferente) — limpeza de tipos é debt geral.
