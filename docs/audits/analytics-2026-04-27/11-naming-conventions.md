# Naming conventions FE ↔ BE — Audit cross-cutting

## Scope

Inconsistências de nomenclatura, redundâncias e convenções divergentes entre backend (Laravel/PHP, snake_case) e frontend (TS/React, camelCase) que afetam o módulo de analytics.

## Findings

### 🔴 Crítico — `day_of_week` tem 3 convenções incompatíveis

**Tracking** (`LinkTrackingService::enrichTemporalData`): grava `day_of_week` em **ISO N=1-7** (segunda=1, domingo=7).

**Aggregations** (`LinkAnalyticsService::getClicksByDayOfWeekOptimized`): usa `EXTRACT(DOW FROM created_at)` do Postgres, que retorna **0-6** (domingo=0).

**UserAgent service** (`UserAgentAnalyticsService::getDailyPatterns`): usa `Carbon::format('w')` em **UTC** que retorna **0-6** (domingo=0).

**Impacto:** quando o response é montado via `array_merge` no controller, dados podem sair com keys 0-6 e 1-7 misturados na mesma estrutura — gráficos no FE renderizam pico no dia errado. Ver `05-temporal.md` para detalhes.

### 🔴 Crítico — `continents` tipado e renderizado no FE mas backend nunca envia

**FE:** `src/types/analytics/geographic.ts` declara `ContinentData[] continents`. `GeographicInsights.tsx:57-84` itera sobre esse array e exibe distribuição.

**BE:** `getGeographicAnalyticsOptimized` retorna `heatmap_data`, `top_countries`, `top_states`, `top_cities` — **nunca produz continents**. O componente cai num fallback que **infere continente por nome de país hardcoded**, gerando dados falsos para a UI. Ver `04-geographic.md`.

### 🔴 Crítico — 4 campos do `LinkPerformanceDashboard` nunca vêm do backend

**FE:** `analyticsService.getLinkPerformance` (`src/services/analytics.service.ts`) tem um adapter que **hardcoda** os seguintes campos no objeto de retorno:

| Campo | Valor hardcoded |
|-------|-----------------|
| `uptime_percentage` | sempre `100` |
| `performance_score` | espelha `success_rate` |
| `clicks_per_hour` | sempre `0` |
| `visitor_retention` | sempre `0` |

Esses valores são exibidos como métricas reais no painel de Performance. Ver `08-performance.md`.

### 🟡 Importante — `LinkPerformanceDashboard` tem 5+ campos duplicados

**FE:** `src/types/analytics/performance.ts`

3 nomes diferentes para "cliques únicos":
- `uniqueClicks`
- `unique_visitors`
- `summary.unique_visitors`

2 nomes diferentes para "total de cliques":
- `totalClicks`
- `total_redirects_24h`

Mistura camelCase/snake_case no mesmo type. Origem: compatibilidade com gerações de API. Refactoring necessário (ver `08-performance.md`).

### 🟡 Importante — Métodos com prefixo "Real" são heurísticas

**Arquivo:** `app/Services/Analytics/LinkAnalyticsService.php`

| Método | Linha | Comportamento real |
|--------|-------|---------------------|
| `calculateRealResponseTime` | ~1201 | estimativa baseada em volume + distribuição horária (NÃO mede tempo real) |
| `calculateRealSuccessRate` | ~1226 | heurística baseada em "links ativos + atividade recente" |
| `calculateUptimePercentage` | — | derivado de "horas com atividade", não uptime real |

O prefixo "Real" é misleading. Ou:

a) Renomear: `estimateResponseTime`, `estimateSuccessRate`, `estimateActivityHours`. **Recomendado** — preserva código atual e clarifica.

b) Implementar medição real: instrumentar `RedirectController` com timings, expor via `clicks.response_time` (já existe) e gravar success/failure em `redirect_metrics:hour` cache (que já existe mas não é usado nas heurísticas).

### 🟡 Importante — Empty-states divergem da resposta de sucesso

`getLinkGeographicAnalytics` retorna **3 chaves** quando vazio (`top_countries`, `top_states`, `top_cities`) e **4 chaves** quando há dados (adiciona `heatmap_data`). FE precisa de checks defensivos.

Aplicar shape unificado: empty deve retornar **a mesma estrutura** com arrays vazios.

### 🟡 Importante — Mistura snake_case / camelCase nos types FE

**FE types:** `src/types/analytics/*.ts` mistura padrões:

- `clicks_by_hour`, `unique_visitors`, `avg_response_time` (snake_case) — copiados direto da API
- `totalClicks`, `uniqueClicks` (camelCase) — adicionados em momentos diferentes

Não há middleware `humps` no frontend para normalizar. **Decisão:** ou aplicar humps no `ApiClient` e converter tudo para camelCase nos types, ou aceitar snake_case e padronizar (remover os camelCase atuais).

**Recomendado:** aplicar humps na response. Padroniza com TS conventions e evita types híbridos.

### 🟢 Minor — Typo de pasta

`frontend/src/features/analytics/components/perfomance/` (sem o "r" central). Renomear para `performance/`. Atualiza imports em `LinkAnalyticsTabs.tsx:12` e barrel `components/index.ts:11`.

### 🟢 Minor — Fallbacks "Outros" vs "Unknown"

Parsing de User-Agent retorna fallbacks inconsistentes (PT vs EN). Padronizar: usar `Unknown` (consistente com convenções DB/i18n).

### 🟢 Minor — Pasta `Analytics/` no controller mas `analytics/` na rota

`app/Http/Controllers/Analytics/AnalyticsController.php` (PascalCase) vs rota `/api/analytics/...` (lowercase) — coerente com convenções Laravel, sem ação necessária. Documentado para evitar dúvida futura.

## Recommendations (priorizadas)

1. **[HIGH]** Remover `continents` do FE OU implementar no BE (decisão de produto). Ver `04-geographic.md`.
2. **[HIGH]** Padronizar `day_of_week` em ISO 1-7 em todo o pipeline. Migration possível para retroatividade. Ver `05-temporal.md`.
3. **[HIGH]** Remover ou marcar como hardcoded os 4 campos fantasma do `LinkPerformanceDashboard` no adapter `analytics.service.ts`. Ver `08-performance.md`.
4. **[MEDIUM]** Renomear `calculate*Real*` → `estimate*` (escopo: ~5 ocorrências). Documentar como heurística no docblock.
5. **[MEDIUM]** Aplicar humps no `ApiClient` para normalizar response em camelCase. Atualizar types `src/types/analytics/*.ts`. Esforço: M.
6. **[MEDIUM]** Unificar shape de empty-states com sucesso (sempre retornar todas as chaves com arrays vazios).
7. **[LOW]** Renomear pasta `perfomance/` → `performance/`. Atualizar imports.
8. **[LOW]** Padronizar fallback `Unknown` no UA parsing.

## For the Fix Agent

- **Files**:
  - `backend/app/Services/Links/LinkTrackingService.php` (`enrichTemporalData`)
  - `backend/app/Services/Analytics/LinkAnalyticsService.php` (todos `calculate*Real*` + DOW queries)
  - `backend/app/Services/Analytics/UserAgentAnalyticsService.php` (`getDailyPatterns`)
  - `frontend/src/services/analytics.service.ts` (adapter de `getLinkPerformance`)
  - `frontend/src/types/analytics/*.ts` (todos)
  - `frontend/src/features/analytics/components/perfomance/` → `performance/`
  - `frontend/src/lib/api/ApiClient.ts` (humps middleware, se optar)
- **Tests**: feature tests para shape consistente entre empty/success; unit tests para conversões camelCase.
- **Migration**: opcional — se padronizar `day_of_week` retroativamente, criar migration para corrigir clicks existentes.
- **Estimated effort**: **L** (~3-5 dias se incluir humps + DOW retroativo). **M** se apenas types FE.
- **Dependencies**: este audit deve ser feito **depois** dos audits de módulo (especialmente `04-geographic`, `05-temporal`, `08-performance`) para não conflitar.

## Out of Scope

- Decisão produtual sobre `continents`.
- Refactor estrutural do `LinkAnalyticsService` (ver `12-monolith-refactor.md`).
