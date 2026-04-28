# Audience Analytics — Audit

## Scope

Endpoint `GET /api/analytics/link/{linkId}/audience` e tudo que serve audiência:
device_breakdown, browser_breakdown, os_breakdown, browsers (com versão),
operating_systems (com versão), device_performance, languages.

Inclui:
- `AnalyticsController@getAudienceAnalytics`
- `LinkAnalyticsService::getLinkAudienceAnalytics` → `getAudienceAnalyticsOptimized`
- `LinkAnalyticsService::getDeviceBreakdownOptimized`, `getBrowserBreakdownOptimized`,
  `getOSBreakdownOptimized`, `getBrowserDistribution`, `getOSDistribution`,
  `getDevicePerformance`, `getLanguageDistribution`,
  `extractBrowserFromUserAgent`, `extractOSFromUserAgent`, `extractPrimaryLanguage`
- `UserAgentAnalyticsService` (parsing UA paralelo)
- Frontend: `AudienceAnalysis`, `AudienceChart`, `AudienceInsights`,
  `AudienceMetrics`, hook `useAudienceData`, tipos em `src/types/analytics/audience.ts`

Fora do escopo: referrers, traffic-source channels (cobertos no insights/temporal),
heatmap, conversion.

---

## Data Flow

### Backend

1. **Rota**: `routes/api.php` mapeia `GET /api/analytics/link/{linkId}/audience` →
   `AnalyticsController@getAudienceAnalytics`.
2. **Controller** (`backend/app/Http/Controllers/Analytics/AnalyticsController.php:241-264`):
   - Verifica ownership: `Link::where('id', $linkId)->where('user_id', auth()->guard('api')->id())->first()`.
   - Chama `$this->analyticsService->getLinkAudienceAnalytics($linkId)`.
   - Retorna `{ success: true, data: <payload> }`. (O middleware `NormalizeApiResponse`
     desembrulha para `{ data: ... }`, e o frontend `ApiClient` desembrulha mais um nível.)
3. **Service entry** (`backend/app/Services/Analytics/LinkAnalyticsService.php:1041-1053`):
   `getLinkAudienceAnalytics` faz `Link::findOrFail($linkId)` (segunda query redundante
   após o controller já ter validado), checa `Click::where('link_id', $linkId)->exists()`,
   se não houver cliques retorna `['device_breakdown' => []]` (shape diferente da branch
   "tem cliques" — incompleto: faltam `browser_breakdown`, `os_breakdown`, etc.), caso
   contrário delega para `getAudienceAnalyticsOptimized($linkId)`.
4. **Aggregator** (`LinkAnalyticsService.php:107-119`): `getAudienceAnalyticsOptimized`
   monta o payload final com 7 chaves:
   - `device_breakdown` → `getDeviceBreakdownOptimized` (`:335-355`)
   - `browser_breakdown` → `getBrowserBreakdownOptimized` (`:360-379`)
   - `os_breakdown` → `getOSBreakdownOptimized` (`:384-403`)
   - `browsers` → `getBrowserDistribution` (`:1351-1375`) — com versão e percentual
   - `operating_systems` → `getOSDistribution` (`:1380-1404`) — com versão e percentual
   - `device_performance` → `getDevicePerformance` (`:1409-1435`)
   - `languages` → `getLanguageDistribution` (`:1440-1468`)
5. **Queries**:
   - Todas usam `\DB::table('clicks')` direto e agregação no Postgres
     (`COUNT`, `AVG`, `MIN`, `MAX`, window function para percentual).
   - Browser/OS/device vêm das colunas materializadas em `clicks` populadas pelo
     `LinkTrackingService::parseUserAgent` (`backend/app/Services/Links/LinkTrackingService.php:235-268`)
     que usa `jenssegers/agent` no momento do clique (job assíncrono `ProcessLinkClickJob`).
   - `getDevicePerformance` usa a coluna `clicks.response_time` populada por
     `collectPerformanceData` (`LinkTrackingService.php:378-397`) que é
     `(microtime(true) - $startTime) * 1000` — tempo do **job de tracking**, não do
     redirect HTTP nem do servidor de destino. Ver Findings.
   - `getLanguageDistribution` lê `accept_language` (também coluna materializada),
     extrai o primeiro token via `extractPrimaryLanguage` (`:1473-1501`) e mapeia para
     nomes amigáveis (`pt-BR → "Português (Brasil)"`).

### Frontend

1. **Hook** (`frontend/src/features/analytics/hooks/useAudienceData.ts`):
   - `getEndpoint()` (`:101-108`) monta `/api/analytics/link/${linkId}/audience`.
   - `fetchAudienceData` (`:113-191`) usa `api.get<AudienceData>(endpoint)`. Comentário
     na linha 144 confirma que o `ApiClient` já desembrulha o envelope (Onda 0).
   - Polling de 60s via `setInterval` (`:213-217`); `AbortController` cancela request
     anterior (`:121-127`); cleanup no unmount (`:231-243`).
   - Após receber, chama `calculateStats(audienceData)` e armazena em `stats` (`:163-165`).
2. **calculateStats** (`:61-96`) — gera derivados client-side:
   - `totalClicks` = soma de `device_breakdown.clicks` (correto)
   - `primaryDevice` = device com mais cliques (correto)
   - `primaryBrowser` = `browser_breakdown[0].browser` (assume já ordenado)
   - **`uniqueVisitors = Math.round(totalClicks * 0.7)`** — ESTIMATIVA HARDCODED, não real
   - **`newVisitorRate = Math.round(Math.random() * 30 + 60)`** (60-90%) — FALSO
   - **`bounceRate = Math.round(Math.random() * 20 + 30)`** (30-50%) — FALSO
   - **`avgSessionDuration = Math.round(Math.random() * 120 + 60)`** (1-3 min) — FALSO
3. **Componente raiz** (`AudienceAnalysis.tsx`):
   - Chama `useAudienceData` (`:35-40`).
   - Repassa o objeto `audienceData` para `AudienceChart` e `AudienceInsights`.
   - Renderiza `AudienceMetrics` recebendo `{ audience: audienceData, stats }` (`:73-77`).
4. **AudienceMetrics** (`AudienceMetrics.tsx`): renderiza apenas `deviceTypes`,
   `browserTypes`, `osTypes`, `totalAudienceClicks` baseados no `device_breakdown` /
   `browser_breakdown` / `os_breakdown`. **Não consome** `stats.bounceRate /
   newVisitorRate / avgSessionDuration / uniqueVisitors / primaryDevice / primaryBrowser`.
5. **AudienceChart** (`AudienceChart.tsx`): renderiza tabs com pie/bar charts para
   devices, browsers (`browsers` enhanced), OSs (`operating_systems` enhanced),
   performance (`device_performance`) e idiomas (`languages`).
6. **AudienceInsights** (`AudienceInsights.tsx`): infere mobile vs. desktop por
   `String.includes('mobile|android|iphone' / 'desktop|windows|mac')` no campo
   `device.device` — heurística frágil, ver Findings.

---

## Findings

### Crítico

#### 1. `Math.random()` gera `bounceRate`, `newVisitorRate`, `avgSessionDuration`
Local: `frontend/src/features/analytics/hooks/useAudienceData.ts:82-84`

```ts
const newVisitorRate = Math.round(Math.random() * 30 + 60);     // 60-90%
const bounceRate = Math.round(Math.random() * 20 + 30);         // 30-50%
const avgSessionDuration = Math.round(Math.random() * 120 + 60); // 1-3 minutos
```

Também: `uniqueVisitors = Math.round(totalClicks * 0.7)` (linha 81) — não é random,
mas é uma estimativa fixa de 70%, igualmente falsa do ponto de vista de dado real.

**Impacto atual (UI)**: bom e mau ao mesmo tempo.
- `AudienceMetrics.tsx` **não exibe** nenhum desses três campos. Os cards renderizados
  são `deviceTypes`, `browserTypes`, `osTypes`, `totalAudienceClicks` — todos derivados
  de `device_breakdown / browser_breakdown / os_breakdown`. Logo, **hoje** o usuário não
  vê os números aleatórios na tela de audiência.
- Porém o objeto `stats` é parte do retorno público do hook (`UseAudienceDataReturn`,
  `audience.ts:197-212`); está documentado em `AudienceStats` como
  "Taxa de novos vs. retornantes / Taxa de rejeição / Tempo médio na página"
  (`audience.ts:108-125`). Qualquer dev que ler o tipo vai assumir que são reais.
- `audienceData?.stats` é referenciado também via tipo `AudienceData.stats?` (linha 92)
  — campo opcional sem origem clara (backend não devolve).
- Numa eventual nova métrica ("Taxa de Rejeição" no card), basta plugar `stats.bounceRate`
  para começar a vazar dados sintéticos pra produção.
- Polling de 60s muda os valores random a cada refresh, criando "variação" que
  parece dado real.

**Recomendação**: remover `bounceRate`, `newVisitorRate`, `avgSessionDuration`,
`uniqueVisitors` (estimativa) do `AudienceStats` e do `calculateStats`. Manter apenas
`totalClicks`, `primaryDevice`, `primaryBrowser`, `lastUpdate` — que são verdadeiros.
Se houver requisito de produto para essas métricas, derivá-las no backend a partir das
colunas que já existem em `clicks` (`is_return_visitor`, `session_clicks`, `response_time`)
e expor via endpoint.

#### 2. `device_performance` mede o tempo do **job de tracking**, não a performance percebida
Local backend: `LinkAnalyticsService.php:1409-1435` (consumidor),
`LinkTrackingService.php:378-397` (produtor),
`LinkAnalyticsService.php:1414` (`AVG(response_time)`).

`response_time` = `(microtime(true) - $startTime) * 1000` dentro do
`ProcessLinkClickJob`/`LinkTrackingService::registrarCliqueFromPayload`. Ou seja,
mede quanto tempo o **worker** levou para enriquecer geo/UA/temporal — **não** o
tempo de resposta do redirect HTTP, **não** o tempo de carregamento da URL de destino,
**não** uma métrica de UX do dispositivo do usuário.

Frontend (`AudienceChart.tsx:686-694`) exibe:
> "Média: {avg_response_time}ms | Min: {min_response_time}ms | Max: {max_response_time}ms"

agrupado por `device`, sob o cabeçalho **"Performance por Dispositivo"** com ícone Zap.
Para o usuário final, fica subentendido que mobile/desktop respondem diferente — mas o
valor varia apenas conforme carga do worker, não característica do dispositivo.

**Impacto**: dado tecnicamente correto (é o que está medindo), mas **rotulagem enganosa**.
Mostra ~120-300ms de "performance por dispositivo" que não tem relação com latência
percebida pelo cliente.

**Recomendação**: ou
- (a) renomear para "Tempo de processamento do tracking" e mover para uma seção
  ops/health (não de audiência), **ou**
- (b) instrumentar tempo real (via `Server-Timing` header capturado no frontend, ou
  passando o tempo do redirect HTTP do `RedirectController` para o payload do job),
  **ou**
- (c) remover a aba Performance da UI até ter uma métrica significativa.

### Importante

#### 3. Parsing de User-Agent duplicado em três lugares com convenções diferentes
- `LinkAnalyticsService::extractBrowserFromUserAgent` (`LinkAnalyticsService.php:408-427`)
  → fallback `'Outros'` (PT)
- `LinkAnalyticsService::extractOSFromUserAgent` (`:432-451`)
  → fallback `'Outros'` (PT)
- `UserAgentAnalyticsService::extractBrowser` (`UserAgentAnalyticsService.php:246-264`)
  → fallback `'Unknown'` (EN)
- `UserAgentAnalyticsService::extractOS` (`:266-283`)
  → fallback `'Unknown'` (EN)
- `LinkTrackingService::parseUserAgent` (`LinkTrackingService.php:235-268`)
  → usa `jenssegers/agent`, fallback `'Unknown'`. **Esta é a única que efetivamente
  popula `clicks.browser` / `clicks.os`** — as outras duas estão **mortas/dead code**.

`getBrowserBreakdownOptimized` (`LinkAnalyticsService.php:360-379`) e
`getOSBreakdownOptimized` (`:384-403`) leem `COALESCE(browser, 'Unknown')` direto da
coluna materializada. **Os métodos `extractBrowser/OSFromUserAgent` em
`LinkAnalyticsService` não são chamados em lugar nenhum** (`grep` confirmou). E
`UserAgentAnalyticsService::extractBrowser/OS` é usado pelo método legado
`getBrowserAnalytics` (`:19-55`) que carrega TODOS os clicks em memória e parseia em
PHP — o controller `getBrowserAnalytics` (`AnalyticsController.php:313-336`) inclusive
chama `$this->advancedAnalyticsService` que **não existe injetado** (bug latente —
linha 324 referencia uma propriedade não declarada).

**Inconsistência de fallback** (`Outros` vs `Unknown`) também aparece no payload final
quando há registros antigos com `browser=null` (vira `Unknown` via COALESCE).

**Recomendação**:
- Remover `extractBrowserFromUserAgent`/`extractOSFromUserAgent` de
  `LinkAnalyticsService` (dead code).
- Centralizar a única implementação viva (`LinkTrackingService::parseUserAgent` via
  `jenssegers/agent`) em uma classe `UserAgentParser` injetável que tanto o tracking
  quanto qualquer reprocessamento futuro consuma.
- Padronizar fallback: `Unknown` (já é o que sai do banco). Se for para localizar,
  fazê-lo no frontend por i18n, não na origem.

#### 4. Branch "sem cliques" devolve shape incompleto
`LinkAnalyticsService::getLinkAudienceAnalytics` (`:1041-1053`) retorna apenas
`['device_breakdown' => []]` quando não há cliques, mas o caminho normal devolve 7
chaves. Frontend (`AudienceChart.tsx`) tenta acessar `browsers`, `operating_systems`,
`device_performance`, `languages` — todos serão `undefined` e o componente
condicionalmente esconde via `disabled={!browsers?.length}`. Funciona, mas o tipo
`AudienceData` (`audience.ts:82-103`) sugere que `device_breakdown` é sempre obrigatório
e os outros opcionais — então o shape "sem cliques" tecnicamente está OK, mas inconsistente
com a documentação.

**Recomendação**: retornar todos os campos vazios (`browser_breakdown: []`,
`os_breakdown: []`, etc.) quando não há cliques, para o consumidor não precisar de
guards extras.

#### 5. `AudienceInsights` infere mobile/desktop por `string.includes()` no nome do device
Local: `frontend/src/features/analytics/components/audience/AudienceInsights.tsx:31-46`

```ts
const mobileDevices = deviceBreakdown.filter(d =>
  d.device.toLowerCase().includes('mobile') || ...includes('android') || ...includes('iphone')
);
```

Mas `clicks.device` é populado por `LinkTrackingService::resolveDevice`
(`LinkTrackingService.php:212-233`) que retorna apenas `'mobile' | 'tablet' | 'desktop' |
'bot' | 'unknown'`. Logo:
- `'tablet'` não é contado como mobile **nem** desktop → buracos no cálculo.
- `'unknown'` e `'bot'` idem.
- Os strings `'android'`/`'iphone'`/`'windows'`/`'mac'` no filtro **nunca casam** com a
  taxonomia atual — código defensivo para uma versão antiga da coluna.
- `mobilePercentage + desktopPercentage` pode ser < 100% sem o usuário entender por quê.

**Recomendação**: usar igualdade exata (`device === 'mobile'`) e tratar `tablet`
como categoria própria; mover esse cálculo para o backend (vira mais um campo no
payload de audience) para evitar drift.

#### 6. `getDevicePerformance` ignora `device='unknown'/'bot'`
Está OK que ignore bot (não é UX humana), mas hoje ele só filtra `whereNotNull('device')`
e `whereNotNull('response_time')`. Bots e unknowns entram no cálculo se tiverem
`response_time`. Mistura latência de bot na média.

**Recomendação**: `whereNotIn('device', ['bot', 'unknown'])`.

### Minor

#### 7. `getLinkAudienceAnalytics` faz `Link::findOrFail` redundante
Linha 1043 — o controller já validou ownership e existência. Pode ser removido.

#### 8. Naming inconsistente: `device_breakdown` vs `browsers`
O payload tem três pares "legacy" (`device_breakdown`, `browser_breakdown`,
`os_breakdown`) com agregação simples + três "enhanced" (`browsers`, `operating_systems`,
`device_performance`) mais ricos. Isso obriga o frontend (`AudienceAnalysis.tsx:42-46`)
a fazer fallback em cadeia (`audienceData?.audience?.device_breakdown ||
audienceData?.device_breakdown || []`). Os campos legacy poderiam ser removidos: o
frontend já consome browsers/operating_systems para os charts principais.

#### 9. `getBrowserBreakdownOptimized` agrupa por `browser` sem versão; `getBrowserDistribution`
agrupa por `(browser, version)`. Resultado: o mesmo browser pode aparecer 3x na lista
"enhanced" (Chrome 120, Chrome 121, Chrome 122). Para market share isso é ruído.
Considerar uma query única que retorna versão como objeto aninhado, ou trazer só a
versão dominante.

#### 10. `getLanguageDistribution` faz parsing PHP em loop ao invés de SQL
Linha 1450-1456: carrega todos os `accept_language` em memória e faz `extractPrimaryLanguage`
em PHP, depois `arsort`. Para links com 100k+ cliques é uma carga desnecessária. Dá pra
expressar com `SPLIT_PART(accept_language, ',', 1)` no Postgres + `GROUP BY` e mapear
nomes amigáveis no frontend (ou em uma view).

#### 11. `AudienceData.stats` aparece no tipo mas backend nunca preenche
`audience.ts:92` declara `stats?: AudienceStats` no payload, mas
`getAudienceAnalyticsOptimized` não devolve esse campo. Stats só existe no frontend
via `calculateStats`. Remover do tipo `AudienceData` para não confundir.

#### 12. Insight "Dispositivo Principal" duplicado no backend e no frontend
Backend (`generateBusinessInsightsOptimized`, `:498-510`) já emite o insight
"Dispositivo Principal". Frontend (`AudienceInsights.tsx`) recalcula e renderiza
um insight similar. Decidir uma fonte única de verdade.

---

## Recommendations

Em ordem de prioridade:

1. **Remover métricas falsas do hook** (`useAudienceData.ts:81-84`). Reduzir
   `AudienceStats` para apenas o que é real. Atualizar tipo em
   `frontend/src/types/analytics/audience.ts`.
2. **Renomear ou remover "Performance por Dispositivo"** (decisão de produto). Se
   manter, instrumentar uma métrica real (Server-Timing no redirect HTTP).
3. **Unificar parsing de UA** em `App\Services\Tracking\UserAgentParser` (ou similar);
   remover métodos mortos em `LinkAnalyticsService` e `UserAgentAnalyticsService`.
4. **Fixar shape do "sem cliques"** em `getLinkAudienceAnalytics` retornando todas
   as chaves vazias.
5. **Corrigir `AudienceInsights` mobile/desktop** para usar a taxonomia
   `mobile|tablet|desktop|bot|unknown` que o backend de fato emite. Idealmente mover
   esse split pro backend.
6. **Limpar campos legacy** (`device_breakdown` vs `browsers`) — escolher um único.
7. **Bug paralelo (não-audience mas adjacente)**:
   `AnalyticsController::getBrowserAnalytics`, `getRefererAnalytics`,
   `getEngagementAnalytics`, `getPerformanceByRegion`, `getTrafficQualityReport`
   referenciam `$this->advancedAnalyticsService` (linhas 324, 352, 380, 408, 436)
   que **não está declarado**. Esses endpoints lançam fatal error. Documentar/remover
   das rotas ou injetar `UserAgentAnalyticsService`.

---

## For the Fix Agent

- **Files**:
  - `frontend/src/features/analytics/hooks/useAudienceData.ts` (remover Math.random,
    encolher AudienceStats — alvo principal)
  - `frontend/src/types/analytics/audience.ts` (atualizar `AudienceStats` e
    `AudienceData.stats`)
  - `frontend/src/features/analytics/components/audience/AudienceInsights.tsx`
    (taxonomia de device)
  - `backend/app/Services/Analytics/LinkAnalyticsService.php` (remover
    `extractBrowser/OSFromUserAgent` mortos; corrigir shape sem cliques em
    `getLinkAudienceAnalytics`; opcional: filtrar bot/unknown em
    `getDevicePerformance`)
  - `backend/app/Services/Analytics/UserAgentAnalyticsService.php` (consolidar
    parsing UA em classe dedicada se for fazer fix #3)
  - `backend/app/Http/Controllers/Analytics/AnalyticsController.php` (bug paralelo
    do `$this->advancedAnalyticsService`)
- **Tests**:
  - `backend/tests/Feature/AnalyticsAudienceTest.php` (criar) — cobrir os 7 campos do
    payload, shape do sem-cliques, ownership 404, exclusão de bot/unknown se aplicado.
  - Frontend não tem runner; validar manualmente no dashboard de um link com cliques
    reais que `bounceRate` etc. sumiram do retorno do hook.
- **Migration**: não. Schema atual já tem `is_return_visitor`, `session_clicks`,
  `response_time`, `accept_language`, `browser`, `os` — qualquer melhoria de métrica
  real reaproveita colunas existentes.
- **Estimated effort**: M (essência do fix #1 é S, ~1h; #2/#3 + bug do
  `advancedAnalyticsService` puxam pra M; refactor completo de naming legacy/enhanced
  vai pra L mas não é bloqueante).
- **Dependencies**: nenhuma nova. Continua usando `jenssegers/agent` que já está
  instalado e povoa a coluna materializada.

---

## Out of Scope

- Migração para NestJS + Prisma (decisão arquitetural separada). As recomendações
  aqui se traduzem 1:1 para um service NestJS injetável.
- Endpoints de referrer/engagement/region/traffic-quality (controller tem bug com
  `advancedAnalyticsService` mas não fazem parte do payload de audience).
- Schema de `clicks` (já adequado para tudo que está sendo pedido).
- Testes E2E e cobertura de tracking (responsabilidade de
  `RedirectTest`/`ProcessLinkClickJobTest`).
- Métricas globais (cross-link) — endpoint atual é apenas por link e o service tem
  vários métodos globais já comentados/órfãos que merecem auditoria separada.
