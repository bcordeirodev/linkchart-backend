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
 * Varredura diária do winback: seleciona os usuários AUSENTES há duas semanas
 * cujos links continuaram rendendo cliques e enfileira um
 * {@see SendWinbackEmailJob} por usuário.
 *
 * Agendado todo dia 10:20 America/Sao_Paulo (bootstrap/app.php), rodando no
 * container worker — instância única, então blue/green nunca duplica o disparo.
 *
 * ⚠️ Re-segmentação de 2026-08-16. O alvo ANTIGO era o link órfão (14 dias, 0
 * cliques): esse pool é estruturalmente vazio em produção — link de usuário
 * real quase sempre tem clique, e os zerados são anônimos, sem destinatário. O
 * e-mail nunca disparou. O alvo novo é a assimetria oposta e mensurável: gente
 * que sumiu enquanto o produto seguiu trabalhando por ela. `links.winback_email_sent_at`
 * ficou órfã; o claim agora é `users.winback_email_sent_at`.
 *
 * Elegível = usuário elegível a e-mails de retenção
 * ({@see User::scopeEligibleForRetentionEmails()}) que satisfaça TODOS:
 *
 *  1. Ausente há {@see self::ABSENCE_DAYS} dias:
 *     `COALESCE(last_login_at, created_at) < agora − 14d`. O coalesce cobre a
 *     base antiga — `last_login_at` só acumula desde 07/08/2026 e nulo
 *     significa "nunca logou desde o tracking", caso em que o cadastro é o
 *     melhor proxy de última presença.
 *  2. Sem link novo na janela: criar link é presença, mesmo sem login gravado.
 *  3. Links rendendo: >= {@see self::MIN_CLICKS} cliques em links não-demo nos
 *     últimos {@see self::CLICKS_WINDOW_DAYS} dias (mesmo piso do digest —
 *     abaixo disso não há história para contar).
 *  4. Fora do cooldown de {@see self::COOLDOWN_DAYS} dias: ausência é
 *     recorrente e o e-mail pode repetir, mas nunca vira goteira.
 */
class DispatchWinbackEmailsJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable;

    /** Dias sem sinal de presença (login OU link novo) para o usuário entrar no winback. */
    public const ABSENCE_DAYS = 14;

    /** Janela de apuração dos cliques que o e-mail vai relatar. */
    public const CLICKS_WINDOW_DAYS = 14;

    /** Piso de cliques na janela — abaixo disso não há número que justifique o e-mail. */
    public const MIN_CLICKS = 5;

    /** Intervalo mínimo entre dois winbacks para o mesmo usuário. */
    public const COOLDOWN_DAYS = 60;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * Seleciona os usuários ausentes com links rendendo e enfileira um job de
     * envio por usuário.
     */
    public function handle(): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, []);

        try {
            $now = CarbonImmutable::now('America/Sao_Paulo');
            $absentBefore = $now->subDays(self::ABSENCE_DAYS)->utc();
            $clicksSince = $now->subDays(self::CLICKS_WINDOW_DAYS)->utc();
            $cooldownBefore = $now->subDays(self::COOLDOWN_DAYS)->utc();

            $userIds = User::query()
                ->eligibleForRetentionEmails()
                // Guard 1 — ausência. Colunas qualificadas porque `created_at`
                // existe em quase toda tabela do schema; o COALESCE só faz
                // sentido lido como "última presença conhecida".
                ->whereRaw('COALESCE(users.last_login_at, users.created_at) < ?', [$absentBefore])
                // Guard 4 — cooldown. Mesmo formato do claim condicional que o
                // job de envio reavalia antes de mandar.
                ->where(function ($query) use ($cooldownBefore) {
                    $query->whereNull('winback_email_sent_at')
                        ->orWhere('winback_email_sent_at', '<', $cooldownBefore);
                })
                // Guard 2 — link novo é presença.
                ->whereDoesntHave('links', function ($query) use ($absentBefore) {
                    $query->where('is_demo', false)
                        ->where('created_at', '>=', $absentBefore);
                })
                // Guard 3 — links rendendo. A subquery agrega por dono JOINando
                // links (regra global: clique só conta com o link ao lado, para
                // excluir demo) e o piso vai no HAVING.
                ->whereIn('id', function ($query) use ($clicksSince) {
                    $query->from('clicks')
                        ->join('links', 'links.id', '=', 'clicks.link_id')
                        ->select('links.user_id')
                        ->where('links.is_demo', false)
                        ->whereNotNull('links.user_id')
                        ->where('clicks.created_at', '>=', $clicksSince)
                        ->groupBy('links.user_id')
                        ->havingRaw('COUNT(*) >= ?', [self::MIN_CLICKS]);
                })
                ->orderBy('id')
                ->pluck('id');

            foreach ($userIds as $userId) {
                SendWinbackEmailJob::dispatch((int) $userId);
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'recipients' => $userIds->count(),
                'absent_before' => $absentBefore->toIso8601String(),
                'clicks_since' => $clicksSince->toIso8601String(),
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
