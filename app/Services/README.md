# Services layer

## Propósito da camada Services

Services são donos da lógica de negócio da aplicação. São chamados por Controllers e, ocasionalmente, por Jobs. Recebem suas dependências via constructor injection (Repositories, outros Services). Não contêm queries Eloquent diretamente — isso é responsabilidade da camada Repository. Não contêm preocupações HTTP (headers, responses) — isso é responsabilidade dos Controllers.

---

## Subpastas

### `Analytics/`

Orquestra e agrega dados de analytics para os endpoints de `/api/analytics/link/{id}/*`.

| Arquivo | Papel |
|---|---|
| `LinkAnalyticsOrchestrator.php` | Fan-out para os demais serviços de analytics; injetado pelo `AnalyticsController`. |
| `DashboardAnalyticsService.php` | KPIs de dashboard (total de cliques, taxa de crescimento, top referrers). |
| `GeographicAnalyticsService.php` | Distribuição geográfica por país/cidade. |
| `TemporalAnalyticsService.php` | Séries temporais de cliques (diário, semanal, heatmap). |
| `AudienceAnalyticsService.php` | Segmentação de audiência (device, browser, OS). |
| `InsightsAnalyticsService.php` | Coordena os 8 `InsightGenerator`s via Strategy Pattern. |
| `MetricsService.php` | Métricas de performance de request para telemetria. |

Suporte interno em `Analytics/Insights/`:
- `Generators/` — 8 generators (Device, Diversity, Engagement, Geographic, Performance, Retention, Security, Temporal).
- `InsightGeneratorInterface.php` / `InsightGeneratorRegistry.php` — contrato e registro do Strategy Pattern.
- `Support/UserAgentParser.php` — parsing de User-Agent sem dependência de camada superior.

Para o lado consumidor (controller), ver `app/Http/Controllers/Analytics/README.md`.

### `Links/`

Domínio principal do produto.

| Arquivo | Papel |
|---|---|
| `LinkService.php` | CRUD de links, validações de negócio, quota de usuário. |
| `LinkTrackingService.php` | Enriquecimento de cliques (GeoIP, UA, UTM, dados temporais/comportamentais); chamado de forma assíncrona via `ProcessLinkClickJob`. Contém o incremento live de `links.clicks` via query direta (linha ~108). |
| `LinkAuditService.php` | Registro de eventos de auditoria em `link_audits`. |
| `LinkPreviewService.php` | Busca e cache de metadados Open Graph para preview. |
| `LinkSafetyService.php` | Verificação de segurança (Safe Browsing) de URLs. |
| `ClickVelocityService.php` | Detecção de anomalia de cliques por velocidade (fraude). |

### `Onboarding/`

| Arquivo | Papel |
|---|---|
| `OnboardingDemoDataService.php` | Cria link demo e semeia cliques fictícios para novos usuários. Chamado por `SeedDemoLinkJob`. |

### Root-level

| Arquivo | Papel |
|---|---|
| `EmailService.php` | Envio de e-mails transacionais via API do Brevo. |
| `EmailVerificationService.php` | Geração e validação de tokens de verificação de e-mail. |

---

## Tabela de Contracts

Todos os contratos estão em `app/Contracts/` e são vinculados em `AppServiceProvider::register()`.

| Service | Contract |
|---|---|
| `LinkService` | `Services/LinkServiceInterface` |
| `DashboardAnalyticsService` | `Analytics/DashboardAnalyticsInterface` |
| `GeographicAnalyticsService` | `Analytics/GeographicAnalyticsInterface` |
| `TemporalAnalyticsService` | `Analytics/TemporalAnalyticsInterface` |
| `AudienceAnalyticsService` | `Analytics/AudienceAnalyticsInterface` |
| `InsightsAnalyticsService` | `Analytics/InsightsAnalyticsInterface` |
| `LinkAnalyticsOrchestrator` | `Analytics/LinkAnalyticsOrchestratorInterface` |

`LinkRepository` também tem contrato em `Contracts/Repositories/LinkRepositoryInterface`.

---

## Convenções

- **Constructor injection only** — nenhum service locator (R-12 corrigiu o último remanescente em `LinkTrackingService`).
- **Logging via `AppLogger`** — nunca `Log::*` diretamente (mandato do `CLAUDE.md`; R-10 corrigiu as violações restantes).
- **Cache**: Services com responsabilidade de cache documentam a chave, o TTL e a estratégia de invalidação no docblock da classe.
- **PHPDoc obrigatório** em todo método público (Phase 3 trouxe a camada à cobertura completa).

---

## Onde colocar coisas

- **Nova lógica de negócio** → novo método no service existente se o domínio bater; caso contrário, novo service na subpasta adequada.
- **Quando criar um Contract** → quando o service tem múltiplas implementações reais OU quando os testes precisam fazer mock dele.
- **Testes** → `tests/Unit/Services/<sub-folder>/`.
