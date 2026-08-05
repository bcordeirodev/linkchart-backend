<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\Link;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Varredura diária do marco de 100 cliques: seleciona os links que cruzaram o
 * limiar e enfileira um {@see SendMilestoneEmailJob} por LINK.
 *
 * Agendado todo dia 10:00 America/Sao_Paulo (bootstrap/app.php), rodando no
 * container worker — instância única, então blue/green nunca duplica o
 * disparo. É por link, não por usuário: cada conquista rende seu próprio
 * e-mail, e o claim vive na coluna do link.
 *
 * Elegível = link NÃO-demo com `clicks >= 100`, ainda sem
 * `milestone_100_notified_at`, cujo dono existe e é elegível a e-mails de
 * retenção ({@see User::scopeEligibleForRetentionEmails()}). Link anônimo
 * (user_id nulo) não tem destinatário e fica fora.
 *
 * Um re-run do scheduler é seguro em dois níveis: o filtro de
 * milestone_100_notified_at aqui evita re-enfileirar, e o claim atômico do job
 * por link garante at-most-once mesmo se dois dispatches escaparem.
 */
class DispatchMilestoneEmailsJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable;

    /** Limiar do marco comemorado por este job. */
    public const THRESHOLD = 100;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * Seleciona os links no marco e enfileira um job de envio para cada um.
     */
    public function handle(): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, []);

        try {
            $linkIds = Link::query()
                ->where('is_demo', false)
                ->where('clicks', '>=', self::THRESHOLD)
                ->whereNull('milestone_100_notified_at')
                // whereHas já exige o dono existente (semântica de EXISTS na
                // FK), então link anônimo com user_id nulo cai fora sozinho.
                ->whereHas('user', fn ($query) => $query->eligibleForRetentionEmails())
                ->orderBy('id')
                ->pluck('id');

            foreach ($linkIds as $linkId) {
                SendMilestoneEmailJob::dispatch($linkId);
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'recipients' => $linkIds->count(),
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
