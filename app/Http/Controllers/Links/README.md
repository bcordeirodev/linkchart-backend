# Links

Este domínio é composto por quatro sub-domínios com responsabilidades distintas, cada um
em seu próprio controller. Todos compartilham o mesmo diretório `app/Http/Controllers/Links/`.

---

## Sub-domínio: Public Shortener (`PublicLinkController`)

### Propósito

Permite encurtamento anônimo de URLs (sem autenticação) e expõe metadados e analytics
básicos de um link via slug público. É a porta de entrada para usuários do plano gratuito
e para o fluxo "Shortener" sem conta.

### Feature espelhada no frontend

`frontend-next/src/features/shorter/`, `frontend-next/src/features/public-analytics/`

### Endpoints

| Verb | Path | Controller@action | Middleware (route-specific) | Auth |
|---|---|---|---|---|
| POST | /api/public/shorten | `PublicLinkController@store` | `throttle:public-shorten` (10/min por IP) | not required |
| GET | /api/public/link/{slug} | `PublicLinkController@showBySlug` | — | not required |
| GET | /api/public/analytics/{slug} | `PublicLinkController@basicAnalytics` | `throttle:public-analytics` (30/min por IP) | not required |

### Services e Repositories

- `LinkServiceInterface` → `LinkService` — cria o link anônimo via `createPublicLink(CreatePublicLinkDTO)`.
- `LinkSafetyService` — verificação de segurança da URL destino (invocado dentro de `LinkService`).
- `LinkPreviewService` — não é invocado diretamente aqui; preview de links públicos é sob demanda.

### Jobs disparados

Nenhum job é disparado diretamente pelo `PublicLinkController`. O `FetchLinkPreviewJob` é
gerenciado pelo `LinkMetaController` (ver sub-domínio abaixo).

### Cache

- `public_analytics_{linkId}` — TTL 5 min (`Cache::remember`). Armazena o payload completo
  de `basicAnalytics` (cliques totais, top países, dispositivos, distribuição horária,
  browsers, dia da semana). Invalidado por expiração natural; não há invalidação ativa.

### Pontos de atenção

- `showBySlug` não possui rate limit — use com cautela em features que a chamam em loop.
- Os filtros de validade (`is_active`, `expires_at`, `starts_in`) são aplicados na query SQL
  do `basicAnalytics` para evitar vazar a existência de slugs inativos — retorna 404 genérico.

---

## Sub-domínio: Redirect (`RedirectController`) — LOCKED

### Propósito

Coração do sistema. Resolve um slug para a URL original e executa uma das três branches:
redirecionamento humano (302 imediato + tracking assíncrono), renderização de HTML com Open
Graph para bots/scrapers sociais, ou página de erro HTML. Não é uma rota de API.

### Feature espelhada no frontend

`frontend-next/src/features/redirect/` e hits diretos no browser (sem Next.js no caminho).

### Endpoints

| Verb | Path | Controller@action | Middleware (route-specific) | Auth |
|---|---|---|---|---|
| GET | /r/{slug} | `RedirectController@redirect` | `throttle:redirect` (600/min por IP), `metrics.redirect` | not required |
| GET | /{slug} | `RedirectController@redirect` | `throttle:redirect` (600/min por IP), `metrics.redirect` | not required |

Ambas as rotas estão em `routes/web.php` (não em `routes/api.php`). A rota `/{slug}` é o
alias clean-URL usado em produção via `NEXT_PUBLIC_REDIRECT_URL` sem o prefixo `/r/`.
A rota `GET /r/{slug}` tem o nome `public.redirect`; a alias tem o nome `public.redirect.clean`
com constraint `[^/]+`.

> **LOCKED por Hard Rules.** Qualquer mudança neste controller, no job, no model ou nas rotas
> deve manter os testes `RedirectTest` e `ProcessLinkClickJobTest` verdes antes do merge.

### Services e Repositories

- `LinkServiceInterface` → `LinkService` — disponível via injeção, usado para resolução futura.
- `LinkTrackingService` — `resolveRealUserIP()` é chamado no controller para montar o payload
  do job; o registro efetivo do clique ocorre dentro do job, não aqui.

### Jobs disparados

- `ProcessLinkClickJob` (tries=3, backoff=10 s) — dispatchado de forma fire-and-forget em
  `dispatchTracking()` para cada clique humano. Carrega o payload completo: IP resolvido, UA,
  referer, UTM, Sec-Fetch headers, client hints, tempo de resposta HTTP. Dentro do job,
  `LinkTrackingService::registrarCliqueFromPayload` grava em `clicks` e incrementa
  `links.clicks` via query direta.

### Cache

- **`Link::findActiveBySlugCached(string $slug): ?self`** — TTL 10 min, chave `link:slug:{slug}`.
  Invalidado pelo evento `saved` do model quando qualquer dos campos
  `[slug, is_active, expires_at, starts_in, original_url, click_limit]` muda, e pelo
  evento `deleted`. Quando o slug muda, a entrada do slug anterior também é esquecida.
  **Esta lista deve permanecer byte-idêntica à do model `Link::booted()`.**
- **Metadata OG** — TTL 24 h, chave `metadata_{md5($url)}`. Armazena título, descrição e
  imagem extraídos do HTML da URL destino para uso na renderização dos previews sociais.

### Pontos de atenção

- A rota `/api/r/{slug}` foi **desabilitada em 04/11/2025** e está preservada como comentário
  nas linhas 18–32 de `routes/api.php`. Não a reative sem atualizar o ADR 0003
  (`docs/adr/0003-redirect-canonico-em-web-php.md` — criado na Fase 7 do plano).
- `links.clicks` **não** é incrementado no `RedirectController`. O incremento ocorre dentro
  de `ProcessLinkClickJob` via `LinkTrackingService::registrarCliqueFromPayload` (≈ linha 108
  do service). Incrementar aqui quebraria o tracking assíncrono.
- Bot detection usa duas estratégias combinadas: lista estática de 20 padrões de UA
  (`BOT_USER_AGENT_PATTERNS`) + `Jenssegers\Agent\Agent::isRobot()`. Qualquer UA não
  reconhecido como bot é tratado como humano.
- Proteção básica contra SSRF em `isSafeFetchUrl()`: rejeita esquemas não-HTTP, hostnames
  internos (`*.local`, `*.internal`, `localhost`) e IPs privados/loopback literais.

---

## Sub-domínio: Link CRUD (`LinkController`)

### Propósito

RESTful CRUD de links para usuários autenticados com e-mail verificado. Também expõe dois
endpoints de leitura de cliques individuais (`/clicks` e `/clicks-list`) e recebe a
cross-mount do endpoint de analytics legado.

### Feature espelhada no frontend

`frontend-next/src/features/links/`

### Endpoints

| Verb | Path | Controller@action | Middleware (route-specific) | Auth |
|---|---|---|---|---|
| GET | /api/links | `LinkController@index` | — | required (JWT + verified) |
| POST | /api/links | `LinkController@store` | — | required (JWT + verified) |
| GET | /api/links/{id} | `LinkController@show` | — | required (JWT + verified) |
| PUT | /api/links/{id} | `LinkController@update` | — | required (JWT + verified) |
| DELETE | /api/links/{id} | `LinkController@destroy` | — | required (JWT + verified) |
| GET | /api/link/{id}/clicks | `LinkController@getClicksData` | — | required (JWT + verified) |
| GET | /api/link/{id}/clicks-list | `LinkController@getClicksList` | — | required (JWT + verified) |
| GET | /api/links/{id}/analytics | `AnalyticsController@getLinkLegacyAnalytics` | — | required (JWT + verified) |

> O endpoint `GET /api/links/{id}/analytics` é declarado dentro do grupo de rotas do
> `LinkController` (prefixo `links/`, linha 96 de `routes/api.php`), mas é **tratado pelo
> `AnalyticsController`**, não pelo `LinkController`. Ver sub-domínio Analytics.

### Services e Repositories

- `LinkServiceInterface` → `LinkService` (impl.) — operações de negócio: `getAllUserLinks`,
  `getUserLink`, `createLink(CreateLinkDTO)`, `updateLink(UpdateLinkDTO)`, `deleteLink`.
- `LinkAuditService` — registra eventos de criação, atualização e deleção em `link_audits`.
- `LinkRepository` — acesso a dados; usado internamente por `LinkService`.

### Pontos de atenção

- Ownership é verificado em todas as actions via `LinkService::getUserLink` (filtra por
  `user_id`) ou `BaseController::findOwnedLink`. Nunca acesse `Link::find($id)` diretamente
  sem checar o usuário dono.
- `getClicksData` e `getClicksList` retornam JSON bruto, **sem** o envelope do
  `NormalizeApiResponse`. O frontend consome esses endpoints com shape diferente dos demais.
- `getClicksList` usa `ilike` (case-insensitive no PostgreSQL) — o fallback SQLite em testes
  usa `like`, o que pode causar divergências de comportamento em buscas case-sensitive.
- `UpdateLinkRequest::hasDataToUpdate()` exige ao menos um campo no corpo da requisição;
  o controller rejeita PUTs sem payload com 422.

---

## Sub-domínio: Link Metadata (`LinkMetaController`)

### Propósito

Fornece dados auxiliares da lista de links (sparkline, tendência, preview OG, status de
saúde da URL) em um único round-trip via `batch-meta` ou individualmente. Os shapes de
resposta de todas as cinco actions são locked — são consumidos diretamente pelo dashboard
do frontend.

### Feature espelhada no frontend

`frontend-next/src/features/links/` (cards da lista de links, indicadores de tendência).

### Endpoints

| Verb | Path | Controller@action | Middleware (route-specific) | Auth |
|---|---|---|---|---|
| POST | /api/links/batch-meta | `LinkMetaController@batchMeta` | — | required (JWT + verified) |
| GET | /api/links/{id}/sparkline | `LinkMetaController@sparkline` | — | required (JWT + verified) |
| GET | /api/links/{id}/trend | `LinkMetaController@trend` | — | required (JWT + verified) |
| GET | /api/links/{id}/preview | `LinkMetaController@preview` | — | required (JWT + verified) |
| GET | /api/links/{id}/health | `LinkMetaController@health` | — | required (JWT + verified) |

> `batch-meta` aceita até 50 IDs por chamada (`ids: int[], days?: int (1–90)`).

### Services e Repositories

- `MetricsService` — `getLinkSparkline(int $id, int $days)` e `getLinkTrend(int $id, int $window)`.
- `LinkPreview` (model) — armazena favicon, og_title e og_image_url resultantes do preview fetch.

### Jobs disparados

- `FetchLinkPreviewJob` (tries=2, backoff padrão do framework) — disparado de forma
  fire-and-forget quando a preview está ausente ou mais antiga que 24 h. Dispatchado em
  `batchMeta` (para cada link sem preview fresco) e em `preview` (individual).

### Cache

Nenhum cache gerenciado diretamente por este controller. Os dados de sparkline e trend
são computados sob demanda via `MetricsService` (que pode usar cache interno). O resultado
do `health` é lido da coluna `links.health_status` (atualizada por job agendado).

### Pontos de atenção

- Os shapes de resposta de `sparkline`, `trend`, `preview` e `health` são **locked**:
  não altere nomes de campos ou aninhamento sem atualizar o frontend correspondente.
- `health.http_code` está reservado e sempre retorna `null` — campo preparado para uso futuro.
- O `batchMeta` usa `Link::whereIn(...)->where('user_id', ...)` para garantir ownership em
  lote; IDs de outros usuários são silenciosamente ignorados (não retornam erro 403).
- Todos os endpoints deste controller usam `firstOrFail` — erros de autorização resultam
  em 404/ModelNotFoundException (não 403) para evitar enumeration de IDs.

---

## Pontos de atenção consolidados (todos os sub-domínios)

- **`/api/r/{slug}` desabilitado desde 04/11/2025.** As linhas 18–32 de `routes/api.php`
  preservam o comentário histórico. Não reabrir sem documentar no ADR 0003.
- **`/r/{slug}` e `/{slug}` vivem em `routes/web.php`**, não em `routes/api.php`, por causa
  do Open Graph + tracking assíncrono. Mover para a API quebraria os previews sociais.
- **Testes de regressão obrigatórios**: `tests/Feature/RedirectTest.php` e
  `tests/Feature/ProcessLinkClickJobTest.php` cobrem o caminho crítico. Qualquer mudança
  no controller de redirect, job ou model `Link` deve mantê-los verdes antes do merge.
- **Incremento de `links.clicks` exclusivamente no job**: o counter está em
  `LinkTrackingService::registrarCliqueFromPayload` (~linha 108). Duplicar o incremento
  em outro ponto causa contagem dupla.
- **Lista de campos de invalidação de cache deve permanecer exata**:
  `[slug, is_active, expires_at, starts_in, original_url, click_limit]` — byte-idêntica
  ao array em `Link::booted()`. Adicionar campo ao model sem atualizar `booted()` mantém
  o cache stale.
