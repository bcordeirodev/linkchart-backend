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
 * Varredura diária do nudge de ativação: seleciona quem se cadastrou ontem e
 * ainda NÃO criou nenhum link e enfileira um
 * {@see SendActivationNudgeEmailJob} por usuário.
 *
 * Agendado todo dia 10:30 America/Sao_Paulo (bootstrap/app.php), rodando no
 * container worker — instância única, então blue/green nunca duplica o disparo.
 * O horário fica entre o winback (10:20) e as dicas do terceiro dia (10:40) de
 * propósito: o nudge precisa chegar ANTES do e-mail de dicas, que pressupõe um
 * link já criado. É a quebra mais precoce do funil (8 de 24 usuários nunca
 * criaram um link, medição de 2026-08-16) e a única que os outros e-mails de
 * retenção não alcançam — todos eles falam de cliques.
 *
 * Janela: `[agora - 2 dias, agora - 1 dia)`, com as fronteiras calculadas em
 * America/Sao_Paulo e convertidas para UTC antes da query. Meia-aberta e de 24h
 * exatas para casar com a cadência diária do scheduler — mesmo desenho do
 * {@see DispatchOnboardingTipsJob}: cada cadastro atravessa a janela em um
 * único run, sem lacuna nem sobreposição.
 *
 * Elegível = usuário criado na janela, elegível a e-mails de retenção
 * ({@see User::scopeEligibleForRetentionEmails()}), ainda sem
 * `activation_nudge_sent_at` e SEM nenhum link não-demo. O link de demonstração
 * semeado no cadastro ({@see SeedDemoLinkJob}) não conta como ativação — quem
 * ainda não encurtou nada por conta própria é exatamente o alvo.
 */
class DispatchActivationNudgesJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable;

    /** Idade mínima (dias) do cadastro para receber o nudge — lado fechado da janela. */
    public const WINDOW_END_DAYS = 1;

    /** Idade máxima (dias) do cadastro — lado aberto da janela. */
    public const WINDOW_START_DAYS = 2;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    /**
     * Seleciona os cadastros da janela ainda sem link próprio e enfileira um
     * job de envio por usuário.
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
                ->whereNull('activation_nudge_sent_at')
                ->where('created_at', '>=', $startUtc)
                ->where('created_at', '<', $endUtc)
                ->whereDoesntHave('links', fn ($query) => $query->where('is_demo', false))
                ->orderBy('id')
                ->pluck('id');

            foreach ($userIds as $userId) {
                SendActivationNudgeEmailJob::dispatch((int) $userId);
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
