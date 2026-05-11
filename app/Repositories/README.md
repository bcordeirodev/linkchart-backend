# Repositories layer

## Propósito da camada Repositories

Repositories encapsulam as queries Eloquent do sistema. São chamados exclusivamente por Services (e, em casos pontuais, por Jobs). Retornam Models, Collections ou arrays — nunca respostas HTTP. Não contêm lógica de negócio; essa responsabilidade pertence à camada Service.

---

## Inventário (pós-Phase 2)

Apenas **`LinkRepository`** permanece nesta camada.

| Arquivo | Contrato | Status |
|---|---|---|
| `LinkRepository.php` | `Contracts/Repositories/LinkRepositoryInterface` | ativo |
| `WordRepository.php` | — | **removido em R-02** (import inválido para `App\Models\Word` inexistente) |
| `ChartRepository.php` | — | **removido em R-03** (sem callers) |

---

## Notas sobre `LinkRepository`

- O método `incrementClicks` foi removido em R-01 (código morto; único caller era `LinkService::processRedirect`, também removido).
- O incremento live de `links.clicks` está em `LinkTrackingService::registrarCliqueFromPayload` (linha ~108) via `DB::table()->increment()` — **não** em nenhum repository. Essa escolha evita disparar Observers e manter o cache do Model estável.
- Algumas queries dependem dos índices compostos adicionados em `2025_09_14_140100_add_performance_indexes_simple.php`; os métodos correspondentes citam a migration no PHPDoc.

---

## Convenções

- Um repository por Model primário, salvo Models pequenos suficientes para query direta.
- Repositories **não** contêm lógica de negócio — essa responsabilidade pertence à camada Service.
- Adicionar Contract quando o repository for mockado em testes ou tiver múltiplas implementações.
- Queries novas devem verificar se o índice necessário existe antes de adicionar colunas ao `WHERE`/`ORDER BY`.
