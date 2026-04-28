# Redirect & Tracking Pipeline — Audit

## Scope

- `routes/web.php` — definição da rota `GET /r/{slug}` (única rota web do app, fora do prefixo `api/`)
- `app/Http/Controllers/Links/RedirectController.php` — entrypoint HTTP, bot detection, OG fetch, dispatch do tracking
- `app/Jobs/ProcessLinkClickJob.php` — job assíncrono que invoca o tracking service
- `app/Services/Links/LinkTrackingService.php` — enriquecimento (geo, device, temporal, behavior, performance) e persistência
- `app/Models/Click.php`, `app/Models/LinkUtm.php`, `app/Models/Link.php`
- `app/Http/Middleware/RedirectMetricsCollector.php`, `app/Http/Middleware/TrustProxies.php`
- `app/Providers/AppServiceProvider.php` — definição do rate-limiter `redirect`
- `bootstrap/app.php` — pipeline de middlewares e alias `metrics.redirect`
- Migrations: `2025_04_20_033001_create_clicks_table.php`, `2025_08_19_160612_add_detailed_location_fields_to_clicks_table.php`, `2025_09_11_130817_add_enhanced_tracking_to_clicks_table.php`, `2025_04_20_033105_create_link_utm_table.php`
- `config/geoip.php` (driver `ip_api`, fallback `default_location`)
- `tests/Feature/RedirectTest.php`, `tests/Feature/ProcessLinkClickJobTest.php`

## Data Flow

### Backend

1. **HTTP entry** — `GET /r/{slug}` em `routes/web.php:68` aplica middlewares `throttle:redirect` (600/min/IP — `AppServiceProvider::boot:44`) e `metrics.redirect` (`RedirectMetricsCollector`). O grupo `web` em `bootstrap/app.php:29` injeta `TrustProxies` antes.
2. **Lookup do link cacheado** — `RedirectController::redirect:65` chama `Link::findActiveBySlugCached($slug)` (cache `link:slug:{slug}`, TTL 600s, invalidado em `saved`/`deleted` — `Link.php:103-121`).
3. **Validações** — só checa `expires_at` (linha 71) e `starts_in` (linha 75). **Não checa `click_limit`** (existe `Link::hasReachedClickLimit()` mas só `LinkService::resolveOriginalUrl` o usa).
4. **Bot detection** — `RedirectController::isBotUserAgent:136` faz `stripos` numa whitelist de 20 padrões e cai num fallback para `Jenssegers\Agent::isRobot()`.
5. **Branching**
   - **Humano + não-preview**: `dispatchTracking` (linha 112) monta payload `{ip, user_agent, referer, accept_language, query_params, start_time}` (apenas chaves UTM via `$request->only(LinkTrackingService::UTM_KEYS)`), dispatcha `ProcessLinkClickJob` na fila padrão e faz `DB::table('links')->where('id',$link->id)->increment('clicks')` (query direta, sem disparar observers). Resposta: `redirect()->away($link->original_url, 302)` com headers `no-store/no-cache`.
   - **Bot ou `?preview=1`**: `fetchOriginalMetadata` (linha 154) com proteção SSRF (`isSafeFetchUrl:207` rejeita schemes não-HTTP, hosts `localhost/.local/.internal`, IPs privados/loopback literais), faz `Http::get` com timeout 5s, retry 2×, `verify=false`, max 5 redirects e cacheia 24h sob `metadata_{md5(url)}`. Renderiza HTML inline com OG/Twitter Cards e `<meta refresh>`.
6. **Resolução de IP real** — `LinkTrackingService::resolveRealUserIP:96` ordena: `?real_ip=` query → `X-Real-IP` → primeiro IP de `X-Forwarded-For` → `CF-Connecting-IP` → `request->ip()`. Em produção, `isValidIP` rejeita ranges privados/reservados (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`).
7. **Job** — `ProcessLinkClickJob` (`tries=3`, `backoff=10s`, `timeout=30s`) chama `LinkTrackingService::registrarCliqueFromPayload($linkId, $payload)`.
8. **Enriquecimento**, em `LinkTrackingService::registrarCliqueFromPayload:29`:
   - `resolveDetailedLocation($ip)` — chama `app('geoip')->getLocation($ip)`. Retorna `default` (com flag) para localhost ou em falha → tracking grava `country='localhost'`, `city='localhost'` e demais campos `null`.
   - `parseUserAgent($userAgent)` — `Jenssegers\Agent` retorna browser/version/os/version/flags (is_mobile/tablet/desktop/bot).
   - `enrichTemporalData(now(), $timezone)` — converte para timezone local do visitante (se GeoIP retornou); calcula `hour_of_day`, `day_of_week` (1-7), `is_weekend`, `is_business_hours`, `local_time`.
   - `analyzeVisitorBehavior($ip,$linkId,$referer)` — duas queries em `clicks` (24h e 1h por IP) para `is_return_visitor` e `session_clicks`; categoriza `click_source` em direct/social/search/email/referral/unknown.
   - `collectPerformanceData($acceptLanguage,$startTime)` — `response_time = (microtime(true) - start_time) * 1000` em ms (medido **a partir do dispatch no controller**, atravessa enfileiramento).
9. **Persistência** — `Click::create(array_merge(...))` insere todos os 32 campos; em seguida `LinkUtm::create` se `extractUtm` (query → fallback referer query string) trouxe algo.
10. **Métricas paralelas** — `RedirectMetricsCollector` (middleware) duplica geoip/device/parsing e grava agregados em `Cache::put` chaves `redirect_metrics:hour:*` / `redirect_metrics:day:*` (1h / 24h TTL). **Esses dados não são consumidos por nenhum endpoint hoje** (busca por `redirect_metrics:` em `app/` retorna apenas o produtor).

### Frontend

- O tracking de clique é **100% backend**. Não há beacon/`navigator.sendBeacon` ou pixel client-side no fluxo `/r/{slug}` ativo.
- Existe um endpoint AJAX legado em `frontend/src/pages/public/RedirectPage.tsx:237` que ainda monta `${backendUrl}/api/r/${slug}` — essa rota foi desativada em 04/11/2025 (`routes/api.php:18-32`) e o componente está morto. **Out of scope deste audit** (frontend redirect feature).
- Há util `getUserRealIP()` consumido por essa página legacy que tentava enviar `?real_ip=` para evitar preflight CORS — também sem efeito hoje, já que o caminho ativo é navegação direta para `GET /r/{slug}`.

## Findings

### 🔴 Crítico

- **`click_limit` não é aplicado em `/r/{slug}`** — `app/Http/Controllers/Links/RedirectController.php:71-77`
  O controller só valida `expires_at` e `starts_in`. `Link::hasReachedClickLimit()` existe e está conectado em `LinkService::resolveOriginalUrl` (linha 116), mas esse service só era usado pelo endpoint AJAX legado `/api/r/{slug}` (desativado). Resultado: a feature de limite de cliques exposta no `LinkForm` do frontend é **silenciosamente ignorada**. Impacto: links com limite continuam redirecionando, contador `links.clicks` é incrementado para sempre e `Click` rows continuam sendo gerados.

- **Contador `links.clicks` incrementa antes do tracking — sem rollback em falha** — `app/Http/Controllers/Links/RedirectController.php:124-126`
  `ProcessLinkClickJob::dispatch` é chamado e logo em seguida `DB::table('links')->increment('clicks')`. Se o dispatch falhar (Redis offline, queue table indisponível) o `try/catch` engole o erro e ainda assim o increment já rodou; pior, se o dispatch suceder mas o job falhar definitivamente após 3 retries, `links.clicks` fica desalinhado de `count(clicks)`. Não existe reconciliação. Impacto: divergência entre o KPI denormalizado (cards no dashboard usam `links.clicks`) e os analytics derivados de `clicks` (charts).

- **`response_time` registrado mede latência da fila, não do redirect** — `app/Services/Links/LinkTrackingService.php:50,381`
  `start_time` é capturado no controller (`microtime(true)`) e o cálculo só ocorre quando o job roda — pode ser segundos depois. O campo é vendido pelas analytics como métrica de performance (`UserAgentAnalyticsService` e `LinkAnalyticsService::1414+` lêem `response_time`), mas na prática mede *delay de processamento da queue*, não tempo de resposta do redirect. Os dashboards exibem isso como "performance do link". Impacto: métrica enganosa em qualquer sweep do dashboard de performance.

- **GeoIP `default_location` pode mascarar dados como "United States/New Haven"** — `config/geoip.php:155-169` + `app/Services/Links/LinkTrackingService.php:183`
  O code path está correto (`if (! $location->default)` só usa quando flag é falsa), mas a configuração ainda fornece um `default_location` realista (US/CT). Qualquer regressão futura que ler `$location` sem checar `->default` vai gravar New Haven em todos os clicks que falharem GeoIP. Adicionalmente, o driver default é `ip_api` (rate-limited free tier 45 req/min sem chave) — em pico o serviço retorna 429 e cai no `default_location` sem feedback visível além de um `Log::warning`. Impacto: qualidade de geo silenciosamente degrada em horário de pico.

### 🟡 Importante

- **`RedirectController` faz responsabilidades demais** — `app/Http/Controllers/Links/RedirectController.php` (610 linhas)
  Detecta bot, busca metadata, parseia HTML (regex), monta SSRF guard, renderiza dois templates HTML inline, dispatcha tracking. O HTML deveria estar em Blade view; o parser de meta-tags e o SSRF guard pertencem ao `LinkPreviewService` (já existente) ou a um novo `OpenGraphFetcher`. Para o NestJS migration vira diretamente um Controller obeso de `> 600 LOC`. Impacto: testabilidade ruim, retrabalho na migração.

- **Naming desalinhado: analytics ignoram `hour_of_day`/`day_of_week` enriquecidos** — `app/Services/Analytics/LinkAnalyticsService.php:276,301,514,1205`
  O tracking calcula `hour_of_day` e `day_of_week` em **timezone local do visitante** (com base no `timezone` retornado pelo GeoIP) e grava nas colunas indexadas (`idx_clicks_temporal_enhanced`). Mas as queries de "clicks por hora/dia da semana" usam `EXTRACT(HOUR FROM created_at)` e `EXTRACT(DOW FROM created_at)` em **UTC** — ignorando completamente o esforço de enriquecimento e os índices. `MetricsService.php:455,474` faz o mesmo. Apenas `UserAgentAnalyticsService` (linhas 1534-1546) usa `hour_of_day` corretamente. Impacto: heatmap temporal é mostrado em UTC; o índice composto criado para isso fica órfão; `is_weekend`/`is_business_hours` populados nunca são lidos.

- **Bot detection incoerente entre middleware e controller** — `app/Http/Middleware/RedirectMetricsCollector.php:291-312` vs `app/Http/Controllers/Links/RedirectController.php:136-152`
  O middleware classifica como `bot` qualquer UA que case `(bot|crawler|spider|scraper)`, mas o controller só dispatcha tracking para humanos detectados pelo seu próprio (whitelist ∪ Jenssegers). Resultado: um UA tipo `MyCrawler/1.0` é considerado bot pelo middleware (não vai pra tracking) **e** humano pelo controller (vai pra tracking + 302). Os dois usam regras diferentes. Pior: o `is_bot` do `Click` enriquecido vem de `Jenssegers::isRobot()` no `LinkTrackingService`, criando uma terceira fonte de verdade.

- **`LinkUtm` é criado fora de transação com `Click`** — `app/Services/Links/LinkTrackingService.php:52-74`
  Se `LinkUtm::create` falhar, o `Click` órfão fica sem UTM. Não há `DB::transaction`. Impacto: campanhas perdem atribuição; consumidores que fazem `JOIN link_utms` enxergam discrepâncias. Pequeno volume hoje, mas amplifica com retry parcial.

- **`session_clicks` calculado com query síncrona dentro do job** — `app/Services/Links/LinkTrackingService.php:321-326`
  Duas queries em `clicks` por IP (24h e 1h) por **cada** clique processado. Sem índice em `(ip, created_at)`. Em pico de tráfego (600 cliques/min/IP permitidos) cada job dispara dois full scans parciais. Impacto: latência da fila cresce com o volume; ainda assim a métrica é incrementada com `+1` em memória que não reflete cliques concorrentes na mesma janela.

- **`RedirectMetricsCollector` é dead code útil** — `app/Http/Middleware/RedirectMetricsCollector.php`
  459 linhas de coleta sofisticada que grava agregados em cache mas **nada lê** essas chaves (`redirect_metrics:*`). Faz GeoIP lookup duplicado (já feito no job) que custa rate-limit do `ip_api`. Os logs `Log::info('RedirectMetricsCollector: ...')` poluem o canal default em produção (visto em cada hit `/r/`). Impacto: ruído nos logs, custo dobrado de geoip, manutenção de código sem consumidor.

- **`extractUtmFromReferer` não bate com `extractUtm` no controller** — `app/Services/Links/LinkTrackingService.php:131-157` + `app/Http/Controllers/Links/RedirectController.php:120`
  No controller, `query_params` já é filtrado por `$request->only(UTM_KEYS)` — chega ao job apenas com chaves UTM. O `extractUtm` no service refiltra (no-op) e, se vazio, tenta extrair do `referer`. Mas o `extractUtmFromReferer` retorna `array_intersect_key(...)` sem `array_filter` — se o referer tem `utm_source=` (vazio), ele vira `['utm_source' => '']` e cria um `LinkUtm` com strings vazias. O caminho de query usa `array_filter`, o de referer não.

### 🟢 Minor

- **HTML inline com 200+ linhas em PHP** — `app/Http/Controllers/Links/RedirectController.php:326-497, 511-585`
  Heredoc com CSS embedded. Baixa testabilidade do markup (não dá para rodar snapshot test isolado), e duplica estilos entre `renderRedirectPage` e `renderErrorPage`. Migrar para Blade view + asset CSS resolve.

- **`user_agent` truncado a 1024 chars no schema, mas não no app** — `database/migrations/2025_04_20_033001_create_clicks_table.php:19`
  `string('user_agent', 1024)` sem truncamento no service. UAs maiores (>1024) explodem em `SQLSTATE[22001]` e perdem o `Click`. Não vi observação de erro real, mas é uma brecha.

- **Logs verbosos no `RedirectMetricsCollector`** — `app/Http/Middleware/RedirectMetricsCollector.php:25,37,59,...`
  Cada redirect produz ~10 linhas `Log::info` em produção. Custo de I/O e de vasculhar logs é alto. Mover para `Log::debug` (gated por `APP_DEBUG`).

- **Não há deduplicação de cliques** — `app/Services/Links/LinkTrackingService.php:52`
  Recarregar a página, double-click, retry de browser, prefetch de UA — todos geram rows. Não há janela mínima por (`link_id`, `ip`, `user_agent`) para colapsar bursts. Para um encurtador isso é aceitável (cada hit é um clique), mas torna `is_return_visitor` ruidoso.

- **Cache key de OG metadata usa MD5** — `app/Http/Controllers/Links/RedirectController.php:162`
  MD5 é fine para cache key, mas considere `sha1` ou `xxh64` para evitar warnings de scanners de segurança que apontam `md5`. Funcional, só cosmético.

- **Throttle `redirect` por IP literal** — `app/Providers/AppServiceProvider.php:44`
  600 req/min/IP é generoso. Atrás de Cloudflare, todos os usuários compartilham IP do CF apparent — o `request->ip()` aqui já está com `TrustProxies` ativo, então deve estar OK. Confirmar em produção que `X-Forwarded-For` vem com o IP cliente real (a config `proxies = '*'` é permissiva demais — em produção deveria ser a faixa do CF).

- **`Click::ip` é `ipAddress` (cast pgsql `inet`)** — `database/migrations/2025_04_20_033001_create_clicks_table.php:17`
  Quando `resolveDetailedLocation` recebe um IPv6 mal formado vindo de `?real_ip=`, o `Click::create` vai falhar com erro de tipo. `LinkTrackingService::resolveRealUserIP` valida com `FILTER_VALIDATE_IP`, então o caminho normal está coberto, mas o fallback `'0.0.0.0'` em `registrarCliqueFromPayload:40` nunca dispara hoje (controller sempre passa um IP).

- **Migration de `link_utms` cria tabela `link_utms` mas o `down()` derruba `link_utm`** — `database/migrations/2025_04_20_033105_create_link_utm_table.php:31`
  Typo: `Schema::dropIfExists('link_utm')` — rollback silenciosamente não derruba a tabela criada. Bug latente em migrate:rollback.

## Recommendations (priorizadas)

1. **[HIGH]** Aplicar `click_limit` no `RedirectController::redirect` — adicionar `if ($link->hasReachedClickLimit()) return $this->renderErrorPage(...)` antes do dispatch. Cobrir com feature test.
2. **[HIGH]** Mover `DB::table('links')->increment('clicks')` para dentro do `ProcessLinkClickJob` (ou trocar contador denormalizado por `count(*) from clicks where link_id=?` cacheado) e garantir que só ocorra **após** `Click::create`. Aceitar inconsistência de leitura mais antiga em troca de consistência total quando o ciclo termina.
3. **[HIGH]** Capturar `response_time` real movendo o cálculo para o middleware (que já mede `microtime(true) - $startTime`) e passar via payload. Renomear o campo para `processing_latency_ms` ou separar em dois campos (`http_response_ms` vs `tracking_lag_ms`).
4. **[HIGH]** Decidir um dialeto único para temporal: ou (a) trocar todas as queries `EXTRACT(HOUR FROM created_at)` por `hour_of_day`/`day_of_week` (timezone-aware, indexado), ou (b) remover os campos enriquecidos e a migration. Hoje é o pior dos dois mundos.
5. **[MEDIUM]** Envolver `Click::create` + `LinkUtm::create` em `DB::transaction(...)`. Adicionar índice composto `(ip, created_at)` em `clicks` para baratear `analyzeVisitorBehavior`.
6. **[MEDIUM]** Extrair OG fetch+parse para `OpenGraphFetcherService` (ou expandir `LinkPreviewService`) e mover HTML para Blade views (`resources/views/redirect/page.blade.php`, `error.blade.php`). Reduz `RedirectController` para ~150 linhas.
7. **[MEDIUM]** Decidir o futuro do `RedirectMetricsCollector`: ou expor um endpoint `GET /api/admin/redirect-metrics` que consuma os agregados em cache, ou removê-lo. Hoje é code smell que custa GeoIP duplicado e log noise.
8. **[MEDIUM]** Unificar bot detection: extrair `BotDetector` (regex whitelist + Jenssegers) e usar nos dois pontos (controller + middleware + tracking `is_bot`). Considerar deprecar o `is_bot` no Click se confiarmos na detecção upstream (bots não chegam ao `Click::create` no fluxo atual).
9. **[MEDIUM]** Adicionar `array_filter` em `extractUtmFromReferer` ou refatorar `extractUtm` para sempre filtrar valores vazios no final.
10. **[LOW]** Migrar drive default de GeoIP de `ip_api` (free, rate-limited) para `maxmind_database` (offline, sem rate limit) — já existe a config, falta só baixar `geoip.mmdb` no Docker build. Eliminaria o problema de degradação silenciosa em pico.
11. **[LOW]** Truncar `user_agent` para 1024 chars no `LinkTrackingService` antes do `Click::create` (defensive).
12. **[LOW]** Restringir `TrustProxies::$proxies = '*'` para a faixa real do proxy em produção (ENV `TRUSTED_PROXIES`).
13. **[LOW]** Corrigir typo do `down()` em `2025_04_20_033105_create_link_utm_table.php` (`link_utm` → `link_utms`).
14. **[LOW]** Migrar logs `info` do `RedirectMetricsCollector` para `debug` ou removê-los se o middleware for descontinuado.

## For the Fix Agent

- **Files**:
  - `/Users/bruno/Projects/link-charts/backend/app/Http/Controllers/Links/RedirectController.php`
  - `/Users/bruno/Projects/link-charts/backend/app/Jobs/ProcessLinkClickJob.php`
  - `/Users/bruno/Projects/link-charts/backend/app/Services/Links/LinkTrackingService.php`
  - `/Users/bruno/Projects/link-charts/backend/app/Http/Middleware/RedirectMetricsCollector.php` (decidir destino)
  - `/Users/bruno/Projects/link-charts/backend/app/Services/Analytics/LinkAnalyticsService.php` (alinhar temporal)
  - `/Users/bruno/Projects/link-charts/backend/app/Services/Analytics/MetricsService.php` (alinhar temporal)
  - `/Users/bruno/Projects/link-charts/backend/database/migrations/<new>_add_ip_created_at_index_to_clicks.php` (novo índice)
  - `/Users/bruno/Projects/link-charts/backend/config/geoip.php` (trocar driver default)
  - `/Users/bruno/Projects/link-charts/backend/resources/views/redirect/page.blade.php` (novo)
  - `/Users/bruno/Projects/link-charts/backend/resources/views/redirect/error.blade.php` (novo)
- **Tests**:
  - **feature**: `tests/Feature/RedirectTest.php` — adicionar casos `click_limit_reached_returns_error_page`, `click_increment_only_after_job_success` (com `Queue::failed()`), `bot_detection_consistent_between_middleware_and_controller`.
  - **unit**: `tests/Unit/Services/LinkTrackingServiceTest.php` — testar `extractUtm` com referer com `utm_*=` vazio; testar idempotência de `registrarCliqueFromPayload` em retry.
  - **integration**: `tests/Feature/ProcessLinkClickJobTest.php` (já existe) — adicionar caso `creates_click_and_utm_in_transaction_or_neither`.
- **Migration**: **yes** — (a) novo índice `idx_clicks_ip_created_at` em `(ip, created_at)`; (b) opcional: rename ou drop dos campos `is_business_hours`/`is_weekend` se forem deprecados.
- **Estimated effort**: **M** (4-6h):
  - 1h `click_limit` + reorder de increment/dispatch
  - 1h alinhar temporal queries
  - 1h transaction Click+LinkUtm + índice
  - 1h refatorar extract para Blade views
  - 1h destino de `RedirectMetricsCollector`
  - 0.5h testes
- **Dependencies**: nenhum bloqueio externo. Mas alinhar **antes** com o audit de **analytics services** (TemporalAnalytics/GeographicAnalytics naming): se o consumidor mudar o que espera, o tracking não precisa mexer; senão, mexe junto. Recomendo fixar este audit primeiro porque a inconsistência de naming temporal afeta vários outros relatórios.

## Out of Scope

- **Endpoint legado AJAX `/api/r/{slug}`** (comentado em `routes/api.php:18-32`) e seu consumidor morto em `frontend/src/pages/public/RedirectPage.tsx:237` — pertence ao audit de **frontend redirect feature**.
- **`LinkPreviewService` / `LinkHealthCheckJob`** (`app/Services/Links/LinkPreviewService.php`, `app/Jobs/FetchLinkPreviewJob.php`) — sobrepõem o OG fetch do controller mas pertencem ao audit de **link preview / health**.
- **`UserAgentAnalyticsService` profundidade** — só validei naming dos campos lidos; o serviço inteiro é escopo do audit de **analytics services**.
- **Frontend tracking (analytics dashboards)** — não há tracking client-side; consumo dos dados é escopo do audit de **frontend analytics**.
- **Schema/Prisma mapping** — colunas `decimal(10,7)` para latitude/longitude e `tinyInteger` para `hour_of_day` precisam mapeamento explícito no Prisma (`Float` + check, ou `Int @db.SmallInt`). Pertence ao audit de **migration NestJS**.
- **`hasReachedClickLimit` em `LinkService::resolveOriginalUrl`** — ainda referenciado por código não mais alcançável; cleanup pertence ao audit de **link CRUD/services**.
