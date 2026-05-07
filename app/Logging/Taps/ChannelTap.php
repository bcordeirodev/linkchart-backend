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
 *  - Pushes PiiRedactionProcessor unless `logging.channels.<channel>.skip_redaction` is true
 *    (auth/audit channels set this to keep email/ip raw for compliance).
 */
final class ChannelTap
{
    /**
     * Apply standard logging configuration to the channel.
     *
     * @param  Logger  $logger  Illuminate logger wrapping a Monolog instance.
     */
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        $channel = $monolog->getName();
        $skipRedaction = (bool) config("logging.channels.{$channel}.skip_redaction", false);

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
