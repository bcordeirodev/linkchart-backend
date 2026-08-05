<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Disparo semanal do digest de cliques: seleciona os usuários elegíveis e
 * enfileira um {@see SendWeeklyDigestEmailJob} por usuário.
 *
 * Agendado toda segunda 09:00 America/Sao_Paulo (bootstrap/app.php), rodando
 * no container worker — instância única, então blue/green nunca duplica o
 * disparo. A janela relatada é a semana anterior completa: segunda 00:00 até
 * a segunda 00:00 seguinte, no fuso de São Paulo, com a semana pinada em
 * MONDAY explicitamente (startOfWeek herda o locale global — gotcha real).
 *
 * Elegível = usuário verificado, weekly_digest_enabled, fora das contas demo
 * (DEMO_ACCOUNT_IDS), ainda sem claim nesta semana e com >= 1 clique em link
 * próprio NÃO-demo dentro da janela. Quem teve 0 cliques não recebe nada —
 * decisão de produto: digest só com dados reais.
 *
 * Um re-run do scheduler é seguro em dois níveis: o filtro de
 * weekly_digest_sent_at aqui evita re-enfileirar, e o claim atômico do job
 * por usuário garante at-most-once mesmo se dois dispatches escaparem.
 */
class DispatchWeeklyDigestsJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable;

    /**
     * Contas de demonstração do produto — excluídas de TODA métrica de cliques
     * (o volume demo já inverteu geografia/tendência em jul/2026). Mesma regra
     * aplicada nas queries de analytics.
     */
    public const DEMO_ACCOUNT_IDS = [40, 41, 45];

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * Seleciona elegíveis e enfileira um job de envio por usuário, com a
     * janela (UTC, ISO-8601) serializada no payload — um retry atrasado do
     * job por usuário relata a MESMA semana, não a corrente.
     */
    public function handle(): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, []);

        try {
            $windowEnd = CarbonImmutable::now('America/Sao_Paulo')->startOfWeek(CarbonInterface::MONDAY);
            $windowStart = $windowEnd->subWeek();

            $startUtc = $windowStart->utc();
            $endUtc = $windowEnd->utc();

            $userIds = User::query()
                ->where('weekly_digest_enabled', true)
                ->whereNotIn('id', self::DEMO_ACCOUNT_IDS)
                // Equivalente SQL de User::hasVerifiedEmail(): auth0_sub
                // preenchido OU (email_verified E email_verified_at).
                ->where(function ($query) {
                    $query->whereNotNull('auth0_sub')
                        ->orWhere(fn ($q) => $q->where('email_verified', true)->whereNotNull('email_verified_at'));
                })
                // Já recebeu o digest desta semana (re-run do scheduler)? Fora.
                ->where(function ($query) use ($endUtc) {
                    $query->whereNull('weekly_digest_sent_at')
                        ->orWhere('weekly_digest_sent_at', '<', $endUtc);
                })
                ->whereExists(function ($query) use ($startUtc, $endUtc) {
                    $query->select(DB::raw(1))
                        ->from('clicks')
                        ->join('links', 'links.id', '=', 'clicks.link_id')
                        ->whereColumn('links.user_id', 'users.id')
                        ->where('links.is_demo', false)
                        ->where('clicks.created_at', '>=', $startUtc)
                        ->where('clicks.created_at', '<', $endUtc);
                })
                ->orderBy('id')
                ->pluck('id');

            // Sinal antecipado: a leva de segunda somada aos transacionais do
            // dia não pode encostar nos 300/dia do Brevo free — quando este
            // warning começar a aparecer, é hora de migrar de plano/provedor.
            $threshold = (int) config('services.transactional_email.volume_warn_threshold', 250);
            if ($userIds->count() >= $threshold) {
                AppLogger::event('jobs', 'warning', 'digest.volume_near_daily_cap', [
                    'recipients' => $userIds->count(),
                    'daily_cap' => 300,
                    'threshold' => $threshold,
                ]);
            }

            foreach ($userIds as $userId) {
                SendWeeklyDigestEmailJob::dispatch(
                    $userId,
                    $startUtc->toIso8601String(),
                    $endUtc->toIso8601String(),
                );
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
