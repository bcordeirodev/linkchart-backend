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
     * Conta os cliques do usuário na janela e na janela anterior, e resolve o
     * link mais clicado da semana. Só links não-demo entram — regra global de
     * métricas de cliques do produto.
     *
     * @return array{total: int, previous: int, top: object|null} `top` tem title, slug e clicks_count.
     */
    private function computeStats(int $userId, CarbonImmutable $windowStart, CarbonImmutable $windowEnd): array
    {
        $clicksBetween = fn (CarbonImmutable $from, CarbonImmutable $to) => DB::table('clicks')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->where('links.user_id', $userId)
            ->where('links.is_demo', false)
            ->where('clicks.created_at', '>=', $from)
            ->where('clicks.created_at', '<', $to);

        return [
            'total' => $clicksBetween($windowStart, $windowEnd)->count(),
            'previous' => $clicksBetween($windowStart->subWeek(), $windowStart)->count(),
            'top' => $clicksBetween($windowStart, $windowEnd)
                ->select('links.title', 'links.slug', DB::raw('COUNT(*) as clicks_count'))
                ->groupBy('links.id', 'links.title', 'links.slug')
                ->orderByDesc('clicks_count')
                ->first(),
        ];
    }

    /**
     * Monta o payload dos templates emails.weekly-digest{,-text}: assunto com
     * o número-âncora, variação (ou primeira semana), top link, CTA com UTM
     * (`weekly-digest`/`email` — é o que fecha o loop de retenção nos logs) e
     * o link assinado de unsubscribe (LGPD).
     *
     * @param  array{total: int, previous: int, top: object|null}  $stats
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

        return [
            'subject' => "Seus links tiveram {$clicksLabel} na última semana 📈",
            'user_name' => $user->name,
            'total' => $stats['total'],
            'clicks_label' => $clicksLabel,
            'first_week' => $stats['previous'] === 0,
            'variation_label' => $variationLabel,
            'top_link_label' => $stats['top'] ? ($stats['top']->title ?: $stats['top']->slug) : null,
            'top_link_clicks' => $stats['top']->clicks_count ?? 0,
            'period_label' => $windowStart->setTimezone($saoPaulo)->format('d/m')
                .' – '.$windowEnd->setTimezone($saoPaulo)->subDay()->format('d/m'),
            'stats_url' => rtrim(config('app.frontend_url', config('app.url')), '/')
                .'/links?utm_source=weekly-digest&utm_medium=email',
            'unsubscribe_url' => URL::signedRoute('digest.unsubscribe', ['user' => $user->id]),
        ];
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
