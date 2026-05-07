<?php

namespace App\Logging\Processors;

use App\Logging\Context\RequestContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Injects the active RequestContext fields into every log record's $extra.
 *
 * Adds: request_id, user_id, ip, route, env.
 *
 * - Skipped fields when context is null (running in console, scheduled task
 *   without a request id, etc).
 * - Never overwrites a field already present in $extra (caller wins; useful
 *   for tests or for explicitly tagging a log with a different id).
 */
final class RequestContextProcessor implements ProcessorInterface
{
    /** @inheritDoc */
    public function __invoke(LogRecord $record): LogRecord
    {
        $ctx = RequestContext::current();
        $env = app()->environment();

        $additions = ['env' => $env];

        if ($ctx !== null) {
            $additions += [
                'request_id' => $ctx->requestId,
                'user_id'    => $ctx->userId,
                'ip'         => $ctx->ip,
                'route'      => $ctx->route,
            ];
        }

        // null values get filtered by the formatter; keep them out here too
        $additions = array_filter($additions, fn ($v) => $v !== null);

        // caller-provided $extra wins on collision
        $merged = $record->extra + $additions;

        return $record->with(extra: $merged);
    }
}
