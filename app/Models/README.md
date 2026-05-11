# Models

## Propósito da camada Models

Os Eloquent models mapeiam tabelas do banco de dados e encapsulam relacionamentos, casts de tipo, escopos e hooks de ciclo de vida. **Nenhuma regra de negócio vive aqui** — validações de negócio, decisões de roteamento de dados e orquestração de side-effects são responsabilidade da camada de Services. Os models expõem dados e relações; os Services decidem o que fazer com eles.

---

## Inventário e referências cruzadas

| Model | Tabela | Pertence a (BelongsTo) | Tem muitos / tem um (HasMany / HasOne) | Notas |
|---|---|---|---|---|
| `User` | `users` | — | `Link` (HasMany), `EmailVerificationToken` (HasMany via `email ↔ email`) | Observado por `UserObserver` (registrado em `AppServiceProvider::boot`) — dispara `SeedDemoLinkJob` no evento `created` |
| `Link` | `links` | `User` (BelongsTo, nullable — links anônimos permitidos) | `Click` (HasMany), `LinkPreview` (HasOne) | Cache auto-registrado em `static::booted()`; acesso via `findActiveBySlugCached`, TTL 600 s |
| `Click` | `clicks` | `Link` (BelongsTo) | `LinkUtm` (HasOne) | Enriquecido em 3 fases de migration — ver `database/migrations/README.md` |
| `LinkUtm` | `link_utms` | `Click` (BelongsTo via `click_id`) | — | Um registro por clique contendo os parâmetros UTM presentes na requisição recebida |
| `LinkAudit` | `link_audits` | `Link` (BelongsTo), `User` (BelongsTo) | — | Escrito por `LinkAuditService` nas ações create/update/delete de links |
| `LinkPreview` | `link_previews` | `Link` (BelongsTo via `link_id`) | — | Populado por `FetchLinkPreviewJob`; PK não convencional (`link_id`, sem auto-increment, sem timestamps) |
| `EmailVerificationToken` | `email_verification_tokens` | `User` (BelongsTo via `email ↔ email`) | — | Uso único; janela de 24 h para verificação de e-mail, 1 h para reset de senha |

---

## Invariantes de cache (`Link`)

O hot path de redirect (`RedirectController`) depende inteiramente deste cache para evitar hits no Postgres em picos de clique.

| Propriedade | Valor |
|---|---|
| Método | `Link::findActiveBySlugCached(string $slug): ?self` |
| Chave | `link:slug:{slug}` (construída por `Link::slugCacheKey`) |
| TTL | 600 segundos (10 minutos) — `Link::CACHE_TTL_SECONDS` |
| Driver | Default cache driver (Redis em prod, `array` em testes) |

**Invalidação** (gerenciada em `Link::booted()`):

- No evento `saved`: invalida apenas quando ao menos um dos campos de relevância mudou:
  `['slug', 'is_active', 'expires_at', 'starts_in', 'original_url', 'click_limit']`
- Quando o `slug` em si muda, a chave do slug anterior também é esquecida.
- No evento `deleted`: sempre invalida.
- O incremento do contador `clicks` usa `DB::table()->increment()` direto (bypassa model events) — **não** dispara invalidação.

> **Regra hard:** adicionar uma coluna cujo valor deva invalidar o cache ao ser salvo requer atualizar a lista de campos em `Link::booted()`. Ver também `database/migrations/README.md` para a política correspondente no lado das migrations.

---

## Observers

| Observer | Modelo observado | Registro | Responsabilidade |
|---|---|---|---|
| `App\Models\Observers\UserObserver` | `User` | `AppServiceProvider::boot()` via `User::observe(UserObserver::class)` | Dispara `SeedDemoLinkJob` quando um novo `User` é criado |

`Link` **não possui observer separado** — sua lógica de cache é auto-registrada em `Link::booted()`.

---

## Onde colocar coisas

- **Novo model** → `app/Models/<Name>.php`. Adicionar uma linha na tabela de inventário acima.
- **Novo observer** → `app/Models/Observers/<Name>Observer.php`. Registrar em `AppServiceProvider::boot()` com `<Model>::observe(<Name>Observer::class)`.
- **Novo campo `$fillable` ou `$casts`** → adicionar a `@property` correspondente no bloco PHPDoc da classe.
- **Campo que deve invalidar o cache de slug** → incluir no array de relevância em `Link::booted()` e documentar em `database/migrations/README.md`.
