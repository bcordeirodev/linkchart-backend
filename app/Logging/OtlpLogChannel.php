<?php

namespace App\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OtlpHandler;

/**
 * Custom Monolog channel factory that forwards log records to the OpenTelemetry
 * global LoggerProvider via the OTel Monolog bridge handler.
 *
 * This factory is registered as a Laravel custom log channel using the 'custom'
 * driver and the 'via' key in config/logging.php. Laravel calls __invoke() with
 * the channel config array and expects a Monolog\Logger in return.
 *
 * Behaviour by config('otel.enabled'):
 *   - true  → builds an OtlpHandler from \OpenTelemetry\API\Globals::loggerProvider()
 *             so every log record is shipped to Grafana Cloud Loki via OTLP/HTTP.
 *   - false → attaches a NullHandler; zero records are exported, the test suite
 *             is fully isolated, and no OTel SDK is loaded.
 */
class OtlpLogChannel
{
    /**
     * Create and return a Monolog Logger for the 'otlp' channel.
     *
     * @param  array<string, mixed>  $config  Channel configuration from config/logging.php.
     */
    public function __invoke(array $config): Logger
    {
        // Monolog 3 handlers normalize the level themselves (int|string|Level),
        // so the config string 'error' and the enum default are both accepted.
        $level = $config['level'] ?? \Monolog\Level::Error;
        $logger = new Logger('otlp');

        if (config('otel.enabled')) {
            try {
                $handler = new OtlpHandler(Globals::loggerProvider(), $level);
            } catch (\Throwable) {
                // Defence in depth: a channel-construction failure must never
                // break logging. Fall back to discarding records.
                $handler = new NullHandler($level);
            }
        } else {
            $handler = new NullHandler($level);
        }

        $logger->pushHandler($handler);

        return $logger;
    }
}
