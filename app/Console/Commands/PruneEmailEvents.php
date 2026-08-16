<?php

namespace App\Console\Commands;

use App\Logging\AppLogger;
use App\Models\EmailEvent;
use Illuminate\Console\Command;

/**
 * Prune LGPD da tabela email_events: apaga eventos além da janela de retenção.
 *
 * Diferente do sweep de IPs (que mascara), aqui a linha inteira sai — o funil
 * de e-mail é métrica operacional recente, não histórico permanente. Agendado
 * diário em bootstrap/app.php.
 */
class PruneEmailEvents extends Command
{
    protected $signature = 'email-events:prune
        {--days=180 : Janela de retenção em dias}';

    protected $description = 'Remove eventos de e-mail além da janela de retenção';

    /**
     * Apaga os eventos com created_at anterior ao corte e loga o total.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = EmailEvent::query()->where('created_at', '<', $cutoff)->delete();

        AppLogger::event('app', 'info', 'privacy.email_events_pruned', [
            'count' => $deleted,
            'retention_days' => $days,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);

        $this->info("Removidos {$deleted} eventos anteriores a {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
