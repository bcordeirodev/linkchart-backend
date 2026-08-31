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
 * No application code dispatches this job directly (it re-dispatches itself,
 * see below).
 *
 * Execution model (since 2026-08-31, two modes on the same class):
 *   - **Dispatcher** (`$linkIds === null`, the scheduled entry point): selects
 *     the active link ids and re-dispatches this same class once per chunk of
 *     {@see self::BATCH_SIZE} ids. Finishes in milliseconds.
 *   - **Batch** (`$linkIds` preenchido): probes only its slice of links.
 *
 * Por que não uma varredura única: o job monolítico cresceu junto com a base
 * (~683 links ativos ≈ 290s de varredura) e passou a estourar o `$timeout` de
 * 300s. O `TimeoutExceededException` mata o worker com SIGKILL, que pula os
 * shutdown handlers do PHP — a falha não chegava ao canal `jobs` nem às
 * métricas (72 varreduras perdidas em silêncio entre 17 e 31/08). Em lotes,
 * cada job termina em segundos, uma falha custa {@see self::BATCH_SIZE} links
 * (não a varredura inteira) e é logada de verdade. Os dois modos logam com o
 * MESMO nome de classe, então o gauge `job_last_success_timestamp_seconds` e
 * os alertas do Grafana continuam válidos sem ajuste.
 *
 * Side effects:
 *   - HTTP / external calls (batch mode only): issues an HTTP `HEAD` request
 *     (via Guzzle) to each link's `original_url`, falling back to `GET` when
 *     the destination rejects `HEAD`. TLS verification is enabled (Guzzle
 *     default); links with broken or self-signed certificates will be flagged
 *     as `health_status = 'error'`.
 *   - DB writes (batch mode only): updates `links.health_status`
 *     ('ok' | 'error' | 'unknown') and `links.health_checked_at` via a direct
 *     `DB::table('links')->update([...])` call (no model events, so the Link
 *     cache is NOT invalidated — health fields are read separately from slug
 *     cache hits).
 *   - Cache: none written by this job.
 *   - Queue (dispatcher mode only): dispatches one batch instance of this
 *     class per {@see self::BATCH_SIZE} active links.
 *   - Log channels: `jobs` — lifecycle events (`job.started` / `job.succeeded` /
 *     `job.failed`); the dispatcher logs `links` / `batches`, each batch logs
 *     `checked` / `errors` / `unknown`. Per-link HTTP errors are still caught
 *     silently and only recorded as `health_status` (no log line per
 *     unhealthy link).
 *
 * Retry policy:
 *   - `$tries = 1` — no retries; a failed batch is acceptable since the
 *     scheduler will sweep again in the next hourly tick.
 *   - `$backoff`: not set; uses framework default (irrelevant given `$tries = 1`).
 *   - On final failure: no `failed()` callback. The job is moved to failed-jobs;
 *     health columns for that batch are not updated.
 *
 * Idempotency: YES.
 *   Re-running either mode at any time simply re-checks each URL and overwrites
 *   the health columns. No new rows are created; existing data is always
 *   replaced. Links deactivated between dispatch and batch execution are
 *   filtered out again by the batch query.
 *
 * @see \App\Models\Link
 */
class LinkHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Links por lote. Pior caso realista de um lote inteiro de destinos em
     * timeout: 20 × (10s de timeout + 0,5s de espaçamento) ≈ 210s — folga
     * confortável dentro do `$timeout` de 300s, que o lote monolítico não tinha.
     */
    private const BATCH_SIZE = 20;

    /**
     * @param  array<int, int>|null  $linkIds  Ids do lote a sondar; `null` ativa o
     *                                         modo dispatcher (entrada do scheduler).
     */
    public function __construct(public ?array $linkIds = null) {}

    public function handle(): void
    {
        $start = microtime(true);
        AppLogger::jobStarted(static::class);

        try {
            $context = $this->linkIds === null
                ? $this->dispatchBatches()
                : $this->checkBatch();

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000, $context);
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
     * Modo dispatcher: fatia os links ativos e enfileira um lote por fatia.
     *
     * @return array{links: int, batches: int} Contexto para o log de sucesso.
     */
    private function dispatchBatches(): array
    {
        $ids = Link::where('is_active', true)->pluck('id');

        $batches = 0;
        foreach ($ids->chunk(self::BATCH_SIZE) as $chunk) {
            static::dispatch($chunk->values()->all());
            $batches++;
        }

        return ['links' => $ids->count(), 'batches' => $batches];
    }

    /**
     * Modo lote: sonda os links da fatia e persiste as colunas de saúde.
     *
     * @return array{checked: int, errors: int, unknown: int} Contexto para o log de sucesso.
     */
    private function checkBatch(): array
    {
        $http = $this->httpClient();

        $checked = 0;
        $errors = 0;
        $unknown = 0;
        $lastRequestAt = [];

        $links = Link::whereIn('id', $this->linkIds ?? [])
            ->where('is_active', true)
            ->get(['id', 'original_url']);

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

        return ['checked' => $checked, 'errors' => $errors, 'unknown' => $unknown];
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
