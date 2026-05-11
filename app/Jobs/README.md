# Jobs layer

## Propósito da camada Jobs

Jobs são unidades de trabalho assíncrono enfileiradas no Redis. Cada job declara `$tries` e `$backoff` como propriedades de classe. A postura de idempotência de cada job está documentada no PHPDoc da classe (adicionado na Phase 3, Task 3.2). Lifecycle logging (started / succeeded / failed / retrying) vai para o canal `jobs` via `AppLogger`.

---

## Inventário

| Job | Trigger | Queue | tries | backoff | Idempotente? | Efeitos colaterais |
|---|---|---|---|---|---|---|
| `ProcessLinkClickJob` | `RedirectController::dispatchTracking()` (linha ~190) após response 302 | `default` (redis) | 3 | 10 s | **NÃO** — duplicate clicks em retry; under-counting tolerado pelo produto | insert em `clicks` + increment em `links.clicks` via `LinkTrackingService` |
| `SeedDemoLinkJob` | `UserObserver::created` (Models/Observers/UserObserver.php) | `default` | 3 | 30 s | **NÃO** — depende do observer disparar uma única vez | cria Link demo + semeia cliques fictícios via `OnboardingDemoDataService` |
| `FetchLinkPreviewJob` | `LinkMetaController::batchMeta` (linha 76) e `::preview` (linha 168) | `default` | 2 | framework default | **SIM** (`updateOrCreate` em `link_id`) | HTTP fetch de metadados OG + escrita em `link_previews` |
| `LinkHealthCheckJob` | scheduler `hourly()->withoutOverlapping()` em `bootstrap/app.php` linha 10 | `default` | 1 | framework default (irrelevante) | **SIM** (re-verificação pura) | HTTP HEAD/GET em `links.original_url` + atualização de `links.health_*` em lotes de 50 |

---

## Convenções

- Todo novo job **deve** declarar `public int $tries` e `public int $backoff` como propriedades de classe.
- Todo novo job **deve** ser idempotente; se não for possível, documentar o motivo no PHPDoc da classe.
- Lifecycle logging usa `AppLogger::jobStarted/jobSucceeded/jobFailed/jobRetrying` — canal `jobs`.
- Usar o trait `HasLogContext` para propagar `request_id` do dispatcher (payload do controller) até o job, correlacionando logs entre os dois lados da fronteira HTTP/queue.

---

## Onde colocar coisas

- Novo job → `app/Jobs/<Nome>Job.php`. Adicionar à tabela acima.
- Agendamento → `bootstrap/app.php::withSchedule(...)` (não em `routes/console.php`).
- Testes → `tests/Feature/<Nome>JobTest.php`.
