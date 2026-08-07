<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\User;
use App\Services\EmailService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Envia o digest semanal de cliques de UM usuário (enfileirado pelo
 * {@see DispatchWeeklyDigestsJob}).
 *
 * Ordem do handle(): guards (usuário existe / opt-in / verificado) → stats →
 * se 0 cliques na janela, sai SEM claim (nada a enviar; o claim fica livre
 * para um dispatch com dados) → claim atômico → envio → outcome.
 *
 * Delivery é AT MOST ONCE, não at least once — o mesmo trade-off documentado
 * em {@see SendWelcomeEmailJob}: `weekly_digest_sent_at` é reivindicado num
 * UPDATE condicional único ANTES do envio, então um retry (tries = 3) após um
 * envio bem-sucedido não duplica o e-mail. Uma falha real do provedor depois
 * do claim perde o digest desta semana (o job se marca failed, sem retry —
 * retry não enviaria: o claim já foi tomado); o da semana seguinte chega
 * normalmente.
 *
 * A janela viaja no payload (UTC, ISO-8601) — um retry ou atraso de fila
 * relata a MESMA semana calculada no disparo, nunca a corrente. Todos os
 * caminhos de saída registram `outcome` no canal `jobs` (constantes
 * OUTCOME_*), como no welcome.
 */
class SendWeeklyDigestEmailJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    /** O digest foi enviado via provedor transacional. */
    public const OUTCOME_SENT = 'sent';

    /** No-op: o usuário não existe mais (apagado entre dispatch e execução). */
    public const OUTCOME_SKIPPED_USER_MISSING = 'skipped_user_missing';

    /** No-op: o usuário optou por sair (weekly_digest_enabled = false). */
    public const OUTCOME_SKIPPED_DISABLED = 'skipped_disabled';

    /** No-op: o e-mail do usuário não está verificado. */
    public const OUTCOME_SKIPPED_UNVERIFIED = 'skipped_unverified';

    /** No-op: nenhum clique real na janela — nada a relatar (claim não é queimado). */
    public const OUTCOME_SKIPPED_NO_CLICKS = 'skipped_no_clicks';

    /** No-op: outro dispatch ou retry já reivindicou o envio desta semana. */
    public const OUTCOME_SKIPPED_ALREADY_CLAIMED = 'skipped_already_claimed';

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    /**
     * @param  int  $userId  Usuário destinatário.
     * @param  string  $windowStartIso  Início da janela relatada (UTC, ISO-8601, inclusivo).
     * @param  string  $windowEndIso  Fim da janela relatada (UTC, ISO-8601, exclusivo).
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $windowStartIso,
        public readonly string $windowEndIso,
    ) {}

    /**
     * Guards → stats → claim → envio. Sai quieto em todos os skips; falha do
     * provedor marca o job como failed sem retry (ver docblock da classe).
     */
    public function handle(EmailService $emailService): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, [
            'user_id' => $this->userId,
            'window_start' => $this->windowStartIso,
            'window_end' => $this->windowEndIso,
        ]);

        try {
            $user = User::find($this->userId);

            if (! $user) {
                $this->succeedWith(self::OUTCOME_SKIPPED_USER_MISSING, $start);

                return;
            }

            if (! $user->weekly_digest_enabled) {
                $this->succeedWith(self::OUTCOME_SKIPPED_DISABLED, $start);

                return;
            }

            if (! $user->hasVerifiedEmail()) {
                $this->succeedWith(self::OUTCOME_SKIPPED_UNVERIFIED, $start);

                return;
            }

            $windowStart = CarbonImmutable::parse($this->windowStartIso);
            $windowEnd = CarbonImmutable::parse($this->windowEndIso);

            $stats = $this->computeStats($user->id, $windowStart, $windowEnd);

            if ($stats['total'] === 0) {
                $this->succeedWith(self::OUTCOME_SKIPPED_NO_CLICKS, $start);

                return;
            }

            // Claim atômico: só passa se ainda não houve envio DESTA semana
            // (sent_at nulo ou anterior ao fim da janela relatada). O racer
            // perdedor vê 0 linhas afetadas e sai quieto.
            $claimed = User::whereKey($this->userId)
                ->where('weekly_digest_enabled', true)
                ->where(function ($query) use ($windowEnd) {
                    $query->whereNull('weekly_digest_sent_at')
                        ->orWhere('weekly_digest_sent_at', '<', $windowEnd);
                })
                ->update(['weekly_digest_sent_at' => now()]);

            if ($claimed === 0) {
                $this->succeedWith(self::OUTCOME_SKIPPED_ALREADY_CLAIMED, $start);

                return;
            }

            $data = $this->buildViewData($user, $stats, $windowStart, $windowEnd);

            $result = $emailService->sendTransactionalEmail(
                $user->email,
                $data['subject'],
                view('emails.weekly-digest', $data)->render(),
                view('emails.weekly-digest-text', $data)->render(),
                $user->name,
                'weekly_digest',
            );

            // sendTransactionalEmail() nunca lança — devolve ['success' => false, ...].
            // Não inspecionar seria perder o e-mail em silêncio logando sucesso.
            if (! ($result['success'] ?? false)) {
                $e = new RuntimeException(
                    'O provedor de e-mail recusou o digest semanal: '.($result['error'] ?? 'motivo desconhecido')
                );

                AppLogger::jobFailed(static::class, $e, $this->attempts());
                $this->fail($e);

                return;
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'outcome' => self::OUTCOME_SENT,
                'total_clicks' => $stats['total'],
                'previous_clicks' => $stats['previous'],
            ]);
        } catch (Throwable $e) {
            AppLogger::jobFailed(static::class, $e, $this->attempts());
            throw $e;
        } finally {
            $this->popLogContext();
        }
    }

    /**
     * Callback final de falha (após esgotar retries ou fail() explícito).
     */
    public function failed(Throwable $e): void
    {
        AppLogger::jobFailed(static::class, $e, $this->tries);
    }

    /**
     * Piso de cliques na janela para os "fatos" (estados, mobile, pico) irem
     * ao e-mail. Abaixo disso a moda de `day_of_week`/`hour_of_day` é acaso
     * apresentado como insight — o digest volta ao formato enxuto (número +
     * variação + top link).
     */
    private const FACTS_MIN_CLICKS = 5;

    /**
     * Conta os cliques do usuário na janela e na janela anterior, resolve o
     * link mais clicado da semana e — quando há volume suficiente — os três
     * fatos de contexto do e-mail. Só links não-demo entram, regra global de
     * métricas de cliques do produto.
     *
     * `states`, `top_state` e `peak` saem de colunas pré-computadas de
     * `clicks`. Vale notar que `hour_of_day`/`day_of_week` são gravados no
     * fuso do VISITANTE (ver `LinkTrackingService::enrichTemporal`), não em
     * UTC nem no fuso do dono do link — é o que dá sentido ao "pico terça
     * 14h": 14h para quem clicou.
     *
     * @return array{total: int, previous: int, top: object|null, states: int,
     *     top_state: string|null, mobile_pct: int|null, peak: object|null}
     *     `top` tem id, title, slug, lifetime_clicks e clicks_count;
     *     `peak` tem day_of_week (ISO 1-7) e hour_of_day.
     */
    private function computeStats(int $userId, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $clicksBetween = fn (CarbonImmutable $from, CarbonImmutable $to) => DB::table('clicks')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->where('links.user_id', $userId)
            ->where('links.is_demo', false)
            ->where('clicks.created_at', '>=', $from)
            ->where('clicks.created_at', '<', $to);

        $total = $clicksBetween($windowStart, $windowEnd)->count();

        $stats = [
            'total' => $total,
            'previous' => $clicksBetween($windowStart->subWeek(), $windowStart)->count(),
            'top' => $clicksBetween($windowStart, $windowEnd)
                ->select(
                    'links.id',
                    'links.title',
                    'links.slug',
                    'links.clicks as lifetime_clicks',
                    DB::raw('COUNT(*) as clicks_count')
                )
                ->groupBy('links.id', 'links.title', 'links.slug', 'links.clicks')
                ->orderByDesc('clicks_count')
                ->first(),
            'states' => 0,
            'top_state' => null,
            'mobile_pct' => null,
            'peak' => null,
        ];

        if ($total < self::FACTS_MIN_CLICKS) {
            return $stats;
        }

        // Um único round-trip para os dois agregados escalares. COUNT(DISTINCT)
        // ignora NULL, então cliques sem geo simplesmente não contam estado.
        $breakdown = $clicksBetween($windowStart, $windowEnd)
            ->selectRaw('COUNT(DISTINCT clicks.state) as states_count')
            ->selectRaw('SUM(CASE WHEN clicks.is_mobile THEN 1 ELSE 0 END) as mobile_count')
            ->first();

        $stats['states'] = (int) ($breakdown->states_count ?? 0);
        $stats['mobile_pct'] = (int) round(((int) ($breakdown->mobile_count ?? 0)) / $total * 100);

        if ($stats['states'] === 1) {
            $stats['top_state'] = $clicksBetween($windowStart, $windowEnd)
                ->whereNotNull('clicks.state_name')
                ->value('clicks.state_name');
        }

        $stats['peak'] = $clicksBetween($windowStart, $windowEnd)
            ->whereNotNull('clicks.day_of_week')
            ->whereNotNull('clicks.hour_of_day')
            ->select('clicks.day_of_week', 'clicks.hour_of_day', DB::raw('COUNT(*) as peak_count'))
            ->groupBy('clicks.day_of_week', 'clicks.hour_of_day')
            ->orderByDesc('peak_count')
            ->orderBy('clicks.day_of_week')
            ->orderBy('clicks.hour_of_day')
            ->first();

        return $stats;
    }

    /** Nomes dos dias da semana indexados por ISO-8601 (1 = segunda). */
    private const WEEKDAY_NAMES = [
        1 => 'segunda',
        2 => 'terça',
        3 => 'quarta',
        4 => 'quinta',
        5 => 'sexta',
        6 => 'sábado',
        7 => 'domingo',
    ];

    /**
     * Monta o payload dos templates emails.weekly-digest{,-text}: assunto com
     * o número-âncora, variação (ou primeira semana), top link, faixa de fatos
     * de contexto, os dois CTAs com UTM (`weekly-digest`/`email` — é o que
     * fecha o loop de retenção nos logs) e o link assinado de unsubscribe
     * (LGPD).
     *
     * Os dois CTAs são deliberadamente diferentes em destino e promessa. O
     * primário aponta para a página PÚBLICA do top link, que abre sem login —
     * é o caminho de quem sumiu há semanas e cuja sessão expirou. Ele mostra o
     * histórico completo, não a semana, e por isso a copy promete "histórico":
     * um botão dizendo "ver estatísticas" faria o número maior parecer erro. O
     * secundário leva ao painel autenticado com `period=7d`, onde os números
     * casam exatamente com os do e-mail.
     *
     * @param  array{total: int, previous: int, top: object|null, states: int,
     *     top_state: string|null, mobile_pct: int|null, peak: object|null}  $stats
     * @return array<string, mixed>
     */
    private function buildViewData(User $user, array $stats, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $clicksLabel = $stats['total'] === 1 ? '1 clique' : $stats['total'].' cliques';

        $variationLabel = null;
        if ($stats['previous'] > 0) {
            $percent = (int) round((($stats['total'] - $stats['previous']) / $stats['previous']) * 100);
            $variationLabel = sprintf('%+d%%', $percent);
        }

        $saoPaulo = 'America/Sao_Paulo';
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
        $utm = 'utm_source=weekly-digest&utm_medium=email';
        $top = $stats['top'];

        return [
            'subject' => "Seus links tiveram {$clicksLabel} na última semana 📈",
            'user_name' => $user->name,
            'total' => $stats['total'],
            'clicks_label' => $clicksLabel,
            'first_week' => $stats['previous'] === 0,
            'variation_label' => $variationLabel,
            'top_link_label' => $top ? ($top->title ?: $top->slug) : null,
            'top_link_clicks' => $top->clicks_count ?? 0,
            'top_link_lifetime' => (int) ($top->lifetime_clicks ?? 0),
            'facts' => $this->buildFacts($stats),
            'period_label' => $windowStart->setTimezone($saoPaulo)->format('d/m')
                .' – '.$windowEnd->setTimezone($saoPaulo)->subDay()->format('d/m'),
            'public_url' => $top
                ? $frontend.'/public-analytics/'.rawurlencode($top->slug).'?'.$utm
                : null,
            'dashboard_url' => $top
                ? $frontend.'/links/analytics/'.$top->id.'?period=7d&'.$utm
                : $frontend.'/links?'.$utm,
            'unsubscribe_url' => URL::signedRoute('digest.unsubscribe', ['user' => $user->id]),
        ];
    }

    /**
     * Traduz os agregados de contexto na faixa de fatos curtos do e-mail.
     *
     * Devolve lista vazia quando o volume não atingiu {@see self::FACTS_MIN_CLICKS}
     * (os agregados nem chegam a ser calculados nesse caso) — o template usa
     * isso para omitir a faixa inteira. Cada fato é omitido individualmente se
     * o dado que o sustenta não existir: cliques sem geo não viram "estados",
     * cliques sem enriquecimento temporal não viram "pico".
     *
     * @param  array{total: int, states: int, top_state: string|null,
     *     mobile_pct: int|null, peak: object|null}  $stats
     * @return list<array{icon: string, label: string}>
     */
    private function buildFacts(array $stats): array
    {
        if ($stats['total'] < self::FACTS_MIN_CLICKS) {
            return [];
        }

        $facts = [];

        if ($stats['states'] > 1) {
            $facts[] = ['icon' => '📍', 'label' => $stats['states'].' estados'];
        } elseif ($stats['top_state'] !== null) {
            $facts[] = ['icon' => '📍', 'label' => $stats['top_state']];
        }

        if ($stats['mobile_pct'] !== null) {
            $facts[] = ['icon' => '📱', 'label' => $stats['mobile_pct'].'% no celular'];
        }

        if ($stats['peak'] !== null) {
            $weekday = self::WEEKDAY_NAMES[(int) $stats['peak']->day_of_week] ?? null;
            if ($weekday !== null) {
                $facts[] = [
                    'icon' => '🕐',
                    'label' => 'pico '.$weekday.' '.((int) $stats['peak']->hour_of_day).'h',
                ];
            }
        }

        return $facts;
    }

    /**
     * Registra um exit path não-enviado como job bem-sucedido com `outcome`.
     */
    private function succeedWith(string $outcome, float $start): void
    {
        AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
            'outcome' => $outcome,
        ]);
    }

    /** {@inheritDoc} */
    protected function logContextRequestId(): ?string
    {
        return null;
    }

    /** {@inheritDoc} */
    protected function logContextUserId(): ?int
    {
        return $this->userId;
    }
}
