# Public Analytics — Audit

> Data: 2026-04-27
> Escopo: módulo de analytics público (sem autenticação) acessível via `/public-analytics/{slug}` no frontend e `GET /api/public/{link,analytics}/{slug}` no backend.

## Scope

Auditar end-to-end o módulo `public-analytics`, que expõe analytics básicos de qualquer link encurtado a visitantes não autenticados. O fluxo cobre dois endpoints:

- `GET /api/public/link/{slug}` → `PublicLinkController@showBySlug` → `PublicLinkResource`.
- `GET /api/public/analytics/{slug}` → `PublicLinkController@basicAnalytics` (resposta JSON inline).

Frontend consome ambos em paralelo via `usePublicAnalytics`, alimentando `PublicMetrics`, `PublicCharts`, `LinkInfoCard`, `AnalyticsInfo`, `PublicAnalyticsHeader` e os states `ErrorState`/`LoadingState`. A página de rota é `src/pages/public/PublicAnalyticsPage.tsx`.

Foram analisados:

- `routes/api.php` (linhas 40–44) — definição da rota e ausência de throttle.
- `app/Providers/AppServiceProvider.php` — limiters declarados (`login`, `public-shorten`, `redirect`).
- `app/Http/Controllers/Links/PublicLinkController.php::basicAnalytics`.
- `app/Http/Resources/PublicLinkResource.php`.
- `app/Models/Click.php` (campos disponíveis para agregação).
- Frontend: `usePublicAnalytics.ts`, `types/index.ts`, `services/link-public.service.ts`, components em `features/public-analytics/components/`.

## Data Flow

### Backend

**Rota** (`routes/api.php:40-44`):

```php
Route::prefix('public')->controller(PublicLinkController::class)->group(function () {
    Route::post('/shorten', 'store')->middleware('throttle:public-shorten');
    Route::get('/link/{slug}', 'showBySlug');                   // SEM throttle
    Route::get('/analytics/{slug}', 'basicAnalytics');          // SEM throttle
});
```

Apenas `POST /shorten` está atrás de `throttle:public-shorten` (10/min/IP). Os dois GETs públicos não têm rate limit dedicado e dependem somente do limiter padrão do Laravel (`api`, normalmente 60/min/IP via `RouteServiceProvider`, mas o `AppServiceProvider` não redefine isso explicitamente).

**`basicAnalytics(string $slug)`** (`PublicLinkController.php:107-197`):

1. `Link::where('slug', $slug)->first()` — busca direto no DB **sem cache**, **sem filtro `is_active`** (note: a rota retorna analytics até de links inativos/expirados — diferente de `showBySlug`, que filtra `is_active=true` e respeita `expires_at`/`starts_in`).
2. Monta payload base: `total_clicks`, `created_at`, `is_active`, `short_url`, `has_analytics`.
3. Se `clicks > 0`, executa **3 queries adicionais** sobre `clicks`:
   - Top 5 países: `GROUP BY country`.
   - Device breakdown: `GROUP BY device`.
   - Cliques por hora (últimos 7 dias): `EXTRACT(HOUR FROM created_at)` — Postgres-specific.
4. Preenche horas faltantes (0–23) com 0.
5. Retorna JSON cru (não passa por `Resource` nem pelo middleware `NormalizeApiResponse` é debatível — a rota está dentro do prefixo `api`, então o middleware global se aplica e pode transformar a resposta).

**`showBySlug`** (`PublicLinkController.php:69-99`): retorna `PublicLinkResource`, que expõe: `id`, `slug`, `title`, `original_url`, `short_url`, `clicks`, `is_active`, `created_at`, `expires_at`, `is_public`, `has_analytics`, `domain`. **Filtra** `is_active=true`, `expires_at > now`, `starts_in <= now`.

**Inconsistência crítica:** `showBySlug` esconde links inativos/expirados (404), mas `basicAnalytics` **não** — qualquer pessoa com o slug pode ver `total_clicks`, gráficos e `created_at` mesmo de links já desativados.

### Frontend

**Hook `usePublicAnalytics`** (`hooks/usePublicAnalytics.ts:23-124`):

- Dispara duas requisições em paralelo via `Promise.all`:
  - `api.get(`/api/public/link/${slug}`)` (chamada direta com `api`, **não** via `publicLinkService`).
  - `api.get(`/api/public/analytics/${slug}`)`.
- Faz dois `setTimeout` artificiais (100ms antes do fetch e 50ms antes de setar dados, mais 100ms para tirar loading) — total de ~250ms de delay artificial em cada navegação. Comentários dizem "evitar problemas de transição/flicker".
- Type assertions agressivas: `(linkResponse as any).data`, `analyticsResponse as any` — o serviço tipado existe (`publicLinkService.getLinkBySlug` / `getPublicAnalytics`) mas o hook não o usa.
- `debugInfo` é uma string concatenada exposta no `ErrorState` em produção (linha 41 de `ErrorState.tsx`: `Debug: {debugInfo}`).
- Cleanup parcial: `timeoutRef` cobre apenas o último `setTimeout`; os dois `await new Promise(setTimeout)` internos não têm cancelamento, então um unmount durante o fetch ainda chama `setState` em componente desmontado.

**Tipos** (`types/index.ts`):

- `PublicAnalyticsData` reflete o JSON do backend (não há um `LinkBasicAnalytics` formal no BE — o controller retorna array). O nome no FE é `PublicAnalyticsData`; o `link-public.service.ts` define `PublicAnalyticsResponse` com **subset menor** (sem `charts`).
- Há divergência: `PublicAnalyticsResponse` em `link-public.service.ts:32-38` não inclui `charts`, mas `PublicAnalyticsData` em `types/index.ts:6-23` inclui. O hook usa o tipo de `types/index.ts` e ignora o serviço.

**Componentes**: respeitam o design system (`MetricCardOptimized`, `EnhancedPaper`, `ApexChartWrapper`, `formatBarChart`, `formatPieChart`). `LinkInfoCard.tsx` tem **434 linhas** (acima do limite de 200 do `.cursorrules`) e usa gradientes hardcoded (`linear-gradient(135deg, #1976d2 0%, #42a5f5 100%)`) em vez de `createThemeGradient`. `AnalyticsInfo.tsx` tem ~70 linhas de código comentado (linhas 28–97).

## Findings

### Crítico

**[C1] Endpoint analytics público sem rate limit dedicado** — `routes/api.php:43`. Não está em nenhum throttle group; um scraper pode iterar slugs de 4–6 chars e mapear todos os links públicos do sistema, incluindo `total_clicks` e gráficos. Risco de DoS por enumeração e de vazamento de dados agregados.

**[C2] `basicAnalytics` retorna dados de links inativos/expirados** — `PublicLinkController.php:110`. Diferente de `showBySlug`, não filtra `is_active`, `expires_at`, `starts_in`. Isso significa que mesmo após o dono desativar/expirar um link, os analytics continuam expostos publicamente. Bug de privacidade.

**[C3] Não há autorização nenhuma sobre quais links têm analytics públicos** — qualquer link encurtado (autenticado ou não) tem analytics expostos via `/api/public/analytics/{slug}`. Não há flag `is_public_analytics` no model `Link`. Um usuário autenticado que encurta um link privado para uso interno tem suas métricas (clicks, países, devices, distribuição horária) acessíveis a qualquer um que descubra o slug. **Esse é o achado mais grave do módulo** — a feature foi desenhada como se todos os links fossem públicos por natureza, o que não é verdade para links criados por usuários autenticados.

### Importante

**[I1] Sem cache de resposta** — `basicAnalytics` executa 1 + 3 queries em cada request, sem `Cache::remember`. Para links populares, qualquer pico em `/public-analytics/{slug}` martela o Postgres. Recomenda-se cache TTL ~5min por slug (invalidado via observer no `Click` ou TTL natural).

**[I2] Hook não usa o `publicLinkService` tipado** — `usePublicAnalytics.ts:50-52` chama `api.get` direto com `as any`, ignorando `publicLinkService.getLinkBySlug` e `getPublicAnalytics`. Quebra o padrão `BaseService` documentado em `CLAUDE.md`. Resulta em perda de tipagem e duplicação de URL strings.

**[I3] `debugInfo` exposto na UI de erro em produção** — `ErrorState.tsx:41`. String contém ID do link e contagem de cliques. Vazamento menor mas inadequado para produção. Deveria ser condicional a `import.meta.env.DEV`.

**[I4] `setTimeout` artificiais no hook** — três delays totalizando ~250ms (`usePublicAnalytics.ts:46, 69, 81`). Comentários sugerem que mascaram bugs de renderização/flicker em vez de resolvê-los. Atrasa percepção de performance e gera memory leak parcial (timeouts internos sem cleanup).

**[I5] Cidade ausente é OK, mas top_countries com apenas 1 visitante de país pequeno pode identificar** — atualmente só `country` é exposto (não `city`/`state`/`iso_code`/`latitude`). Mesmo assim, num link com 3 cliques, ver "Brasil: 1, Islândia: 1, Japão: 1" pode permitir inferência sobre quem abriu. Considerar limiar mínimo (`HAVING COUNT(*) >= N`) ou só expor charts quando `total_clicks >= 10`.

**[I6] `LinkInfoCard.tsx` viola limite de 200 linhas do `.cursorrules`** — 434 linhas, três blocos de gradientes hardcoded em vez de `createThemeGradient`. Refatorar em subcomponentes (`LinkInfoActions`, `LinkInfoMeta`).

**[I7] `original_url` exposto integralmente em rota pública** — `PublicLinkResource.php:29`. Faz sentido para a UI de "visitar destino", mas combinado com [C3] significa que qualquer slug-guesser obtém o destino real do link, mesmo de links privados. Essa é a parte mais sensível: links encurtados podem apontar para URLs internas com tokens, drives compartilhados, etc.

### Minor

**[M1] Inconsistência de naming** — frontend usa `PublicAnalyticsData` e `PublicAnalyticsResponse` para o mesmo recurso (com schemas diferentes). Backend não tem nome formal (`LinkBasicAnalytics` é só o nome lógico do método). Padronizar para `BasicAnalyticsResponse` em ambos.

**[M2] Código morto em `AnalyticsInfo.tsx`** — linhas 28–97 são bloco comentado. Remover.

**[M3] `PublicAnalyticsHeader.tsx:28` usa gradiente hardcoded** com hex literais em vez de `createTextGradient(theme, 'primary')` (que é usado corretamente em `PublicCharts.tsx:55`).

**[M4] `LoadingState.tsx` não é usado** — `PublicAnalyticsPage.tsx:55-57` usa `<PublicAnalyticsSkeleton />` em vez do `LoadingState` exportado pelo módulo. Remover ou documentar a divergência.

**[M5] Query de horas usa `EXTRACT(HOUR FROM created_at)`** — Postgres-only. Fica acoplado ao driver mas funciona, já que o projeto fixou `pgsql`. Marcar para Prisma equivalente: usar `Prisma.sql` raw ou `clicks.hour_of_day` (campo já enriquecido em `Click`).

**[M6] `total_clicks` vem de `link.clicks` (denormalizado)**, mas charts vêm de agregações em `clicks`. Se houver drift (ex: ao deletar cliques manualmente), os números não baterão. Não é defeito do módulo, mas vale anotar.

**[M7] Resposta de `basicAnalytics` não passa por Resource** — viola padrão do projeto que usa `JsonResource` (vide `PublicLinkResource`). Criar `BasicAnalyticsResource` para padronizar.

## Recommendations

Em ordem de prioridade:

1. **Decidir o modelo de privacidade** (bloqueia tudo). Opções:
   - **a)** Adicionar coluna `links.public_analytics` (bool, default `false`); `basicAnalytics` retorna 404 se a flag estiver desligada. Usuário autenticado opta in via UI de criação/edição.
   - **b)** Restringir analytics públicos APENAS a links criados sem autenticação (`user_id IS NULL`). Links de usuários autenticados retornam 404 em `/api/public/analytics/{slug}`.
   - **c)** Manter o comportamento atual mas tornar explícito na UI de criação ("este link terá analytics públicos").
   Recomendo **(b)** como mínimo imediato — alinha com o nome do controller (`PublicLinkController`) e com a intenção de `is_public = user_id === null` em `PublicLinkResource`.

2. **Adicionar `throttle:public-analytics`** em `AppServiceProvider`: 30/min/IP por slug, ou 120/min/IP global. Aplicar em `/link/{slug}` e `/analytics/{slug}`.

3. **Filtrar links inativos/expirados/não iniciados** em `basicAnalytics` (mesma lógica de `showBySlug`).

4. **Cache via `Cache::remember("public_analytics:{$slug}", 300, …)`**, invalidado em `LinkObserver` (mesmo lugar onde `findActiveBySlugCached` invalida).

5. **Mover queries para `LinkAnalyticsService`** com método dedicado `getPublicBasicAnalytics(string $slug)`, retornando DTO. Controller fica magro, alinhado com SRP e com a migração para NestJS.

6. **Agregação anti-fingerprint**: aplicar `HAVING COUNT(*) >= 2` em top_countries, ou só expor `charts` quando `total_clicks >= 10`. Top cities/states **não** devem ser adicionados publicamente.

7. **Frontend**: trocar `api.get` por `publicLinkService` no hook; remover `setTimeout` artificiais; condicionar `debugInfo` a `import.meta.env.DEV`; refatorar `LinkInfoCard` em subcomponentes; criar `BasicAnalyticsResponse` único e remover `PublicAnalyticsResponse` duplicado em `link-public.service.ts`.

8. **Criar `BasicAnalyticsResource`** no backend e padronizar resposta.

## For the Fix Agent

- **Files**:
  - Backend:
    - `app/Http/Controllers/Links/PublicLinkController.php` — adicionar filtros, mover lógica para service, retornar Resource.
    - `app/Providers/AppServiceProvider.php` — adicionar `RateLimiter::for('public-analytics', …)`.
    - `routes/api.php` — aplicar `throttle:public-analytics` nas duas rotas GET.
    - `app/Services/Analytics/LinkAnalyticsService.php` (ou novo `PublicAnalyticsService`) — método `getBasicAnalytics(string $slug)`.
    - `app/Http/Resources/BasicAnalyticsResource.php` — novo.
    - `app/Models/Link.php` + nova migration — adicionar coluna `public_analytics` (se opção 1a) OU não mexer (se opção 1b).
    - `app/Observers/LinkObserver.php` (se existir) — invalidar cache `public_analytics:{slug}` em `saved`/`deleted`.
  - Frontend:
    - `src/features/public-analytics/hooks/usePublicAnalytics.ts` — usar `publicLinkService`, remover delays, fixar cleanup, condicionar debug.
    - `src/features/public-analytics/types/index.ts` — alinhar com backend, remover duplicação.
    - `src/services/link-public.service.ts` — incluir `charts` em `PublicAnalyticsResponse` ou unificar tipo.
    - `src/features/public-analytics/components/info/LinkInfoCard.tsx` — quebrar em subcomponentes, trocar gradientes hardcoded.
    - `src/features/public-analytics/components/info/AnalyticsInfo.tsx` — remover código morto.
    - `src/features/public-analytics/components/header/PublicAnalyticsHeader.tsx` — usar `createTextGradient`.
    - `src/features/public-analytics/components/states/ErrorState.tsx` — condicionar `debugInfo`.

- **Tests**:
  - `tests/Feature/PublicAnalyticsTest.php` (novo): 404 em link inativo, expirado, futuro; 404 (ou 403) em link com analytics privados conforme decisão; rate limit dispara após N requests; cache hit no segundo request; agregação respeitando floor mínimo.
  - `tests/Feature/PublicLinkTest.php` (se houver) — cobrir `showBySlug` para coerência.
  - Frontend não tem suite — validar manualmente fluxo loading → success → erro → unmount durante fetch.

- **Migration**: **yes** (se opção 1a) — coluna `public_analytics boolean default false` em `links`, com backfill `true` para `user_id IS NULL` (preservar comportamento atual de links anônimos). **no** se for opção 1b ou 1c.

- **Estimated effort**: **M** (1–2 dias). A decisão de privacidade é a parte mais lenta; o resto é mecânico.

- **Dependencies**:
  - Decisão de produto sobre privacidade (item 1) — bloqueia tudo.
  - Auditoria de `LinkAnalyticsService` para evitar duplicação de lógica.
  - Coordenar com auditoria do módulo `links` se a coluna `public_analytics` for adicionada (impacta `LinkController@store/update`, `LinkResource`, formulários no frontend).

## Out of Scope

- Refatoração geral do `LinkInfoCard.tsx` para tirá-lo abaixo de 200 linhas — agendado, não bloqueante.
- Migração de queries Postgres-specific para Prisma — coberto pela migração geral.
- Substituir `setTimeout` por `useTransition`/Suspense no hook — investigação separada de UX.
- Re-design da página `PublicAnalyticsPage.tsx` (animations, layout) — fora do escopo de auditoria de dados/segurança.
- Endpoint `POST /api/public/shorten` — já tem `throttle:public-shorten`, fora do escopo.
- Rota `/r/{slug}` em `routes/web.php` — auditoria separada (vide `MEMORY.md`).
