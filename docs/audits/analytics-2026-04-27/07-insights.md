# Insights Analytics — Audit

> Data: 2026-04-27 · Escopo: módulo **Business Insights** (geographic, audience, temporal, performance, security, retention, session_depth, traffic_source) do Link Charts.

## Scope

Rota exposta:

- `GET /api/analytics/link/{linkId}/insights` → `AnalyticsController@getBusinessInsights` → `LinkAnalyticsService::getComprehensiveLinkAnalytics` → retorna `data['insights']` (apenas o array bruto).

Backend (todos em `LinkAnalyticsService.php`):

- `generateBusinessInsightsOptimized($linkId)` (L456–702) — gera 8 tipos de insight num único método de ~250 linhas.
- `getReturnVisitorRate($linkId)` (L707–756).
- `getSessionDepthAnalysis($linkId)` (L761–833).
- `getTrafficSourceAnalysis($linkId)` (L838–970).
- `getLinkInsightsAnalytics($linkId)` (L1058–1102) — método público que retornaria `insights + summary + analytics_data`. **Não está plugado em nenhuma rota**.
- `calculateRealResponseTime` (L1201), `calculateRealSuccessRate` (L1226), `calculatePerformanceScore` (L1256), `calculateUptimePercentage` (L1288) — usados no dashboard, **não no módulo de insights** (apesar de o pedido referenciá-los).

Frontend:

- `useInsightsData.ts` — consome `/insights`, normaliza array vs objeto.
- `InsightsAnalysis.tsx` (244 LOC) — render principal.
- `BusinessInsights.tsx` (319 LOC) — lista de cards.
- `RetentionAnalysisChart.tsx` (442 LOC).
- `SessionDepthChart.tsx` (569 LOC).
- `TrafficSourceChart.tsx` (686 LOC).
- Tipos: `src/types/analytics/insights.ts` + tipos locais duplicados em `useInsightsData.ts`.

## Data Flow

### Backend

1. `AnalyticsController@getBusinessInsights($linkId)` (L167–190):
   - Resolve `Link` por user (ok, autorização correta).
   - Chama `analyticsService->getComprehensiveLinkAnalytics($linkId)`.
   - Retorna **somente** `$analytics['insights']` (array de 0 a 8 itens).
   - Resposta: `{ success, data: BusinessInsight[] }`.

2. `getComprehensiveLinkAnalytics` (L16–40) compõe `overview`, `geographic`, `temporal`, `audience`, `insights`. O controller `getBusinessInsights` descarta tudo menos `insights`. O método público `getLinkInsightsAnalytics` (L1058) já estrutura `insights + summary + analytics_data { retention, session_depth, traffic_sources }` mas **não há controller/route que o invoque** (`grep -n "getLinkInsightsAnalytics" routes/ app/Http/Controllers/` retorna zero).

3. `generateBusinessInsightsOptimized` é um pipeline imperativo monolítico:
   - Faz `Click::where('link_id', $linkId)->count()` para total.
   - Em sequência, dispara queries independentes: top country, top device, peak hour, suspicious IPs, growth rate (7d vs 14d), `getReturnVisitorRate`, `getSessionDepthAnalysis`, `getTrafficSourceAnalysis`.
   - Cada bloco anexa um item ao array `$insights` com formato fixo (`type, title, description, priority, actionable, confidence, impact_score, recommendation` e às vezes `data_points`).

### Frontend

1. `useInsightsData({ linkId })` chama `GET /api/analytics/link/${linkId}/insights`.
2. `ApiClient` desembrulha `{ data }` automaticamente, então `response` aqui é o **array** retornado pelo backend.
3. O hook detecta `Array.isArray(response)`:
   - Sintetiza `InsightsData` com `summary`, `categories: {}` (vazio!) e `generated_at: new Date().toISOString()`.
   - **`analytics_data` nunca é setado**.
4. `InsightsAnalysis` faz `data?.analytics_data ? <RetentionAnalysisChart .../> ...` — esses três charts pesados (1.5k LOC combinadas) **nunca renderizam** com a rota atual.
5. `BusinessInsights` consome só `type/title/description/priority` (descarta `actionable`, `recommendation`, `impact_score`, `confidence`, `data_points`).

## Findings

### Crítico

1. **Endpoint `/insights` retorna apenas o array de insights, não a estrutura `InsightsData`.**
   - Controller (L182): `'data' => $analytics['insights'] ?? []`.
   - Existe método `getLinkInsightsAnalytics` (L1058) que já retorna `insights + summary + analytics_data { retention, session_depth, traffic_sources }`, mas **nenhuma rota o usa**.
   - Consequência: `RetentionAnalysisChart`, `SessionDepthChart`, `TrafficSourceChart` (1.7k LOC + UI elaborada) **estão mortos no frontend**. O usuário nunca vê esses três módulos, embora os componentes existam e o backend tenha as queries prontas.
   - O hook tenta compensar criando `InsightsData` sintético com `categories: {}` e sem `analytics_data` — mascara o problema, não corrige.

2. **`getReturnVisitorRate` baseia retenção em cliques, não em visitantes.**
   - L709: `$totalClicks = Click::where('link_id', $linkId)->count()` — usa contagem de cliques como denominador para "total_visitors".
   - L724: `Click::where('is_return_visitor', true)->count()` — cada clique de um visitante recorrente é contado, não o visitante.
   - Resultado: se um único IP clica 50 vezes em 1h, todos os 50 cliques (exceto o primeiro do IP) podem ter `is_return_visitor=true`, inflando a "taxa de retenção". A unidade certa seria `COUNT(DISTINCT ip)` no numerador e denominador (ou `session_clicks`).
   - O nome do campo `total_visitors` no payload é enganoso — é total de cliques.
   - `benchmark_comparison` usa thresholds **hardcoded** (40/25/15) sem base citada; descrita no insight como "benchmark da indústria" sem fonte. O frontend ainda pinta `industryBenchmark = 20` em `RetentionAnalysisChart.tsx:161` — outro número arbitrário, divergente dos thresholds do backend.

3. **`getTrafficSourceAnalysis` faz dupla categorização contraditória.**
   - O `LinkTrackingService::categorizeClickSource` (L349–376) **já** categoriza referer em 5 valores (`direct`, `social`, `search`, `email`, `referral`, `unknown`) e grava em `clicks.click_source`.
   - O `getTrafficSourceAnalysis` (L866–873) define um `$channelMapping` que tenta re-categorizar por substring — procura "facebook", "twitter", "google" etc. **dentro** dos valores `click_source`, que nunca contêm essas strings (já são as categorias).
   - Consequência: a categorização do insights service **só pode acertar em `direct`, `email` (porque `'email' in 'email'`), `search` (porque `'search' in 'search'`) e `social` (porque `'social' in 'social'`)** — ou seja, faz match acidental por colisão de palavra, não por análise. Para `referral` ou `unknown` cai em `'other'` apesar de `referral` estar na lista (o keyword `referral` está em `referral` channel, então funciona, mas `unknown` vira `other` silenciosamente).
   - Mais grave: nenhuma fonte real (Facebook, Google, etc.) chega aqui — o tracking já apagou essa informação. O método pretende uma granularidade que o schema não suporta.

4. **`response_time` no analytics dos insights é medido errado.**
   - `LinkTrackingService::collectPerformanceData` (L378–396) calcula `response_time` como `(microtime(true) - $startTime) * 1000` no **job de tracking assíncrono** (`ProcessLinkClickJob`). `$startTime` é capturado quando o job inicia, não quando o redirect HTTP foi servido.
   - Isso significa que `clicks.response_time` mede **duração da execução do job de enriquecimento** (geo + UA + behavior + etc.), não a latência real do `/r/{slug}` (que é o que o usuário percebe).
   - Esse valor enviesado é então agregado em `getSessionDepthAnalysis` (L768), `getTrafficSourceAnalysis` (L845), e exibido em `SessionDepthChart`/`TrafficSourceChart` rotulado como "tempo de resposta" — métrica enganosa.

5. **`generateBusinessInsightsOptimized` numera blocos errado e duplica `type='engagement'`.**
   - Comentários numeram 1..7 e depois "ETAPA 3" continua com 6, 7, 8 — confuso (`// 6. Insight de retenção` em L625 vinha depois de "// 7. Insight de engajamento" em L592).
   - Há **dois insights `type='engagement'`**: o de growth rate (L607) e o de session depth (L656). O frontend usa `type` para filtrar/agrupar e categorize divider — esses dois caem juntos com títulos diferentes. Um deveria ser `type='growth'` (já está no enum do TS).

6. **`avg_session_depth` em `getTrafficSourceAnalysis` agrega errado.**
   - L919: `array_sum(array_column($channel['sources'], 'avg_session_depth')) / count($channel['sources'])` — média **sem peso**.
   - Se o canal `social` tem fonte A com 1.000 cliques e session_depth=1.5, e fonte B com 1 clique e session_depth=10, o método retorna `(1.5 + 10) / 2 = 5.75` — distorcido pela cauda. Correto seria média ponderada por `clicks` ou `unique_visitors`.

7. **Categorias retornadas pelo backend não batem com a UI.**
   - Backend gera `type`s: `geographic, audience, temporal, performance, engagement, security, retention, traffic_source`.
   - Frontend `BusinessInsights.tsx:79–88` mapeia ícones para `geographic, audience, temporal, performance, business, schedule` — **sem `engagement, security, retention, traffic_source, growth, optimization`**. Esses caem em fallback `<Info />` (genérico).
   - Frontend `BusinessInsights.tsx:120–131` ordena por categoria com mapeamento que inclui `business` (não existe no backend) e omite `retention, traffic_source` (existem). Resulta em ordem inconsistente.
   - Tipo TS `InsightType` (`types/analytics/insights.ts:26–35`) lista `geographic, temporal, audience, performance, conversion, engagement, optimization, security, growth` — **omite `retention` e `traffic_source`** (que o backend gera) e inclui `conversion`, `optimization`, `growth` (que o backend nunca emite).
   - Hook duplica `BusinessInsight`/`InsightsData` em `useInsightsData.ts:10` com lista de `type`s diferente da do `types/analytics/insights.ts`. Duas fontes de verdade no frontend, ambas divergentes do backend.

### Importante

8. **`confidence` é hardcoded por bloco — não é calculada.**
   - Cada insight tem um número fixo: 0.95 para "bom volume", 0.9 para top country, 0.85 para device/diversidade, 0.8 para horário/growth/traffic, 0.7 para suspicious IPs.
   - Não há fórmula que derive confidence de tamanho de amostra, variância, idade dos dados, etc.
   - O frontend exibe "Confiança Média" como métrica destacada (`InsightsAnalysis.tsx:148`) — passa percepção de modelo estatístico, mas é literal/cosmético.
   - Mesma lógica para `impact_score` (1–9 hardcoded).

9. **`getReturnVisitorRate` é chamado três vezes por requisição em `getLinkInsightsAnalytics`.**
   - L1073, L1082→L626 (dentro de `generateBusinessInsightsOptimized`), L1086. Mesmo cenário para `getSessionDepthAnalysis` e `getTrafficSourceAnalysis` (cada um executa o pipeline de queries 3 vezes).
   - No fluxo atual `/insights` chama só uma rota das três (porque retorna apenas array). Mas se o método `getLinkInsightsAnalytics` for plugado (correção do achado #1) sem refator, são **9 queries duplicadas** por hit.

10. **`generateBusinessInsightsOptimized` é monolítico (~250 LOC, 8 generators inline).**
    - Cada bloco tem mesma estrutura: query agregada + threshold + push em `$insights`. Caso clássico para Strategy/Pipeline.
    - Adicionar um novo tipo de insight exige editar o mesmo método (open/closed violado). Não há registro/extensão.
    - Testabilidade ruim: para testar "geographic insight" precisa popular Click + executar a função inteira e filtrar por `type`.

11. **`getSessionDepthAnalysis` agrupa por `session_clicks` mas conta `users` como linhas, não como pessoas únicas.**
    - L766: `COUNT(*) as users` — cada **linha** de clique vira "user", então uma sessão de 5 clicks aparece como `session_clicks=5, users=5` (sendo 1 visitante real).
    - L789: `$totalUsers = $sessionData->sum('users')` é portanto a soma de cliques agrupada por valor de `session_clicks`, não a quantidade de sessões.
    - `power_users_percentage = (powerUsersCount / totalUsers) * 100` é razão de cliques de power users sobre total de cliques **agrupado**, não % de visitantes que são power users.
    - Métrica `total_sessions` é mal nomeada — é total de cliques. `engagement_score = avgSessionDepth * 20` (capped em 100) é fórmula arbitrária.

12. **Insight de "growth rate" pode dividir por zero / dar Infinity.**
    - L602: `if ($oldClicks > 0) { $growthRate = (($recentClicks - $oldClicks) / $oldClicks) * 100; }` — proteção contra divisão por zero **existe**, mas se não houver dados antigos (`$oldClicks = 0`), **nenhum insight de engagement é emitido**. Para um link novo com tráfego forte, isso significa zero feedback.
    - Quando `$recentClicks > 0 && $oldClicks > 0` mas pequenos (ex.: 10 vs 2), `growthRate=400%` — passa pelo threshold `> 50`, vira "high priority", "Crescimento Acelerado". Volume baixo + variação grande gera ruído.

13. **Tipos duplicados no frontend (3 fontes de `BusinessInsight`).**
    - `src/types/analytics/insights.ts:9` (canônico).
    - `src/features/analytics/hooks/useInsightsData.ts:10` (duplicado, lista `type` diferente).
    - `src/features/analytics/components/insights/BusinessInsights.tsx:19` (subset minimal: só `type, title, description, priority`).
    - Componentes (`RetentionAnalysisChart`, `SessionDepthChart`, `TrafficSourceChart`) definem **suas próprias** interfaces para `RetentionData`, `SessionDepthData`, `TrafficSourceData` em vez de compartilhar com `types/analytics/insights.ts`.

14. **Componentes de insights estouram o limite de 200 LOC do `.cursorrules`.**
    - `RetentionAnalysisChart`: 442 LOC.
    - `SessionDepthChart`: 569 LOC.
    - `TrafficSourceChart`: 686 LOC.
    - `BusinessInsights`: 319 LOC.
    - Não há `useRetentionChartConfig`, `useSessionDepthChartConfig`, `useTrafficSourceChartConfig` para extrair as `apex options`.

15. **Nenhum cache para `/insights`.**
    - Cada hit re-executa ~8 queries agregadas em `clicks` (a maioria sem índice composto adequado para o filtro). O comentário `Optimized` no nome do método é otimista.
    - Existe índice `idx_clicks_behavior_enhanced` (`is_return_visitor, session_clicks`) e `idx_clicks_source_enhanced` (`click_source`) — bom para retention/session/traffic_source. Mas top country/device/peak_hour não têm índice composto com `link_id`.

### Minor

16. **`calculateRealResponseTime`, `calculateRealSuccessRate`, `calculatePerformanceScore`, `calculateUptimePercentage` não pertencem ao módulo de insights.**
    - Apesar de o pedido pedir auditá-los aqui, eles são usados em `getLinkDashboardAnalytics` (L1177–1178), não em `generateBusinessInsightsOptimized`. Vão entrar na auditoria do módulo dashboard/performance, não aqui. Apenas anoto:
      - `calculateRealResponseTime` é **heurística pura sem dados reais** — devolve 120/180/250/320 ms baseado em volume + horários de pico. **Não mede nada do tempo de resposta real**, mesmo havendo `clicks.response_time` no schema.
      - `calculateRealSuccessRate` é heurística: 70% peso "links ativos" + 30% peso "atividade nas últimas 6h". Não usa nenhum sinal de erro real (não há tabela de falhas/4xx/5xx).
      - `calculatePerformanceScore` é função aditiva com pesos arbitrários (30/25/25/20).
      - `calculateUptimePercentage` é "horas com clique nas últimas 24h / 24" misturado com ratio de links ativos. Mede atividade, não disponibilidade.

17. **`type='security'` baseado em "mais de 50 cliques do mesmo IP".**
    - L575: `havingRaw('COUNT(*) > 50')`. Threshold sem janela temporal — um IP corporativo (NAT) com 51 cliques em 6 meses dispara o alerta. Sem `confidence` calibrado.

18. **Insight emoji-friendly mistura UI e domínio.**
    - Strings como `"💡 Insights não disponíveis"`, `"📈 {type}"`, `"🔄 Análise de Retenção"` estão hardcoded em componentes. Localização/i18n futura quebra.

19. **`getBusinessInsights` no controller faz query de Link redundante.**
    - L170 carrega o Link só para verificar ownership; depois `getComprehensiveLinkAnalytics` faz `Link::findOrFail($linkId)` novamente em L18.

20. **`generated_at` no frontend é `new Date().toISOString()` quando vem array.**
    - `useInsightsData.ts:213`. Sempre exibirá "última geração: agora", invalidando o uso para indicar staleness.

## Recommendations

1. **Plugar `getLinkInsightsAnalytics` na rota `/insights`** (controller passa a chamá-lo em vez de descartar campos de `getComprehensiveLinkAnalytics`). Resolve o achado #1 e habilita os três charts mortos. Atualizar o frontend para parar de tratar resposta como array.

2. **Reescrever `getReturnVisitorRate` para contar visitantes únicos**, não cliques. Usar `COUNT(DISTINCT ip)` ou idealmente uma definição de visitante baseada em fingerprint/cookie session.

3. **Decidir o que é `click_source`.** Ou:
   - (a) Tracking grava o **domain/host** real → insights service categoriza com `$channelMapping`. Schema atual permite (campo `varchar(50)`), só requer mudar `categorizeClickSource` no tracking.
   - (b) Manter categoria pré-definida no tracking → eliminar o re-mapeamento no insights service e só agrupar pelo valor já categorizado.
   Opção (b) é mais barata e suficiente para o produto.

4. **Renomear `clicks.response_time`** para `tracking_job_duration` (ou similar) e parar de exibi-lo como "tempo de resposta" no frontend. Se quiser tempo real do redirect, adicionar coluna nova preenchida pelo `RedirectController` antes do `dispatch`.

5. **Refatorar `generateBusinessInsightsOptimized` para Strategy**:
   ```
   Services/Analytics/Insights/
     InsightGenerator.php (interface)
     GeographicTopMarketGenerator.php
     GeographicReachGenerator.php
     AudienceTopDeviceGenerator.php
     TemporalPeakHourGenerator.php
     PerformanceVolumeGenerator.php
     SecuritySuspiciousIpsGenerator.php
     EngagementGrowthRateGenerator.php
     RetentionGenerator.php
     SessionDepthGenerator.php
     TrafficSourceGenerator.php
   InsightOrchestrator.php  // recebe lista de generators, roda cada um
   ```
   Cada generator retorna `null` ou `Insight DTO`. Facilita teste, ordenação por prioridade e migração 1:1 para NestJS providers (`InsightsModule` com providers injetáveis registrados num `INSIGHT_GENERATORS` token).

6. **Mover `getReturnVisitorRate`, `getSessionDepthAnalysis`, `getTrafficSourceAnalysis` para domain services** dedicados (`RetentionService`, `SessionAnalysisService`, `TrafficSourceService`). Os generators consomem esses services em vez de duplicar queries. Resolve achado #9.

7. **Calcular `confidence` de verdade** ou remover do payload. Sugestão mínima: `confidence = min(1.0, log10(sample_size + 1) / 4)` — escala 0→1 conforme amostra cresce. Diferente de hardcoded por tipo.

8. **Alinhar enum `InsightType` BE↔FE**:
   - Adicionar no TS: `retention`, `traffic_source`.
   - Remover do TS: `conversion`, `optimization`, `business` (ou implementar no backend).
   - Decidir: insight de growth rate é `engagement` ou `growth`? (recomendo `growth`).

9. **Consolidar tipos no frontend**:
   - Único `BusinessInsight` em `types/analytics/insights.ts`.
   - `useInsightsData.ts` importa daí.
   - `BusinessInsights.tsx` não redeclara subset; consome o tipo canônico.
   - `RetentionData`, `SessionDepthData`, `TrafficSourceData` viram tipos exportados de `types/analytics/insights.ts` (`InsightAnalyticsData.retention | session_depth | traffic_source`).

10. **Quebrar componentes >300 LOC** seguindo `.cursorrules`:
    - `RetentionAnalysisChart` → `RetentionMetrics`, `RetentionPie`, `RetentionBenchmark`, `RetentionInsight` + hook `useRetentionChartConfig`.
    - `SessionDepthChart` e `TrafficSourceChart` análogos.

11. **Cache curto (Redis 60s) para `/insights`.** A chave precisa invalidar quando há nova click — o `Link` model já tem cache por slug com observer; pode-se fazer o mesmo aqui ou TTL pequeno.

12. **Testes**:
    - PHPUnit para cada `InsightGenerator` (input: factory de Click, output: insight ou null + thresholds).
    - PHPUnit para `RetentionService` (cuidar dos casos de denominador correto).
    - Feature test para `/api/analytics/link/{id}/insights` cobrindo: link sem cliques, link com 1 clique, link com cliques de >5 países, link com IP suspeito.

## For the Fix Agent

- **Files**:
  - `backend/app/Http/Controllers/Analytics/AnalyticsController.php` (L167–190) — apontar para `getLinkInsightsAnalytics`.
  - `backend/app/Services/Analytics/LinkAnalyticsService.php` (L456–970, 1058–1102) — extrair generators e domain services; corrigir `getReturnVisitorRate` (L707) e `getTrafficSourceAnalysis` (L838).
  - `backend/app/Services/Links/LinkTrackingService.php` (L378–396) — renomear/clarificar `response_time`; possivelmente mudar `categorizeClickSource` para gravar domínio.
  - `frontend/src/features/analytics/hooks/useInsightsData.ts` — remover branch de array, usar tipo canônico, corrigir `generated_at`.
  - `frontend/src/types/analytics/insights.ts` — alinhar `InsightType`, adicionar tipos de retention/session/traffic.
  - `frontend/src/features/analytics/components/insights/{BusinessInsights,InsightsAnalysis}.tsx` — alinhar mapeamentos de ícone/categoria.
  - `frontend/src/features/analytics/components/insights/{RetentionAnalysisChart,SessionDepthChart,TrafficSourceChart}.tsx` — quebrar em subcomponentes.
- **Tests**:
  - Backend: novos testes em `tests/Feature/Analytics/InsightsEndpointTest.php` + unit tests por generator. Rodar `docker-compose exec app ./vendor/bin/phpunit`.
  - Frontend: sem suite — validar com `npm run quality` e visualmente.
- **Migration**: **não** estrutural. Opcional: adicionar coluna `clicks.real_response_time_ms` separando o que é medido (manter compatibilidade). Se renomear `response_time` exige migration. Para caminho mínimo, sem migration.
- **Estimated effort**: **L** (Large). Refator de generators + domain services + correção da rota + alinhamento de tipos + quebra de 4 componentes grandes + testes. Estimativa ~3 dias dev focado, ~5 dias com testes e revisão.
- **Dependencies**:
  - Coordenar com auditoria do módulo de tracking (`LinkTrackingService`) antes de mudar semântica de `click_source` ou `response_time`.
  - O método `getLinkInsightsAnalytics` já existe — não há dependência de código novo para o achado #1 (pode ser hotfix isolado em 1h).
  - Antes de mudar `getReturnVisitorRate`, validar com produto se "return visitor" é definido por IP, fingerprint ou sessão lógica.

## Out of Scope

- Auditoria de `calculateRealResponseTime`, `calculateRealSuccessRate`, `calculatePerformanceScore`, `calculateUptimePercentage` — pertencem ao **dashboard/performance** (são consumidos por `getLinkDashboardAnalytics`, não por `generateBusinessInsightsOptimized`). Anotações principais ficam em #16; a auditoria detalhada vai no documento do módulo de performance.
- Schema de tracking (`clicks` table) e detecção de bot — escopo dos audits de tracking/redirect.
- Mudança da definição de "visitante único" no produto (depende de decisão fora de engenharia).
- Internacionalização das strings de insight (PT-BR hardcoded em service e componentes).
- Decisão de migrar para NestJS — todas as recomendações são compatíveis (Strategy → providers, domain services → providers, DTOs → class-validator).
