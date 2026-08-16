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
 * Varredura diária da escada de marcos de cliques (10/25/50/100/250/500/1000):
 * seleciona os links que cruzaram um degrau novo e enfileira um
 * {@see SendMilestoneEmailJob} carregando o par (link, degrau).
 *
 * Agendado todo dia 10:00 America/Sao_Paulo (bootstrap/app.php), rodando no
 * container worker — instância única, então blue/green nunca duplica o
 * disparo. O job enfileirado continua sendo por LINK (o claim vive na coluna
 * `links.milestone_last_threshold`), mas a varredura aplica um TETO de um
 * e-mail de marco por usuário por rodada: com vários links no degrau, sai o de
 * maior degrau (empate → mais cliques) e os preteridos ficam para a próxima
 * varredura — a escada é sete vezes mais frequente que o marco único de antes,
 * e sem o teto uma conta ativa receberia uma rajada num dia só.
 *
 * Elegível = link NÃO-demo com `clicks >= 10` cujo maior degrau cruzado é maior
 * que o já comemorado, e cujo dono existe e é elegível a e-mails de retenção
 * ({@see User::scopeEligibleForRetentionEmails()}). Link anônimo (user_id nulo)
 * não tem destinatário e fica fora.
 *
 * Um re-run do scheduler é seguro em dois níveis: o filtro por degrau aqui
 * evita re-enfileirar, e o claim atômico por degrau no job garante at-most-once
 * mesmo se dois dispatches escaparem.
 */
class DispatchMilestoneEmailsJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable;

    /** Escada de marcos comemorados, em ordem crescente. */
    public const THRESHOLDS = [10, 25, 50, 100, 250, 500, 1000];

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * Maior degrau da escada já cruzado por esse total de cliques (0 = nenhum).
     */
    public static function highestCrossedThreshold(int $clicks): int
    {
        $crossed = 0;

        foreach (self::THRESHOLDS as $threshold) {
            if ($clicks >= $threshold) {
                $crossed = $threshold;
            }
        }

        return $crossed;
    }

    /**
     * Seleciona os links que cruzaram um degrau novo e enfileira um job de
     * envio por usuário (o de maior degrau).
     */
    public function handle(): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, []);

        try {
            $candidates = Link::query()
                ->where('is_demo', false)
                ->where('clicks', '>=', self::THRESHOLDS[0])
                // Filtro grosso: degrau novo implica last < clicks; o refino
                // fica no PHP.
                ->whereColumn('milestone_last_threshold', '<', 'clicks')
                // whereHas já exige o dono existente (semântica de EXISTS na
                // FK), então link anônimo com user_id nulo cai fora sozinho.
                ->whereHas('user', fn ($query) => $query->eligibleForRetentionEmails())
                ->orderBy('id')
                ->get(['id', 'user_id', 'clicks', 'milestone_last_threshold']);

            // Teto de 1 e-mail de marco por usuário por varredura: fica o maior
            // degrau (empate → mais cliques). O preterido não é reivindicado e
            // sai amanhã.
            $bestByUser = [];

            foreach ($candidates as $link) {
                $threshold = self::highestCrossedThreshold((int) $link->clicks);

                if ($threshold <= (int) $link->milestone_last_threshold) {
                    continue; // ex.: 12 cliques com o degrau 10 já comemorado
                }

                $current = $bestByUser[$link->user_id] ?? null;

                if ($current === null
                    || $threshold > $current['threshold']
                    || ($threshold === $current['threshold'] && (int) $link->clicks > $current['clicks'])) {
                    $bestByUser[$link->user_id] = [
                        'link_id' => (int) $link->id,
                        'threshold' => $threshold,
                        'clicks' => (int) $link->clicks,
                    ];
                }
            }

            foreach ($bestByUser as $best) {
                SendMilestoneEmailJob::dispatch($best['link_id'], $best['threshold']);
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'recipients' => count($bestByUser),
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
