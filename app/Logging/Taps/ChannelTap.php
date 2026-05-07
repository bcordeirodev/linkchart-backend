<?php

namespace App\Logging\Taps;

use App\Logging\Formatters\KeyValueFormatter;
use App\Logging\Processors\PiiRedactionProcessor;
use App\Logging\Processors\RequestContextProcessor;
use Illuminate\Log\Logger;

/**
 * Standard tap applied to most channels:
 *  - Replaces handler formatter with KeyValueFormatter.
 *  - Pushes RequestContextProcessor (request_id/user_id/ip/route/env injection).
 *  - Pushes PiiRedactionProcessor unless invoked with the ':skip-redaction' arg.
 *
 * Skipping redaction is required for auth/audit channels that need raw
 * email/ip for compliance. Pass the flag via the tap config string:
 *
 *   'tap' => [App\Logging\Taps\ChannelTap::class.':skip-redaction'],
 */
final class ChannelTap
{
    /**
     * Apply standard logging configuration to the channel.
     *
     * @param  Logger  $logger  Illuminate logger wrapping a Monolog instance.
     * @param  string  $mode    'skip-redaction' to omit the PII redactor; anything else applies it.
     */
    public function __invoke(Logger $logger, string $mode = ''): void
    {
        $skipRedaction = $mode === 'skip-redaction';
        $monolog = $logger->getLogger();

        $formatter = new KeyValueFormatter();
        foreach ($monolog->getHandlers() as $handler) {
            if (method_exists($handler, 'setFormatter')) {
                $handler->setFormatter($formatter);
            }
        }

        $monolog->pushProcessor(new RequestContextProcessor());
        if (! $skipRedaction) {
            $monolog->pushProcessor(new PiiRedactionProcessor());
        }
    }
}
