<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Profiling\PyroscopeProfiler;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminable middleware that continuous-profiles a sampled fraction of requests
 * with `ext-excimer` and ships the result to Grafana Pyroscope.
 *
 * `handle()` starts the profiler (only when {@see PyroscopeProfiler::shouldSample()}
 * says so); `terminate()` — which Laravel runs AFTER the response is sent to the
 * client — stops it and pushes the profile. The user never waits on the export.
 *
 * The profiler is a container singleton, so the same instance is shared between
 * `handle()` and `terminate()` regardless of how the middleware is resolved.
 */
class PyroscopeSampling
{
    public function __construct(private readonly PyroscopeProfiler $profiler) {}

    /**
     * Begins sampling this request when it falls inside the sampling fraction,
     * then hands off to the rest of the pipeline unchanged.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->profiler->shouldSample()) {
            $this->profiler->start();
        }

        return $next($request);
    }

    /**
     * Runs after the response is flushed: stops the profiler and pushes the
     * profile off the request's critical path. A no-op when this request was
     * not sampled.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->profiler->flush();
    }
}
