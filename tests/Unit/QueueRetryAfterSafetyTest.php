<?php

namespace Tests\Unit;

use App\Jobs\LinkHealthCheckJob;
use App\Jobs\ProcessLinkClickJob;
use Tests\TestCase;

/**
 * Reprova a build quando o `retry_after` de uma conexão de fila for menor que o
 * `$timeout` do job mais longo.
 *
 * O contrato do Laravel é: `retry_after` tem que ser MAIOR que a duração máxima de
 * qualquer job daquela conexão. Se for menor, a fila devolve o job enquanto ele
 * ainda está rodando, um segundo worker o pega, e com `tries = 1` isso vira
 * `MaxAttemptsExceededException` — trabalho duplicado e erro em produção, com o
 * agravante de o job na verdade ter concluído com sucesso.
 *
 * Isto aconteceu de verdade: `retry_after` era 90s e o `LinkHealthCheckJob` levava
 * ~87s, então falhava de forma intermitente (dois erros em 2026-07-26, ambos com
 * `job.succeeded` logado 2 segundos depois). Quando a varredura passou a levar 130s,
 * a falha se tornaria sistemática, a cada hora.
 *
 * `->withoutOverlapping()` no scheduler NÃO protege disso: ele impede o scheduler de
 * despachar duas vezes, não a fila de reentregar.
 */
class QueueRetryAfterSafetyTest extends TestCase
{
    /**
     * Jobs de vida longa que definem o piso do `retry_after`.
     *
     * @return array<int, class-string>
     */
    private function longRunningJobs(): array
    {
        return [
            LinkHealthCheckJob::class,
            ProcessLinkClickJob::class,
        ];
    }

    public function test_every_queue_connection_retries_after_the_longest_job_timeout(): void
    {
        $longest = 0;
        $longestJob = null;

        foreach ($this->longRunningJobs() as $jobClass) {
            $timeout = (new \ReflectionClass($jobClass))->getDefaultProperties()['timeout'] ?? 0;

            if ($timeout > $longest) {
                $longest = $timeout;
                $longestJob = $jobClass;
            }
        }

        $this->assertGreaterThan(0, $longest, 'Nenhum job de vida longa declarou $timeout.');

        foreach (config('queue.connections') as $name => $connection) {
            if (! isset($connection['retry_after'])) {
                continue;
            }

            $this->assertGreaterThan(
                $longest,
                $connection['retry_after'],
                "A conexão de fila '{$name}' tem retry_after={$connection['retry_after']}, menor ou igual ao "
                ."timeout de {$longest}s do {$longestJob}. A fila vai reentregar o job enquanto ele ainda roda."
            );
        }
    }
}
