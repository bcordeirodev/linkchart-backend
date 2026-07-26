<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Models\Link;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Periodically checks the reachability of every active link's original URL.
 *
 * Trigger: the Laravel scheduler defined in `bootstrap/app.php` (line 10)
 * schedules this job to run `hourly()->withoutOverlapping()`:
 * ```php
 * $schedule->job(new \App\Jobs\LinkHealthCheckJob)->hourly()->withoutOverlapping();
 * ```
 * No application code dispatches this job directly.
 *
 * Side effects:
 *   - HTTP / external calls: issues an HTTP `HEAD` request (via Guzzle) to
 *     each active link's `original_url`. Processes links in chunks of 50 to
 *     limit peak memory usage. TLS verification is enabled (Guzzle default);
 *     links with broken or self-signed certificates will be flagged as
 *     `health_status = 'error'`.
 *   - DB writes: updates `links.health_status` ('ok' | 'error') and
 *     `links.health_checked_at` for every active link via a direct
 *     `DB::table('links')->update([...])` call (no model events, so the Link
 *     cache is NOT invalidated — health fields are read separately from slug
 *     cache hits).
 *   - Cache: none written by this job.
 *   - Queue: no further jobs are dispatched.
 *   - Log channels: `jobs` — lifecycle events (`job.started` / `job.succeeded` /
 *     `job.failed`) plus per-run totals (`checked` / `errors`) via `AppLogger`.
 *     Per-link HTTP errors are still caught silently and only recorded as
 *     `health_status = 'error'` (no log line per unhealthy link).
 *
 * Retry policy:
 *   - `$tries = 1` — no retries; a failed run is acceptable since the scheduler
 *     will try again in the next hourly tick.
 *   - `$backoff`: not set; uses framework default (irrelevant given `$tries = 1`).
 *   - On final failure: no `failed()` callback. The job is moved to failed-jobs
 *     silently; health columns for that batch are not updated.
 *
 * Idempotency: YES.
 *   Re-running the job at any time simply re-checks each URL and overwrites the
 *   health columns. No new rows are created; existing data is always replaced.
 *
 * @see \App\Models\Link
 */
class LinkHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $start = microtime(true);
        AppLogger::jobStarted(static::class);

        try {
            [$checked, $errors, $unknown] = $this->checkActiveLinks();

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, [
                'checked' => $checked,
                'errors' => $errors,
                'unknown' => $unknown,
            ]);
        } catch (\Throwable $e) {
            AppLogger::jobFailed(static::class, $e, $this->attempts());
            throw $e;
        }
    }

    /**
     * User-Agent identificável. O default do Guzzle (`GuzzleHttp/7`) leva 403 de
     * proteção anti-bot em vários destinos legítimos — sympla.com.br era um.
     */
    private const PROBE_USER_AGENT = 'Mozilla/5.0 (compatible; LinkChartsHealthBot/1.0; +https://linkcharts.com.br)';

    /** Intervalo mínimo entre duas requisições ao MESMO host, em microssegundos. */
    private const MIN_HOST_INTERVAL_MICROS = 500000;

    /**
     * Constrói o client HTTP usado para sondar os destinos.
     *
     * Seam deliberadamente `protected`: o client não pode ser propriedade do job
     * (ele precisa continuar serializável para a fila), então é aqui que os testes
     * injetam respostas pré-programadas.
     *
     * @return Client O client configurado.
     */
    protected function httpClient(): Client
    {
        return new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => ['max' => 5],
            'http_errors' => false,
            'headers' => [
                'User-Agent' => self::PROBE_USER_AGENT,
                'Accept' => '*/*',
            ],
        ]);
    }

    /**
     * Sonda todos os links ativos e persiste as colunas de saúde.
     *
     * @return array{0:int,1:int,2:int} Tupla [checados, marcados 'error', marcados 'unknown'].
     */
    private function checkActiveLinks(): array
    {
        $http = $this->httpClient();

        $checked = 0;
        $errors = 0;
        $unknown = 0;
        $lastRequestAt = [];

        Link::where('is_active', true)
            ->select(['id', 'original_url'])
            ->chunk(50, function ($links) use ($http, &$checked, &$errors, &$unknown, &$lastRequestAt) {
                foreach ($links as $link) {
                    $this->throttlePerHost($link->original_url, $lastRequestAt);

                    $status = $this->probe($http, $link->original_url);

                    $checked++;
                    if ($status === 'error') {
                        $errors++;
                    } elseif ($status === 'unknown') {
                        $unknown++;
                    }

                    DB::table('links')
                        ->where('id', $link->id)
                        ->update([
                            'health_status' => $status,
                            'health_checked_at' => now(),
                        ]);
                }
            });

        return [$checked, $errors, $unknown];
    }

    /**
     * Classifica a saúde de um destino.
     *
     * Regra central: **'error' só quando o destino respondeu ativamente com erro.**
     * Tudo que é ambíguo vira 'unknown', porque afirmar "link quebrado" sem
     * evidência é pior que não afirmar nada — antes desta regra, ~23% dos links
     * ativos apareciam como quebrados estando vivos.
     *
     * @param  Client  $http  Client já configurado.
     * @param  string  $url  URL de destino a sondar.
     * @return string 'ok' | 'error' | 'unknown'
     */
    private function probe(Client $http, string $url): string
    {
        try {
            $code = $http->head($url)->getStatusCode();

            // Muitos hosts não suportam HEAD (405) ou bloqueiam bots só nele (403).
            // Um GET desfaz o falso-positivo. 429 não é retentado: já é limite.
            if ($code >= 400 && $code !== 429) {
                $code = $http->get($url)->getStatusCode();
            }
        } catch (\Throwable $e) {
            // Não alcançamos o destino daqui. Pode ser link morto, mas também pode
            // ser o host bloqueando o IP do droplet (anatel.com.br fazia isso) ou
            // timeout. Sem resposta, não afirmamos nada.
            return 'unknown';
        }

        if ($code >= 200 && $code < 400) {
            return 'ok';
        }

        // 429 = o destino nos limitou, não diz nada sobre o link.
        return $code === 429 ? 'unknown' : 'error';
    }

    /**
     * Espaça requisições ao mesmo host.
     *
     * O job varre em ordem de id, e links do mesmo host tendem a ser criados
     * juntos — então sem espaçamento ele martela o mesmo destino em sequência e
     * toma 429. Foi o que aconteceu com os 9 links de chat.whatsapp.com.
     *
     * @param  string  $url  URL do destino a sondar.
     * @param  array<string, float>  $lastRequestAt  Mapa host => timestamp da última requisição.
     */
    private function throttlePerHost(string $url, array &$lastRequestAt): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return;
        }

        $previous = $lastRequestAt[$host] ?? null;

        if ($previous !== null) {
            $elapsedMicros = (microtime(true) - $previous) * 1000000;

            if ($elapsedMicros < self::MIN_HOST_INTERVAL_MICROS) {
                usleep((int) (self::MIN_HOST_INTERVAL_MICROS - $elapsedMicros));
            }
        }

        $lastRequestAt[$host] = microtime(true);
    }
}
