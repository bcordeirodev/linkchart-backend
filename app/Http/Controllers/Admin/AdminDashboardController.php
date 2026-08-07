<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Admin\AdminStatsServiceInterface;
use App\Http\Controllers\BaseController;
use App\Logging\AppLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Painel admin read-only — agregados globais de crescimento/uso/saúde.
 *
 * Routes (prefix /api/admin, middleware api.auth:api + verified + admin +
 * throttle:admin — o teste AdminRouteProtectionTest garante que nenhuma
 * rota deste prefixo escapa do gate):
 *   GET /overview    → overview
 *   GET /users       → users
 *   GET /engagement  → engagement
 *   GET /health      → health
 *
 * Cache: 300s por endpoint+parâmetros, namespace `admin:` — NUNCA reusar os
 * prefixos `reports:`/`analytics:` (em contexto admin, uma cache key sem
 * escopo é vazamento cross-tenant).
 */
class AdminDashboardController extends BaseController
{
    /** Seconds an admin payload stays cached. */
    private const CACHE_TTL_SECONDS = 300;

    /** TTL curto para endpoints com dado mais mutável (users, health). */
    private const CACHE_TTL_SHORT_SECONDS = 60;

    /** Mapa range → dias; a chave é validada nos endpoints. */
    private const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * @param  AdminStatsServiceInterface  $stats  Agregador global read-only.
     */
    public function __construct(private readonly AdminStatsServiceInterface $stats) {}

    /**
     * GET /api/admin/overview?range=7d|30d|90d
     *
     * Totais da base, comparação com o período anterior e séries diárias de
     * signups, links criados e cliques.
     *
     * @param  Request  $request  Aceita `range` (default 30d).
     * @return JsonResponse { data: {totals, period, series} }.
     */
    public function overview(Request $request): JsonResponse
    {
        $days = $this->validatedDays($request);

        return $this->cached("overview:{$days}", fn () => $this->stats->getOverview($days));
    }

    /**
     * GET /api/admin/users?page=&per_page=&q=&sort=&order=
     *
     * Lista paginada/buscável de usuários com contagens de links e cliques.
     * Expõe PII de terceiros (emails) — todo hit é auditado via
     * AppLogger::adminAction (identificadores apenas, nunca o payload).
     *
     * @param  Request  $request  Paginação, busca e ordenação whitelisted.
     * @return JsonResponse { data: {items, meta} }.
     */
    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:10|max:100',
            'q' => 'sometimes|nullable|string|max:100',
            'sort' => 'sometimes|string|in:created_at,last_login_at,name,links,clicks',
            'order' => 'sometimes|string|in:asc,desc',
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $q = $validated['q'] ?? null;
        $sort = $validated['sort'] ?? 'created_at';
        $order = $validated['order'] ?? 'desc';

        AppLogger::adminAction('admin.users_viewed', [
            'admin_id' => $request->user()->id,
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort,
            'order' => $order,
            'has_query' => filled($q),
        ]);

        $key = 'users:'.md5(serialize([$page, $perPage, $q, $sort, $order]));

        return $this->cached($key, fn () => $this->stats->getUsers($page, $perPage, $q, $sort, $order), self::CACHE_TTL_SHORT_SECONDS);
    }

    /**
     * GET /api/admin/engagement?range=7d|30d|90d
     *
     * Ativação, retorno pós-cadastro, distribuição de links por usuário e
     * WAU/MAU (desde que last_login_at começou a acumular).
     *
     * @param  Request  $request  Aceita `range` (default 30d).
     * @return JsonResponse { data: {...} }.
     */
    public function engagement(Request $request): JsonResponse
    {
        $days = $this->validatedDays($request);

        return $this->cached("engagement:{$days}", fn () => $this->stats->getEngagement($days));
    }

    /**
     * GET /api/admin/health
     *
     * Fila (Redis), failed_jobs, saúde dos links e qualidade do tráfego.
     *
     * @param  Request  $request  Sem parâmetros.
     * @return JsonResponse { data: {...} }.
     */
    public function health(Request $request): JsonResponse
    {
        return $this->cached('health', fn () => $this->stats->getHealth(), self::CACHE_TTL_SHORT_SECONDS);
    }

    /**
     * Valida `range` contra o whitelist e devolve a janela em dias.
     *
     * @param  Request  $request  Incoming request.
     * @return int 7, 30 ou 90.
     */
    private function validatedDays(Request $request): int
    {
        $validated = $request->validate(['range' => 'sometimes|string|in:7d,30d,90d']);

        return self::RANGES[$validated['range'] ?? '30d'];
    }

    /**
     * Executa o callback com cache namespaced `admin:` e devolve {data}.
     *
     * @param  string  $key  Sufixo da cache key (endpoint + parâmetros).
     * @param  \Closure(): array  $fn  Producer no cache miss.
     * @param  int|null  $ttl  TTL em segundos (default CACHE_TTL_SECONDS).
     * @return JsonResponse { data: mixed }.
     */
    private function cached(string $key, \Closure $fn, ?int $ttl = null): JsonResponse
    {
        $data = Cache::remember("admin:{$key}", $ttl ?? self::CACHE_TTL_SECONDS, $fn);

        return response()->json(['data' => $data]);
    }
}
