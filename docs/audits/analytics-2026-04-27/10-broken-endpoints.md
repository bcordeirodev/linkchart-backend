# Endpoints quebrados em runtime — Audit cross-cutting

## Scope

Documenta endpoints e métodos que **lançam erro fatal em runtime** por dependência não injetada, propriedade não declarada, ou call de método inexistente. Esses bugs estão presentes na branch `main` e são acionáveis sem dependência de outros módulos.

## Findings

### 🔴 Crítico — 5 métodos do `AnalyticsController` referenciam `$this->advancedAnalyticsService` não declarado

**Arquivo:** `app/Http/Controllers/Analytics/AnalyticsController.php`

O construtor injeta apenas `LinkAnalyticsService` e `UserAgentAnalyticsService`. Não há propriedade `advancedAnalyticsService` declarada nem injetada. Os 5 métodos abaixo lançam `Error: Undefined property` se chamados:

| Método | Linha | Endpoint hipotético |
|--------|-------|---------------------|
| `getBrowserAnalytics` | 313 | (não roteado em `routes/api.php`) |
| `getRefererAnalytics` | 341 | (não roteado) |
| `getEngagementAnalytics` | 369 | (não roteado) |
| `getPerformanceByRegion` | 397 | (não roteado) |
| `getTrafficQualityReport` | 425 | (não roteado) |

**Fato atenuante:** nenhum desses métodos está mapeado em `routes/api.php` hoje, então o erro só aparece em testes manuais ou se alguém adicionar a rota. Mesmo assim, é dead code que polui o controller e quebra qualquer auto-discovery / route caching futuro.

**Decisão necessária:**

a) **Remover os 5 métodos** (recomendado se a feature não está no roadmap) — os módulos `audience` e `temporal` já cobrem parte desses cenários.

b) **Implementar `AdvancedAnalyticsService`** e injetar — os métodos sugerem que houve uma refactoring abandonada. Se for retomar, criar o service com os métodos esperados (`getBrowserAnalytics($linkId)`, etc.) e adicionar rotas correspondentes.

### 🔴 Crítico — `now()->va()` em `MetricsService.php:162`

**Arquivo:** `app/Services/Analytics/MetricsService.php:162`

```php
Carbon::now()->va()  // ❌ método inexistente
```

PHPStan já flagou esse erro no relatório do baseline gerado nesta sessão. Provavelmente typo de `->subDays(...)`, `->subHours(...)` ou similar — verificar git blame para entender intent original. Causa fatal error se a code path é executada.

**Caminho de chamada:** `getUserPerformanceMetrics` → invoca métricas que dependem dessa data → ProstHttpException 500 para o usuário.

### 🟡 Importante — `getHeatmapDataRealtime` não tem rota registrada

**Arquivo:** `app/Http/Controllers/Analytics/AnalyticsController.php`

Método público existente para servir polling de heatmap em alta frequência. Sem rota em `routes/api.php`. Resultado: dead code do BE (gera confusão sobre "tem ou não tem realtime?") e o FE acaba chamando o endpoint completo (`getHeatmapData`) a cada 30s, **executando agregações pesadas desnecessárias** (ver `03-heatmap.md`).

### 🟡 Importante — Mismatch URL entre service e hook em heatmap

**Arquivo:** `frontend/src/services/analytics.service.ts` (`getLinkHeatmap`)

`analyticsService.getLinkHeatmap(linkId)` retorna `Promise<unknown>` e está órfão (nenhum hook chama). O hook `useHeatmapData.ts` faz `api.get()` direto. Se alguém migrar para o service esperando tipagem, vai colidir com o tipo `unknown`.

## Recommendations (priorizadas)

1. **[HIGH]** Decidir entre remover ou implementar os 5 métodos do `AnalyticsController`. **Prefere remoção** — o backlog de fixes reais é maior que o valor estimado dessa feature. Se manter, criar issue separada.
2. **[HIGH]** Corrigir `now()->va()` em `MetricsService.php:162`. Investigar git blame, restaurar intent.
3. **[MEDIUM]** Registrar rota para `getHeatmapDataRealtime` ou remover o método. Decisão depende de `03-heatmap.md`.
4. **[LOW]** Tipar `getLinkHeatmap` no service ou remover.

## For the Fix Agent

- **Files**:
  - `backend/app/Http/Controllers/Analytics/AnalyticsController.php` (linhas 313, 341, 369, 397, 425)
  - `backend/app/Services/Analytics/MetricsService.php:162`
  - `frontend/src/services/analytics.service.ts` (`getLinkHeatmap`)
- **Tests**: feature tests garantindo que `routes/api.php` só registra métodos válidos; unit test para `MetricsService::getUserPerformanceMetrics`.
- **Migration**: nenhuma.
- **Estimated effort**: **S** (2h se for remoção; M se implementar `AdvancedAnalyticsService`).
- **Dependencies**: nenhuma — pode ser fixado isolado, primeiro PR do roadmap.

## Out of Scope

- Decidir produtos: se há demanda por `getBrowserAnalytics` independente do `audience` endpoint atual → discussão com produto.
- Implementação de `AdvancedAnalyticsService` real (ver `12-monolith-refactor.md` para alternativa).
