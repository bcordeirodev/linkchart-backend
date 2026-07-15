<?php

declare(strict_types=1);

namespace App\Profiling;

use App\Logging\AppLogger;
use ExcimerProfiler;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Drives the `ext-excimer` sampling profiler for a single request and pushes
 * the resulting folded stacks to Grafana Pyroscope.
 *
 * Registered as a singleton so the same instance spans the terminable
 * middleware's `handle()` (which calls {@see start()}) and `terminate()`
 * (which calls {@see flush()} after the response is already sent).
 *
 * Hard rule: profiling must NEVER affect the request. Every path guards on the
 * kill switch + extension presence and swallows its own errors — a broken
 * profiler degrades to no telemetry, never to a broken response.
 */
class PyroscopeProfiler
{
    /** The active profiler for the current request, or null when not sampling. */
    private ?ExcimerProfiler $profiler = null;

    /** Unix timestamp (seconds) when profiling started — the Pyroscope `from`. */
    private ?int $startedAt = null;

    /**
     * Decides whether THIS request should be profiled: the feature is on, the
     * extension is present, no profile is already running, and the request
     * falls inside the configured sampling fraction.
     *
     * @return bool true when {@see start()} should be called for this request.
     */
    public function shouldSample(): bool
    {
        if (! config('pyroscope.enabled') || ! extension_loaded('excimer')) {
            return false;
        }
        if ($this->profiler !== null) {
            return false;
        }
        $rate = (float) config('pyroscope.sample_rate', 0.0);
        if ($rate <= 0.0) {
            return false;
        }
        if ($rate >= 1.0) {
            return true;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    /**
     * Starts sampling the current request's stack. Safe to call unconditionally
     * after {@see shouldSample()} returns true; any failure leaves profiling off.
     */
    public function start(): void
    {
        try {
            $profiler = new ExcimerProfiler;
            $profiler->setPeriod((float) config('pyroscope.period', 0.01));
            $profiler->setEventType(EXCIMER_CPU);
            $profiler->start();

            $this->profiler = $profiler;
            $this->startedAt = time();
        } catch (Throwable $e) {
            $this->profiler = null;
            $this->startedAt = null;
            $this->logFailure('pyroscope.start_failed', $e);
        }
    }

    /**
     * Stops the profiler and pushes the collapsed profile to Pyroscope. A no-op
     * when nothing is being profiled. Runs after the response is flushed, so its
     * cost is off the user-visible request path.
     */
    public function flush(): void
    {
        $profiler = $this->profiler;
        $startedAt = $this->startedAt;
        $this->profiler = null;
        $this->startedAt = null;

        if ($profiler === null || $startedAt === null) {
            return;
        }

        try {
            $profiler->stop();
            $collapsed = $profiler->getLog()->formatCollapsed();
            if ($collapsed === '') {
                return;
            }
            $this->push($collapsed, $startedAt, time());
        } catch (Throwable $e) {
            $this->logFailure('pyroscope.flush_failed', $e);
        }
    }

    /**
     * POSTs folded stacks to Pyroscope's `/ingest` endpoint with basic auth.
     *
     * @param  string  $collapsed  folded stacks (`frame;frame count` per line).
     * @param  int  $from  profiling start, unix seconds.
     * @param  int  $until  profiling end, unix seconds (>= $from + 1).
     */
    private function push(string $collapsed, int $from, int $until): void
    {
        $endpoint = rtrim((string) config('pyroscope.endpoint', ''), '/');
        $username = (string) config('pyroscope.username', '');
        $password = (string) config('pyroscope.password', '');
        if ($endpoint === '' || $username === '' || $password === '') {
            return;
        }

        $period = (float) config('pyroscope.period', 0.01);
        $sampleRate = $period > 0 ? (int) round(1 / $period) : 100;
        $name = (string) config('pyroscope.app_name', 'linkcharts-backend');
        $env = (string) config('pyroscope.environment', 'production');

        // Pyroscope encodes tags in the `name` param: app{key=val,key=val}.
        // These are QUERY params (the request body is the folded stacks), so
        // build them into the URL rather than passing them as post data.
        $query = http_build_query([
            'name' => sprintf('%s{env=%s,role=web}', $name, $env),
            'from' => (string) $from,
            'until' => (string) max($until, $from + 1),
            'sampleRate' => (string) $sampleRate,
            'spyName' => 'excimerspy',
            'format' => 'folded',
        ]);

        try {
            Http::withBasicAuth($username, $password)
                ->timeout((float) config('pyroscope.push_timeout', 2.0))
                ->withBody($collapsed, 'text/plain')
                ->post($endpoint.'/ingest?'.$query);
        } catch (Throwable $e) {
            $this->logFailure('pyroscope.push_failed', $e);
        }
    }

    /**
     * Records a profiling failure on the `app` channel at warning level —
     * visible for debugging without ever surfacing to the request.
     */
    private function logFailure(string $event, Throwable $e): void
    {
        try {
            AppLogger::event('app', 'warning', $event, [
                'exception' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // Logging itself must never break the request either.
        }
    }
}
