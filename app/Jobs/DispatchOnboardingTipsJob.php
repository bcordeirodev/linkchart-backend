<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Varredura diária das dicas de terceiro dia: seleciona quem se cadastrou há
 * três dias e enfileira um {@see SendOnboardingTipsEmailJob} por usuário.
 *
 * Agendado todo dia 10:40 America/Sao_Paulo (bootstrap/app.php), rodando no
 * container worker — instância única, então blue/green nunca duplica o
 * disparo. O terceiro dia é deliberado: o boas-vindas já saiu no cadastro, e
 * três dias depois o usuário já encurtou o primeiro link e tem contexto para
 * os recursos que quase ninguém descobre sozinho.
 *
 * Janela: `[agora - 4 dias, agora - 3 dias)`, com as fronteiras calculadas em
 * America/Sao_Paulo e convertidas para UTC antes da query. Meia-aberta e de
 * 24h exatas para casar com a cadência diária do scheduler.
 *
 * Elegível = usuário criado na janela, elegível a e-mails de retenção
 * ({@see User::scopeEligibleForRetentionEmails()}) e ainda sem
 * `onboarding_tips_sent_at`.
 */
class DispatchOnboardingTipsJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable;

    /** Idade mínima (dias) do cadastro para receber as dicas — lado fechado da janela. */
    public const WINDOW_END_DAYS = 3;

    /** Idade máxima (dias) do cadastro — lado aberto da janela. */
    public const WINDOW_START_DAYS = 4;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * Seleciona os cadastros da janela e enfileira um job de envio por usuário.
     */
    public function handle(): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, []);

        try {
            $now = CarbonImmutable::now('America/Sao_Paulo');
            $startUtc = $now->subDays(self::WINDOW_START_DAYS)->utc();
            $endUtc = $now->subDays(self::WINDOW_END_DAYS)->utc();

            $userIds = User::query()
                ->eligibleForRetentionEmails()
                ->whereNull('onboarding_tips_sent_at')
                ->where('created_at', '>=', $startUtc)
                ->where('created_at', '<', $endUtc)
                ->orderBy('id')
                ->pluck('id');

            foreach ($userIds as $userId) {
                SendOnboardingTipsEmailJob::dispatch($userId);
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'recipients' => $userIds->count(),
                'window_start' => $startUtc->toIso8601String(),
                'window_end' => $endUtc->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            AppLogger::jobFailed(static::class, $e, $this->attempts());
            throw $e;
        } finally {
            $this->popLogContext();
        }
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
