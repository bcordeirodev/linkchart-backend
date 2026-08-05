<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\Link;
use App\Models\User;
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
 * Envia o winback de UM usuário — um e-mail listando todos os links que
 * completaram duas semanas sem cliques (enfileirado pelo
 * {@see DispatchWinbackEmailsJob}).
 *
 * Ordem do handle(): guards (usuário existe e elegível) → claim atômico nos
 * links do payload → recarga dos links efetivamente reivindicados → envio →
 * outcome.
 *
 * Delivery é AT MOST ONCE, não at least once — mesmo trade-off do
 * {@see SendWeeklyDigestEmailJob}. O claim é um UPDATE condicional em lote
 * (`WHERE id IN (payload) AND winback_email_sent_at IS NULL`) executado ANTES
 * do envio: um retry (tries = 3) depois de um envio bem-sucedido encontra 0
 * linhas e sai quieto. Se NENHUMA linha for reivindicada, outra execução já
 * cobriu esses links e não há e-mail a mandar.
 *
 * Os links do e-mail são recarregados pelo timestamp do próprio claim, então
 * um link que outra execução reivindicou no meio do caminho não aparece na
 * lista — o usuário só lê o que este envio de fato cobriu.
 *
 * Todos os caminhos de saída registram `outcome` no canal `jobs` (constantes
 * OUTCOME_*).
 */
class SendWinbackEmailJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    /** O winback foi enviado via provedor transacional. */
    public const OUTCOME_SENT = 'sent';

    /** No-op: o usuário não existe mais (apagado entre dispatch e execução). */
    public const OUTCOME_SKIPPED_USER_MISSING = 'skipped_user_missing';

    /** No-op: o usuário optou por sair, não está verificado ou é conta demo. */
    public const OUTCOME_SKIPPED_INELIGIBLE = 'skipped_ineligible';

    /** No-op: outra execução já reivindicou todos os links do payload. */
    public const OUTCOME_SKIPPED_ALREADY_CLAIMED = 'skipped_already_claimed';

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    /**
     * @param  int  $userId  Usuário destinatário.
     * @param  list<int>  $linkIds  Links órfãos da leva, já filtrados pelo dispatcher.
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $linkIds,
    ) {}

    /**
     * Guards → claim → envio. Sai quieto em todos os skips; falha do provedor
     * marca o job como failed sem retry (ver docblock da classe).
     */
    public function handle(EmailService $emailService): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, [
            'user_id' => $this->userId,
            'links' => count($this->linkIds),
        ]);

        try {
            $user = User::find($this->userId);

            if (! $user) {
                $this->succeedWith(self::OUTCOME_SKIPPED_USER_MISSING, $start);

                return;
            }

            if (! $user->isEligibleForRetentionEmails()) {
                $this->succeedWith(self::OUTCOME_SKIPPED_INELIGIBLE, $start);

                return;
            }

            // Claim atômico em lote. O timestamp é capturado antes para servir
            // de etiqueta desta execução na recarga logo abaixo — só os links
            // que ESTE job reivindicou entram no e-mail.
            $claimedAt = now();

            $claimed = Link::whereIn('id', $this->linkIds)
                ->whereNull('winback_email_sent_at')
                ->update(['winback_email_sent_at' => $claimedAt]);

            if ($claimed === 0) {
                $this->succeedWith(self::OUTCOME_SKIPPED_ALREADY_CLAIMED, $start);

                return;
            }

            $links = Link::whereIn('id', $this->linkIds)
                ->where('winback_email_sent_at', $claimedAt)
                ->orderBy('id')
                ->get();

            $data = [
                'subject' => 'Seu link ainda não teve cliques — 3 jeitos de divulgar',
                'user_name' => $user->name,
                'link_labels' => $links->map(fn (Link $link) => $link->title ?: $link->slug)->all(),
                'links_url' => rtrim(config('app.frontend_url', config('app.url')), '/')
                    .'/links?utm_source=winback-email&utm_medium=email',
                'unsubscribe_url' => URL::signedRoute('digest.unsubscribe', ['user' => $user->id]),
            ];

            $result = $emailService->sendTransactionalEmail(
                $user->email,
                $data['subject'],
                view('emails.winback', $data)->render(),
                view('emails.winback-text', $data)->render(),
                $user->name,
                'winback',
            );

            // sendTransactionalEmail() nunca lança — devolve ['success' => false, ...].
            // Não inspecionar seria perder o e-mail em silêncio logando sucesso.
            if (! ($result['success'] ?? false)) {
                $e = new RuntimeException(
                    'O provedor de e-mail recusou o winback: '.($result['error'] ?? 'motivo desconhecido')
                );

                AppLogger::jobFailed(static::class, $e, $this->attempts());
                $this->fail($e);

                return;
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'outcome' => self::OUTCOME_SENT,
                'links' => $links->count(),
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
