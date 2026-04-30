# Onboarding Demo Link — Design Spec

**Data:** 2026-04-30

## Objetivo

Ao criar uma conta pela primeira vez, o usuário recebe automaticamente um link de demo apontando para `https://www.google.com`, com ~1.200 clicks falsos distribuídos realisticamente. O objetivo é mostrar o potencial do sistema de analytics logo no primeiro acesso.

---

## Arquitetura

```
User::create() → UserObserver::created() → SeedDemoLinkJob::dispatch($user)
                                                      ↓
                                          [queue worker executa]
                                                      ↓
                                     OnboardingDemoSeeder::run($user)
                                        ├── cria Link (is_demo=true)
                                        └── insere ~1.200 clicks em batch
```

---

## Arquivos Novos

| Arquivo | Responsabilidade |
|---|---|
| `app/Models/Observers/UserObserver.php` | Escuta `created` no model `User`, dispara `SeedDemoLinkJob` |
| `app/Jobs/SeedDemoLinkJob.php` | Job assíncrono (`ShouldQueue`), delega ao `OnboardingDemoSeeder` |
| `app/Services/Onboarding/OnboardingDemoSeeder.php` | Cria o link demo e os clicks com dados realistas |
| `database/migrations/YYYY_MM_DD_add_is_demo_to_links_table.php` | Adiciona coluna `is_demo` boolean na tabela `links` |

---

## Mudanças em Arquivos Existentes

| Arquivo | Mudança |
|---|---|
| `app/Providers/AppServiceProvider.php` | Registra `User::observe(UserObserver::class)` |
| `app/Models/Link.php` | Adiciona `is_demo` em `$fillable` e `$casts` |

---

## Dados do Link de Demo

| Campo | Valor |
|---|---|
| `title` | `"Exemplo — Google"` |
| `slug` | `Str::random(8)` (único) |
| `original_url` | `https://www.google.com` |
| `is_demo` | `true` |
| `is_active` | `true` |
| `clicks` | `1200` (atualizado após batch insert) |
| `user_id` | ID do usuário recém-criado |

---

## Geração dos Clicks

- **Quantidade:** ~1.200 registros
- **Batch:** inserção em lotes de 500 (`Click::insert()`)
- **Período temporal:** últimos 60 dias, com distribuição por hora realista (pico entre 9h–17h)
- **Distribuição geográfica:** 20 países com pesos (US 30%, BR 20%, GB 10%, DE 8%, etc.)
- **Dispositivos:** mobile 60%, desktop 35%, tablet 4%, bot 1%
- **Referrers:** direct 40%, social 30%, search 20%, other 10%
- **Dados reutilizados:** países, cidades, user agents e referrers já definidos nos seeders existentes, extraídos para o `OnboardingDemoSeeder`

---

## Idempotência

Antes de criar o link demo, o job verifica:

```php
if (Link::where('user_id', $user->id)->where('is_demo', true)->exists()) {
    return; // abort silently
}
```

Isso previne duplicatas em caso de retry automático do job.

---

## Error Handling

- `$tries = 3`, `$backoff = 30` — padrão dos outros jobs do projeto
- Falha no job **não afeta** o registro do usuário (resposta 201 já foi enviada)
- Erros logados no canal `api_errors`

---

## O que NÃO está no escopo

- Testes automatizados (projeto não tem suíte de feature tests)
- Endpoint para forçar re-criação do demo
- Distinção visual no frontend (responsabilidade do frontend consumir `is_demo` conforme necessário)
