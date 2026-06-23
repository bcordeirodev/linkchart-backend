<?php

namespace App\Logging\Context;

/**
 * Trait for queued jobs to participate in request-id propagation.
 *
 * The dispatcher passes a 'request_id' (and optionally 'user_id') in the job
 * payload. The job's handle() must call $this->pushLogContext() at the start
 * and $this->popLogContext() in a finally block. This makes
 * RequestContext::current() return the originating request's id during the
 * job, so AppLogger/RequestContextProcessor stamp every job log line with the
 * same request_id as the HTTP request that dispatched it.
 *
 * Usage:
 *   public function handle(): void
 *   {
 *       $this->pushLogContext();
 *       try { ... } finally { $this->popLogContext(); }
 *   }
 */
trait HasLogContext
{
    /**
     * Populate the process-scoped RequestContext from the job's payload.
     * Generates a fallback id ('job_xxx') when the payload has none, so logs
     * still have a request_id for correlation within the job execution.
     */
    public function pushLogContext(): void
    {
        $rid = $this->logContextRequestId() ?? 'job_'.bin2hex(random_bytes(8));

        RequestContext::set(new RequestContext(
            requestId: $rid,
            userId: $this->logContextUserId(),
        ));
    }

    /**
     * Clear the RequestContext at the end of the job. MUST be called from
     * a finally block to guarantee isolation between job executions on the
     * same worker process.
     */
    public function popLogContext(): void
    {
        RequestContext::clear();
    }

    /**
     * Return the W3C traceparent passed in the job payload, or null. Override
     * in jobs whose payload carries it so the worker can continue the trace.
     */
    protected function logContextTraceparent(): ?string
    {
        return null;
    }

    /**
     * Build an OpenTelemetry Context from the propagated traceparent so a span
     * opened in the worker is a child of the originating HTTP request's trace.
     * Returns the root context when none was propagated.
     */
    public function extractedTraceContext(): \OpenTelemetry\Context\Context
    {
        $traceparent = $this->logContextTraceparent();

        if ($traceparent === null) {
            return \OpenTelemetry\Context\Context::getCurrent();
        }

        return \OpenTelemetry\API\Globals::propagator()->extract(['traceparent' => $traceparent]);
    }

    /**
     * Return the request id passed in the job payload (or null).
     * Implementing class must override to expose its payload's request_id.
     */
    abstract protected function logContextRequestId(): ?string;

    /**
     * Optional: return the user id associated with the originating request.
     */
    protected function logContextUserId(): ?int
    {
        return null;
    }
}
