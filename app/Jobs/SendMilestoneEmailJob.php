<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\Link;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Envia o e-mail de UM degrau da escada de marcos de cliques
 * ({@see DispatchMilestoneEmailsJob::THRESHOLDS}) de UM link — enfileirado pelo
 * {@see DispatchMilestoneEmailsJob} com o par (link, degrau).
 *
 * Ordem do handle(): guards (link existe / cliques ainda cobrem o degrau / dono
 * existe e elegível) → claim atômico → envio → outcome. Os guards rodam ANTES
 * do claim para que um estado transitório (opt-out no meio do caminho) não
 * queime a única chance de comemorar o degrau.
 *
 * Delivery é AT MOST ONCE, não at least once — o mesmo trade-off do
 * {@see SendWeeklyDigestEmailJob}: `links.milestone_last_threshold` é
 * reivindicado num UPDATE condicional único (`milestone_last_threshold < :t`)
 * ANTES do envio, então um retry (tries = 3) após um envio bem-sucedido não
 * duplica o e-mail. Um degrau MAIOR ainda pode ser reivindicado depois — é o
 * que faz a escada avançar. Uma falha real do provedor depois do claim perde
 * este degrau para sempre (o job se marca failed, sem retry — retry não
 * enviaria: o claim já foi tomado).
 *
 * Todos os caminhos de saída registram `outcome` no canal `jobs` (constantes
 * OUTCOME_*), como no welcome e no digest.
 */
class SendMilestoneEmailJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    /** O e-mail de marco foi enviado via provedor transacional. */
    public const OUTCOME_SENT = 'sent';

    /** No-op: o link não existe mais (apagado entre dispatch e execução). */
    public const OUTCOME_SKIPPED_LINK_MISSING = 'skipped_link_missing';

    /** No-op: o link não cobre mais o degrau (contador corrigido/zerado). */
    public const OUTCOME_SKIPPED_BELOW_THRESHOLD = 'skipped_below_threshold';

    /** No-op: o dono sumiu, optou por sair ou não está verificado. */
    public const OUTCOME_SKIPPED_OWNER_INELIGIBLE = 'skipped_owner_ineligible';

    /** No-op: outro dispatch ou retry já reivindicou este degrau (ou um maior). */
    public const OUTCOME_SKIPPED_ALREADY_CLAIMED = 'skipped_already_claimed';

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    /**
     * @param  int  $linkId  Link que cruzou um degrau da escada de marcos.
     * @param  int  $threshold  Degrau comemorado por este envio (ver DispatchMilestoneEmailsJob::THRESHOLDS).
     */
    public function __construct(public readonly int $linkId, public readonly int $threshold) {}

    /**
     * Guards → claim → envio. Sai quieto em todos os skips; falha do provedor
     * marca o job como failed sem retry (ver docblock da classe).
     */
    public function handle(EmailService $emailService): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, [
            'link_id' => $this->linkId,
            'threshold' => $this->threshold,
        ]);

        try {
            $link = Link::find($this->linkId);

            if (! $link) {
                $this->succeedWith(self::OUTCOME_SKIPPED_LINK_MISSING, $start);

                return;
            }

            if ($link->clicks < $this->threshold) {
                $this->succeedWith(self::OUTCOME_SKIPPED_BELOW_THRESHOLD, $start);

                return;
            }

            $user = $link->user;

            if (! $user || ! $user->isEligibleForRetentionEmails()) {
                $this->succeedWith(self::OUTCOME_SKIPPED_OWNER_INELIGIBLE, $start);

                return;
            }

            // Claim atômico: sobe o degrau comemorado num único statement, só se
            // o link ainda estiver num degrau menor. O racer perdedor (dispatch
            // concorrente ou retry) vê 0 linhas e sai quieto.
            $claimed = Link::whereKey($this->linkId)
                ->where('milestone_last_threshold', '<', $this->threshold)
                ->update(['milestone_last_threshold' => $this->threshold]);

            if ($claimed === 0) {
                $this->succeedWith(self::OUTCOME_SKIPPED_ALREADY_CLAIMED, $start);

                return;
            }

            // O primeiro degrau é uma estreia ("os 10 primeiros cliques"), não um
            // "passou de" — celebrar a chegada soa melhor que o transbordo.
            $isFirstMilestone = $this->threshold === DispatchMilestoneEmailsJob::THRESHOLDS[0];

            $data = [
                'subject' => $isFirstMilestone
                    ? 'Seus 10 primeiros cliques no Link Charts 🎉'
                    : "Seu link passou de {$this->threshold} cliques 🎉",
                'milestone_line' => $isFirstMilestone
                    ? 'Seu link chegou aos 10 primeiros cliques'
                    : "Um dos seus links passou de {$this->threshold} cliques",
                'user_name' => $user->name,
                'link_label' => $link->title ?: $link->slug,
                'clicks' => $link->clicks,
                'clicks_label' => $link->clicks === 1 ? '1 clique' : $link->clicks.' cliques',
                'stats_url' => rtrim(config('app.frontend_url', config('app.url')), '/')
                    .'/links/analytics/'.$link->id.'?utm_source=milestone-email&utm_medium=email',
                'unsubscribe_url' => URL::signedRoute('digest.unsubscribe', ['user' => $user->id]),
            ];

            $result = $emailService->sendTransactionalEmail(
                $user->email,
                $data['subject'],
                view('emails.milestone', $data)->render(),
                view('emails.milestone-text', $data)->render(),
                $user->name,
                'milestone',
            );

            // sendTransactionalEmail() nunca lança — devolve ['success' => false, ...].
            // Não inspecionar seria perder o e-mail em silêncio logando sucesso.
            if (! ($result['success'] ?? false)) {
                $e = new RuntimeException(
                    "O provedor de e-mail recusou o marco de {$this->threshold} cliques: ".($result['error'] ?? 'motivo desconhecido')
                );

                AppLogger::jobFailed(static::class, $e, $this->attempts());
                $this->fail($e);

                return;
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'outcome' => self::OUTCOME_SENT,
                'link_id' => $link->id,
                'threshold' => $this->threshold,
                'clicks' => $link->clicks,
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
     * Registra um exit path não-enviado como job bem-sucedido com `outcome`.
     */
    private function succeedWith(string $outcome, float $start): void
    {
        AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
            'outcome' => $outcome,
            'link_id' => $this->linkId,
            'threshold' => $this->threshold,
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
        return null;
    }
}
