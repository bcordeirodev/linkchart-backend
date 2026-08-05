<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Varredura diária do winback: seleciona os links que completaram duas semanas
 * sem NENHUM clique e enfileira um {@see SendWinbackEmailJob} por USUÁRIO.
 *
 * Agendado todo dia 10:20 America/Sao_Paulo (bootstrap/app.php), rodando no
 * container worker — instância única, então blue/green nunca duplica o
 * disparo. Diferente do marco, aqui o agrupamento é por dono: quem criou três
 * links órfãos na mesma semana recebe UM e-mail listando os três, não três
 * e-mails.
 *
 * Janela: `[agora - 15 dias, agora - 14 dias)`, com as fronteiras calculadas em
 * America/Sao_Paulo e convertidas para UTC antes da query. Meia-aberta e
 * exatamente de 24h para casar com a cadência diária do scheduler: cada link
 * atravessa a janela em um único run, sem lacuna nem sobreposição.
 *
 * Elegível = link NÃO-demo, `clicks = 0`, criado na janela, ainda sem
 * `winback_email_sent_at`, de dono existente e elegível a e-mails de retenção
 * ({@see \App\Models\User::scopeEligibleForRetentionEmails()}).
 */
class DispatchWinbackEmailsJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable;

    /** Idade mínima (dias) do link para entrar no winback — lado fechado da janela. */
    public const WINDOW_END_DAYS = 14;

    /** Idade máxima (dias) do link — lado aberto da janela. */
    public const WINDOW_START_DAYS = 15;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * Seleciona os links órfãos da janela, agrupa por dono e enfileira um job
     * de envio por usuário com a lista de ids.
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

            $linksByUser = Link::query()
                ->where('is_demo', false)
                ->where('clicks', 0)
                ->whereNull('winback_email_sent_at')
                ->where('created_at', '>=', $startUtc)
                ->where('created_at', '<', $endUtc)
                // whereHas já exige o dono existente (semântica de EXISTS na
                // FK), então link anônimo com user_id nulo cai fora sozinho.
                ->whereHas('user', fn ($query) => $query->eligibleForRetentionEmails())
                ->orderBy('id')
                ->get(['id', 'user_id'])
                ->groupBy('user_id');

            foreach ($linksByUser as $userId => $links) {
                SendWinbackEmailJob::dispatch(
                    (int) $userId,
                    $links->pluck('id')->map(fn ($id) => (int) $id)->all(),
                );
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'recipients' => $linksByUser->count(),
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
