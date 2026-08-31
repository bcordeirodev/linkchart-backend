<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\Link;
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
 * Envia o winback de UM usuário ausente — "seus links renderam enquanto você
 * esteve fora" (enfileirado pelo {@see DispatchWinbackEmailsJob}).
 *
 * Ordem do handle(): guards (usuário existe → elegível → AINDA ausente → cliques
 * ainda acima do piso) → claim atômico → conteúdo → envio → outcome.
 *
 * O payload carrega só o `userId`: os números são recalculados aqui, não
 * herdados do disparo. A fila pode atrasar, e um e-mail dizendo "195 cliques"
 * para quem já voltou e viu tudo é pior que e-mail nenhum — por isso os MESMOS
 * guards do dispatcher são reavaliados, com uma diferença deliberada: quem
 * logou ou criou link no meio do caminho sai quieto, sem queimar o claim, e
 * volta a ser elegível quando sumir de novo.
 *
 * Delivery é AT MOST ONCE, não at least once — mesmo trade-off do
 * {@see SendWeeklyDigestEmailJob}. O claim é um UPDATE condicional em
 * `users.winback_email_sent_at` executado ANTES do envio; um retry (tries = 3)
 * depois de um envio bem-sucedido encontra 0 linhas e sai quieto. A condição
 * NÃO é "IS NULL" e sim "IS NULL OU mais velho que o cooldown": ausência é
 * recorrente, então o mesmo usuário pode receber outro winback dali a
 * {@see DispatchWinbackEmailsJob::COOLDOWN_DAYS} dias.
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

    /** No-op: o usuário voltou entre o disparo e a execução (logou ou criou link). */
    public const OUTCOME_SKIPPED_RETURNED = 'skipped_returned';

    /** No-op: os cliques da janela caíram abaixo do piso — não há história para contar. */
    public const OUTCOME_SKIPPED_BELOW_FLOOR = 'skipped_below_floor';

    /** No-op: outra execução já enviou o winback dentro do cooldown. */
    public const OUTCOME_SKIPPED_ALREADY_CLAIMED = 'skipped_already_claimed';

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    /**
     * @param  int  $userId  Usuário destinatário (ausente e com links rendendo).
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

            $now = CarbonImmutable::now('America/Sao_Paulo');
            $absentBefore = $now->subDays(DispatchWinbackEmailsJob::ABSENCE_DAYS)->utc();
            $clicksSince = $now->subDays(DispatchWinbackEmailsJob::CLICKS_WINDOW_DAYS)->utc();
            $cooldownBefore = $now->subDays(DispatchWinbackEmailsJob::COOLDOWN_DAYS)->utc();

            if (! $this->isStillAbsent($user, $absentBefore)) {
                $this->succeedWith(self::OUTCOME_SKIPPED_RETURNED, $start);

                return;
            }

            $stats = $this->computeStats($user->id, $clicksSince);

            if ($stats['total'] < DispatchWinbackEmailsJob::MIN_CLICKS) {
                $this->succeedWith(self::OUTCOME_SKIPPED_BELOW_FLOOR, $start);

                return;
            }

            // Claim atômico: só passa se nunca houve winback ou se o último é
            // mais velho que o cooldown. O racer perdedor (dispatch concorrente
            // ou retry) vê 0 linhas e sai quieto.
            $claimed = User::whereKey($this->userId)
                ->where(function ($query) use ($cooldownBefore) {
                    $query->whereNull('winback_email_sent_at')
                        ->orWhere('winback_email_sent_at', '<', $cooldownBefore);
                })
                ->update(['winback_email_sent_at' => now()]);

            if ($claimed === 0) {
                $this->succeedWith(self::OUTCOME_SKIPPED_ALREADY_CLAIMED, $start);

                return;
            }

            $data = $this->buildViewData($user, $stats);

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
                'window_clicks' => $stats['total'],
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
     * Reavalia os dois sinais de presença do dispatcher: último login (com
     * fallback no cadastro, para a base anterior ao tracking de `last_login_at`)
     * e criação de link não-demo dentro da janela.
     *
     * Qualquer um dos dois derruba o envio — quem voltou não precisa de winback,
     * e o claim fica intacto para a próxima ausência.
     */
    private function isStillAbsent(User $user, CarbonImmutable $absentBefore): bool
    {
        $lastSeen = $user->last_login_at ?? $user->created_at;

        if ($lastSeen !== null && $lastSeen->greaterThanOrEqualTo($absentBefore)) {
            return false;
        }

        return ! Link::where('user_id', $user->id)
            ->where('is_demo', false)
            ->where('created_at', '>=', $absentBefore)
            ->exists();
    }

    /**
     * Conta os cliques dos links não-demo do usuário desde `$clicksSince` e
     * resolve o link mais clicado da janela — o par de números que dá corpo ao
     * e-mail ("N cliques" + "quem puxou").
     *
     * O JOIN com `links` é obrigatório (regra global de métricas: clique só
     * conta com o link ao lado, para excluir os semeados de demonstração).
     *
     * @return array{total: int, top: object|null} `top` tem id, title, slug e clicks_count.
     */
    private function computeStats(int $userId, CarbonImmutable $clicksSince): array
    {
        $clicksInWindow = fn () => DB::table('clicks')
            ->join('links', 'links.id', '=', 'clicks.link_id')
            ->where('links.user_id', $userId)
            ->where('links.is_demo', false)
            ->where('clicks.created_at', '>=', $clicksSince);

        return [
            'total' => $clicksInWindow()->count(),
            'top' => $clicksInWindow()
                ->select('links.id', 'links.title', 'links.slug', DB::raw('COUNT(*) as clicks_count'))
                ->groupBy('links.id', 'links.title', 'links.slug')
                ->orderByDesc('clicks_count')
                ->orderBy('links.id')
                ->first(),
        ];
    }

    /**
     * Monta o payload dos templates emails.winback{,-text}: assunto com o
     * número-âncora da janela, card do top link, os dois CTAs com UTM
     * (`winback-email`/`email` — é o que fecha o loop de retenção no log do
     * nginx) e o link assinado de unsubscribe (LGPD).
     *
     * O CTA primário aponta para as estatísticas do TOP link (é dele que fala o
     * card logo acima); o secundário leva à lista completa. Se por algum motivo
     * não houver top link — só acontece em corrida com um delete —, o primário
     * degrada para a lista, nunca para uma URL quebrada.
     *
     * @param  array{total: int, top: object|null}  $stats
     * @return array<string, mixed>
     */
    private function buildViewData(User $user, array $stats): array
    {
        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');
        $utm = 'utm_source=winback-email&utm_medium=email';
        $top = $stats['top'];
        $topClicks = (int) ($top->clicks_count ?? 0);

        return [
            'subject' => "Seus links receberam {$stats['total']} cliques enquanto você esteve fora",
            'user_name' => $user->name,
            'total' => $stats['total'],
            'window_days' => DispatchWinbackEmailsJob::CLICKS_WINDOW_DAYS,
            'cooldown_days' => DispatchWinbackEmailsJob::COOLDOWN_DAYS,
            'top_link_label' => $top ? ($top->title ?: $top->slug) : null,
            'top_link_clicks_label' => $topClicks === 1 ? '1 clique' : $topClicks.' cliques',
            'stats_url' => $top
                ? $frontend.'/links/analytics/'.$top->id.'?'.$utm
                : $frontend.'/links?'.$utm,
            'links_url' => $frontend.'/links?'.$utm,
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
