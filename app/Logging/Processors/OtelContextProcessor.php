<?php

namespace App\Logging\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * Monolog processor that stamps each log record with the active OpenTelemetry
 * trace_id and span_id, so log lines can be correlated with traces in Grafana
 * (Tempo ↔ Loki). When no valid span is active (OTel disabled or outside a
 * traced scope) the record is returned unchanged.
 */
final class OtelContextProcessor implements ProcessorInterface
{
    /**
     * Add trace_id and span_id to the log record when a valid span is active.
     *
     * @param  LogRecord  $record  The incoming Monolog log record.
     * @return LogRecord The record with trace_id/span_id in extra, or unchanged if no valid span.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = Span::getCurrent()->getContext();

        if (! $context->isValid()) {
            return $record;
        }

        return $record->with(extra: $record->extra + [
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
        ]);
    }
}
