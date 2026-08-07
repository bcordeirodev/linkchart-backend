<?php

namespace App\Contracts\Admin;

/**
 * Agregados globais (todos os usuários) do painel admin — somente leitura.
 *
 * Regras transversais que TODA implementação deve honrar:
 * - Exclusão de demo via Link::nonDemo() e User::DEMO_ACCOUNT_IDS.
 * - Buckets de dia em America/Sao_Paulo (SqlDateExpr::dateSaoPaulo), com o
 *   predicado SQL sempre na coluna crua (UTC).
 * - Uma fonte por painel: séries/contagens de clique do overview usam
 *   COUNT(clicks); o ranking de usuários usa SUM(links.clicks). Nunca
 *   misturar as duas no mesmo número.
 */
interface AdminStatsServiceInterface
{
    /**
     * Totais, comparação com o período anterior e séries diárias.
     *
     * @param  int  $days  Janela em dias (7|30|90).
     * @return array{totals: array{users:int, links:int, clicks:int}, period: array<string, array{current:int, previous:int, variation_pct: float|null}>, series: array<string, array<int, array{date:string, value:int}>>}
     */
    public function getOverview(int $days): array;

    /**
     * Lista paginada de usuários com contagens agregadas.
     *
     * @param  int  $page  Página (1-based).
     * @param  int  $perPage  Itens por página (10–100).
     * @param  string|null  $q  Busca por nome/email (case-insensitive).
     * @param  string  $sort  Coluna whitelisted: created_at|last_login_at|name|links|clicks.
     * @param  string  $order  asc|desc.
     * @return array{items: array<int, array<string, mixed>>, meta: array{current_page:int, per_page:int, total:int, last_page:int}}
     */
    public function getUsers(int $page, int $perPage, ?string $q, string $sort, string $order): array;

    /**
     * Ativação, retorno pós-cadastro, distribuição de links e WAU/MAU.
     *
     * @param  int  $days  Janela em dias (7|30|90) para as métricas janeladas.
     * @return array<string, mixed>
     */
    public function getEngagement(int $days): array;

    /**
     * Fila, jobs falhados, saúde dos links e qualidade do tráfego.
     *
     * @return array<string, mixed>
     */
    public function getHealth(): array;
}
