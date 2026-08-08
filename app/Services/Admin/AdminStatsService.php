<?php

namespace App\Services\Admin;

use App\Contracts\Admin\AdminStatsServiceInterface;
use App\Models\Link;
use App\Models\User;
use App\Services\Analytics\Support\SqlDateExpr;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Implementação dos agregados globais do painel admin.
 *
 * Molde: ReportsAnalyticsService (o agregador multi-link existente), com o
 * predicado de user_id removido e a exclusão de demo via Link::nonDemo().
 * Somente leitura — nenhum side effect.
 */
class AdminStatsService implements AdminStatsServiceInterface
{
    /** Fuso do produto para bucketing de dia (predicados SQL ficam em UTC). */
    private const TZ = 'America/Sao_Paulo';

    /** {@inheritDoc} */
    public function getOverview(int $days): array
    {
        // Janela atual: hoje (em SP) - (days-1) até agora; anterior: mesma
        // duração imediatamente antes. Predicados convertem para UTC.
        $now = CarbonImmutable::now(self::TZ);
        $from = $now->subDays($days - 1)->startOfDay();
        $prevFrom = $from->subDays($days);

        $fromUtc = $from->utc();
        $prevFromUtc = $prevFrom->utc();

        // ---- Totais all-time (fonte de cliques: COUNT(clicks) via nonDemo) ----
        $totals = [
            'users' => User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)->count(),
            'links' => Link::nonDemo()->count(),
            'clicks' => (int) Link::nonDemo()
                ->join('clicks', 'clicks.link_id', '=', 'links.id')
                ->count(),
        ];

        // ---- Contagens por janela (atual vs anterior) ----
        $signupsCurrent = User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)
            ->where('created_at', '>=', $fromUtc)->count();
        $signupsPrevious = User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)
            ->whereBetween('created_at', [$prevFromUtc, $fromUtc])->count();

        $linksCurrent = Link::nonDemo()->where('links.created_at', '>=', $fromUtc)->count();
        $linksPrevious = Link::nonDemo()->whereBetween('links.created_at', [$prevFromUtc, $fromUtc])->count();

        $clicksCurrent = (int) Link::nonDemo()
            ->join('clicks', 'clicks.link_id', '=', 'links.id')
            ->where('clicks.created_at', '>=', $fromUtc)->count();
        $clicksPrevious = (int) Link::nonDemo()
            ->join('clicks', 'clicks.link_id', '=', 'links.id')
            ->whereBetween('clicks.created_at', [$prevFromUtc, $fromUtc])->count();

        // ---- Séries diárias com zero-fill ----
        $dates = $this->windowDates($from, $now);

        $series = [
            'signups' => $this->dailySeries(
                User::whereNotIn('id', User::DEMO_ACCOUNT_IDS)->where('created_at', '>=', $fromUtc)->toBase(),
                'created_at',
                $dates
            ),
            'links' => $this->dailySeries(
                Link::nonDemo()->where('links.created_at', '>=', $fromUtc)->toBase(),
                'links.created_at',
                $dates
            ),
            'clicks' => $this->dailySeries(
                Link::nonDemo()
                    ->join('clicks', 'clicks.link_id', '=', 'links.id')
                    ->where('clicks.created_at', '>=', $fromUtc)->toBase(),
                'clicks.created_at',
                $dates
            ),
        ];

        return [
            'totals' => $totals,
            'period' => [
                'signups' => $this->periodComparison($signupsCurrent, $signupsPrevious),
                'links' => $this->periodComparison($linksCurrent, $linksPrevious),
                'clicks' => $this->periodComparison($clicksCurrent, $clicksPrevious),
            ],
            'series' => $series,
        ];
    }

    /**
     * Contagem por dia (fuso SP) com zero-fill sobre a janela.
     *
     * @param  \Illuminate\Database\Query\Builder  $query  Query já filtrada pela janela (predicado na coluna crua).
     * @param  string  $timestampColumn  Coluna qualificada usada no bucket.
     * @param  array<int, string>  $dates  Datas Y-m-d da janela ({@see windowDates}).
     * @return array<int, array{date: string, value: int}>
     */
    private function dailySeries(\Illuminate\Database\Query\Builder $query, string $timestampColumn, array $dates): array
    {
        $dateExpr = SqlDateExpr::dateSaoPaulo($timestampColumn);

        $rows = $query
            ->selectRaw("{$dateExpr} as date, COUNT(*) as value")
            ->groupByRaw($dateExpr)
            ->pluck('value', 'date');

        return array_map(fn (string $date) => [
            'date' => $date,
            'value' => (int) ($rows[$date] ?? 0),
        ], $dates);
    }

    /**
     * Datas Y-m-d (fuso SP) da janela, inclusivas — mesma semântica do
     * windowDates do ReportsAnalyticsService (base do zero-fill).
     *
     * @param  CarbonImmutable  $from  Início da janela (já em SP, startOfDay).
     * @param  CarbonImmutable  $to  Fim da janela (agora, em SP).
     * @return array<int, string>
     */
    private function windowDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cursor = $from;
        $end = $to->startOfDay();
        $dates = [];

        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Par atual/anterior com variação percentual (null sem baseline — mesma
     * convenção do variationPct do ReportsAnalyticsService).
     *
     * @param  int  $current  Contagem da janela atual.
     * @param  int  $previous  Contagem da janela anterior de mesma duração.
     * @return array{current: int, previous: int, variation_pct: float|null}
     */
    private function periodComparison(int $current, int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'variation_pct' => $previous === 0
                ? null
                : round((($current - $previous) * 100) / $previous, 1),
        ];
    }

    /** Mapa sort público → expressão de ordenação (whitelist; o controller já validou). */
    private const USER_SORTS = [
        'created_at' => 'users.created_at',
        'last_login_at' => 'users.last_login_at',
        'name' => 'users.name',
        'links' => 'links_count',
        'clicks' => 'total_clicks',
    ];

    /** {@inheritDoc} */
    public function getUsers(int $page, int $perPage, ?string $q, string $sort, string $order): array
    {
        $query = User::query()
            ->whereNotIn('users.id', User::DEMO_ACCOUNT_IDS)
            // LEFT JOIN condicionado: usuários sem link contam 0, links demo
            // nunca contam. SUM(links.clicks) = contador denormalizado (fonte
            // única desta listagem — nunca misturar com COUNT(clicks)).
            ->leftJoin('links', function ($join) {
                $join->on('links.user_id', '=', 'users.id')
                    ->where('links.is_demo', false);
            })
            ->groupBy('users.id', 'users.name', 'users.email', 'users.created_at', 'users.last_login_at')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at', 'users.last_login_at')
            ->selectRaw('COUNT(links.id) as links_count, COALESCE(SUM(links.clicks), 0) as total_clicks');

        if (filled($q)) {
            $needle = '%'.mb_strtolower($q).'%';
            // LOWER + LIKE: case-insensitive nos dois drivers (ILIKE é só pgsql).
            $query->where(function ($w) use ($needle) {
                $w->whereRaw('LOWER(users.name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(users.email) LIKE ?', [$needle]);
            });
        }

        $paginator = $query
            ->orderBy(self::USER_SORTS[$sort], $order)
            ->orderBy('users.id') // desempate estável entre páginas
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($paginator->items())->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'created_at' => $u->created_at?->toISOString(),
                'last_login_at' => $u->last_login_at?->toISOString(),
                'links_count' => (int) $u->links_count,
                'total_clicks' => (int) $u->total_clicks,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /** {@inheritDoc} */
    public function getEngagement(int $days): array
    {
        $nonDemoUsers = User::whereNotIn('id', User::DEMO_ACCOUNT_IDS);

        $totalUsers = (clone $nonDemoUsers)->count();

        // Links não-demo por usuário — base da ativação e da distribuição.
        // Volume baixo (users é tabela pequena): agregar em PHP é mais simples
        // e cross-driver que CASE em SQL.
        //
        // whereNotNull obrigatório: nonDemo() inclui links anônimos (sem dono,
        // do encurtador público) porque eles CONTAM nos totais globais — mas
        // aqui o agrupamento é por usuário, e o grupo `user_id = null` viraria
        // um bucket fantasma inflando ativação e distribuição.
        $linksPerUser = Link::nonDemo()
            ->whereNotNull('links.user_id')
            ->selectRaw('links.user_id, COUNT(*) as total')
            ->groupBy('links.user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($c) => (int) $c);

        $activated = $linksPerUser->count();

        // Retorno na 1ª semana (proxy por criação de link): entre os usuários
        // cadastrados na janela, % que criou o primeiro link não-demo em até
        // 7 dias após o cadastro. Datas comparadas em PHP (cross-driver).
        $cohort = (clone $nonDemoUsers)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays($days))
            ->get(['id', 'created_at']);

        // O whereIn abaixo já mantém a query user-scoped (NULL IN (...) nunca
        // casa), então links anônimos ficam de fora da cohort por construção.
        $firstLinkAt = Link::nonDemo()
            ->whereIn('links.user_id', $cohort->pluck('id'))
            ->selectRaw('links.user_id, MIN(links.created_at) as first_link_at')
            ->groupBy('links.user_id')
            ->pluck('first_link_at', 'user_id');

        $returned = $cohort->filter(function ($user) use ($firstLinkAt) {
            $first = $firstLinkAt[$user->id] ?? null;

            return $first !== null
                && CarbonImmutable::parse($first)->lessThanOrEqualTo($user->created_at->addDays(7));
        })->count();

        // Distribuição de links por usuário (0 / 1 / 2–5 / 6+).
        $distribution = ['0' => 0, '1' => 0, '2-5' => 0, '6+' => 0];
        $distribution['0'] = max(0, $totalUsers - $linksPerUser->count());
        foreach ($linksPerUser as $count) {
            $bucket = match (true) {
                $count === 1 => '1',
                $count <= 5 => '2-5',
                default => '6+',
            };
            $distribution[$bucket]++;
        }

        // min() é query builder puro: devolve a string crua do banco
        // ('Y-m-d H:i:s' em UTC) sem passar pelo cast datetime do model, e o
        // cliente parseia isso como hora LOCAL. Normalizado para ISO-8601 com
        // offset, igual ao resto dos timestamps da API (ver getUsers).
        $oldestLogin = (clone $nonDemoUsers)->whereNotNull('last_login_at')->min('last_login_at');

        return [
            'activation_pct' => $totalUsers === 0 ? null : round($activated * 100 / $totalUsers, 1),
            'week1_return_pct' => $cohort->isEmpty() ? null : round($returned * 100 / $cohort->count(), 1),
            'links_distribution' => collect($distribution)
                ->map(fn ($users, $bucket) => ['bucket' => (string) $bucket, 'users' => $users])
                ->values()
                ->all(),
            'wau' => (clone $nonDemoUsers)->where('last_login_at', '>=', CarbonImmutable::now()->subDays(7))->count(),
            'mau' => (clone $nonDemoUsers)->where('last_login_at', '>=', CarbonImmutable::now()->subDays(30))->count(),
            // Disclaimer do frontend: WAU/MAU só contam desde este instante.
            // Exclui contas demo (mesmo predicado do resto do método) —
            // senão um login de QA antecipa a data exibida ao dono do produto.
            'login_tracking_since' => $oldestLogin ? CarbonImmutable::parse($oldestLogin)->toISOString() : null,
        ];
    }

    /** {@inheritDoc} */
    public function getHealth(): array
    {
        // Fila via driver configurado (Redis em prod). try/catch: o painel
        // degrada para null se o Redis não responder — nunca 500.
        try {
            $queueDepth = Queue::size();
        } catch (\Throwable) {
            $queueDepth = null;
        }

        $links = [
            'active' => Link::nonDemo()->where('links.is_active', true)->count(),
            'inactive' => Link::nonDemo()->where('links.is_active', false)->count(),
            // 'error' é o valor gravado pelo LinkHealthCheckJob em falha.
            'broken' => Link::nonDemo()->where('links.health_status', 'error')->count(),
        ];

        $tierRows = Link::nonDemo()
            ->join('clicks', 'clicks.link_id', '=', 'links.id')
            ->where('clicks.created_at', '>=', CarbonImmutable::now()->subDays(7))
            ->whereNotNull('clicks.quality_tier')
            ->selectRaw('clicks.quality_tier as tier, COUNT(*) as clicks')
            ->groupBy('clicks.quality_tier')
            ->orderByDesc('clicks')
            ->get();

        $tierTotal = max(1, $tierRows->sum('clicks'));

        return [
            'queue_depth' => $queueDepth,
            'failed_jobs_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            'failed_jobs_7d' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count(),
            'links' => $links,
            'quality_tiers_7d' => $tierRows->map(fn ($r) => [
                'tier' => $r->tier,
                'clicks' => (int) $r->clicks,
                'pct' => round($r->clicks * 100 / $tierTotal, 1),
            ])->all(),
        ];
    }
}
