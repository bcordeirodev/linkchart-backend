<?php

namespace App\Logging\Context;

/**
 * Process-scoped log context. Populated by AssignRequestId middleware (HTTP)
 * or by HasLogContext trait (queued jobs). Read by RequestContextProcessor
 * and injected into every log record while it is active.
 *
 * Lifecycle: middleware/trait sets at boundary, clears at teardown. Singleton
 * is per process; PHP-FPM is shared-nothing per request and queue workers
 * call clear() in the trait's finally block, so isolation between
 * requests/jobs is guaranteed.
 */
final class RequestContext
{
    private static ?self $current = null;

    /**
     * @param  string  $requestId  Stable id for the logical request (HTTP or job).
     * @param  int|null  $userId  Authenticated user id, when known.
     * @param  string|null  $ip  Client IP for HTTP requests.
     * @param  string|null  $route  Route name or path; null for jobs/console.
     */
    public function __construct(
        public readonly string $requestId,
        public readonly ?int $userId = null,
        public readonly ?string $ip = null,
        public readonly ?string $route = null,
    ) {}

    /**
     * Return the active context for this process, or null when none is set.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Replace the active context for this process.
     */
    public static function set(self $ctx): void
    {
        self::$current = $ctx;
    }

    /**
     * Clear the active context. Always call in a finally block at the
     * end of the request/job to avoid leaks between executions.
     */
    public static function clear(): void
    {
        self::$current = null;
    }
}
