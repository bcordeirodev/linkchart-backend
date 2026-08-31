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
 * Envia o nudge de ativação para UM usuário do dia 1–2 que ainda não criou
 * nenhum link (enfileirado pelo {@see DispatchActivationNudgesJob}).
 *
 * Ordem do handle(): guards (usuário existe → elegível → AINDA sem link
 * não-demo) → claim atômico → envio → outcome.
 *
 * O guard de "ainda sem link" é reavaliado aqui de propósito: se a pessoa
 * encurtou o primeiro link entre o disparo e a execução, ela se ativou sozinha
 * e o e-mail viraria ruído — o job sai quieto SEM queimar o claim.
 *
 * Delivery é AT MOST ONCE, não at least once — mesmo trade-off do
 * {@see SendOnboardingTipsEmailJob}: `users.activation_nudge_sent_at` é
 * reivindicado num UPDATE condicional único ANTES do envio, então um retry
 * (tries = 3) após um envio bem-sucedido não duplica o e-mail. Uma falha real
 * do provedor depois do claim perde este nudge para sempre — é o preço de nunca
 * cobrar duas vezes o mesmo primeiro link.
 *
 * Todos os caminhos de saída registram `outcome` no canal `jobs` (constantes
 * OUTCOME_*).
 */
class SendActivationNudgeEmailJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    /** O nudge foi enviado via provedor transacional. */
    public const OUTCOME_SENT = 'sent';

    /** No-op: o usuário não existe mais (apagado entre dispatch e execução). */
    public const OUTCOME_SKIPPED_USER_MISSING = 'skipped_user_missing';

    /** No-op: o usuário optou por sair, não está verificado ou é conta demo. */
    public const OUTCOME_SKIPPED_INELIGIBLE = 'skipped_ineligible';

    /** No-op: o usuário criou o primeiro link entre o disparo e a execução. */
    public const OUTCOME_SKIPPED_ACTIVATED = 'skipped_activated';

    /** No-op: outro dispatch ou retry já reivindicou o envio. */
    public const OUTCOME_SKIPPED_ALREADY_CLAIMED = 'skipped_already_claimed';

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    /**
     * @param  int  $userId  Usuário destinatário.
     */
    public function __construct(public readonly int $userId) {}

    /**
     * Guards → claim → envio. Sai quieto em todos os skips; falha do provedor
     * marca o job como failed sem retry (ver docblock da classe).
     */
    public function handle(EmailService $emailService): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, ['user_id' => $this->userId]);

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

            // O link demo semeado no cadastro não conta: ativação é link que a
            // própria pessoa encurtou.
            $hasOwnLink = Link::where('user_id', $user->id)
                ->where('is_demo', false)
                ->exists();

            if ($hasOwnLink) {
                $this->succeedWith(self::OUTCOME_SKIPPED_ACTIVATED, $start);

                return;
            }

            // Claim atômico: flipa NULL → now() num único statement. O racer
            // perdedor (dispatch concorrente ou retry) vê 0 linhas e sai quieto.
            $claimed = User::whereKey($this->userId)
                ->whereNull('activation_nudge_sent_at')
                ->update(['activation_nudge_sent_at' => now()]);

            if ($claimed === 0) {
                $this->succeedWith(self::OUTCOME_SKIPPED_ALREADY_CLAIMED, $start);

                return;
            }

            $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

            $data = [
                'subject' => 'Seu primeiro link encurtado leva 10 segundos',
                'user_name' => $user->name,
                'create_url' => $frontendUrl.'/links?utm_source=activation-nudge&utm_medium=email',
                'unsubscribe_url' => URL::signedRoute('digest.unsubscribe', ['user' => $user->id]),
            ];

            $result = $emailService->sendTransactionalEmail(
                $user->email,
                $data['subject'],
                view('emails.activation-nudge', $data)->render(),
                view('emails.activation-nudge-text', $data)->render(),
                $user->name,
                'activation_nudge',
            );

            // sendTransactionalEmail() nunca lança — devolve ['success' => false, ...].
            // Não inspecionar seria perder o e-mail em silêncio logando sucesso.
            if (! ($result['success'] ?? false)) {
                $e = new RuntimeException(
                    'O provedor de e-mail recusou o nudge de ativação: '.($result['error'] ?? 'motivo desconhecido')
                );

                AppLogger::jobFailed(static::class, $e, $this->attempts());
                $this->fail($e);

                return;
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'outcome' => self::OUTCOME_SENT,
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
