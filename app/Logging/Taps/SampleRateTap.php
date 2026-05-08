<?php

namespace App\Logging\Taps;

use Illuminate\Log\Logger;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Applies probabilistic sampling to INFO/DEBUG records on a channel.
 *
 * Reads `logging.channels.redirect_file.sample_rate` (0.0 to 1.0). When the
 * rate is < 1.0, each handler is wrapped with a custom filter handler that
 * drops INFO/DEBUG records with probability (1 - rate). Records at WARNING
 * and above are always kept regardless of rate.
 *
 * Used only on the redirect_file channel today, but generic enough to be
 * attached anywhere.
 */
final class SampleRateTap
{
    /**
     * Wrap each handler with a sampling filter wrapper.
     *
     * @param  Logger  $logger  Illuminate logger wrapping a Monolog instance.
     */
    public function __invoke(Logger $logger): void
    {
        $rate = (float) config('logging.channels.redirect_file.sample_rate', 1.0);
        if ($rate >= 1.0) {
            return;
        }

        $monolog = $logger->getLogger();
        $wrappedHandlers = [];

        foreach ($monolog->getHandlers() as $handler) {
            $wrappedHandlers[] = new SamplingWrapper($handler, $rate);
        }

        $monolog->setHandlers($wrappedHandlers);
    }
}

/**
 * Internal wrapper handler that applies probabilistic filtering.
 *
 * @internal
 */
final class SamplingWrapper implements HandlerInterface
{
    public function __construct(
        private readonly HandlerInterface $wrapped,
        private readonly float $rate,
    ) {}

    public function isHandling(LogRecord $record): bool
    {
        return $this->wrapped->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if ($record->level->value >= Level::Warning->value) {
            return $this->wrapped->handle($record);
        }

        if (mt_rand() / mt_getrandmax() >= $this->rate) {
            return false;
        }

        return $this->wrapped->handle($record);
    }

    public function handleBatch(array $records): void
    {
        $filtered = [];
        foreach ($records as $record) {
            if ($record->level->value >= Level::Warning->value) {
                $filtered[] = $record;
            } elseif (mt_rand() / mt_getrandmax() < $this->rate) {
                $filtered[] = $record;
            }
        }

        if (! empty($filtered)) {
            $this->wrapped->handleBatch($filtered);
        }
    }

    public function close(): void
    {
        $this->wrapped->close();
    }

    public function reset(): void
    {
        if (method_exists($this->wrapped, 'reset')) {
            $this->wrapped->reset();
        }
    }
}
