<?php

namespace Tests\Unit\Logging;

use App\Logging\Taps\SampleRateTap;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

class SampleRateTapTest extends TestCase
{
    private function buildLogger(): array
    {
        $handler = new TestHandler;
        $monolog = new MonologLogger('test', [$handler]);
        $logger = new IlluminateLogger($monolog);

        return [$logger, $handler];
    }

    public function test_rate_zero_drops_info_records(): void
    {
        [$logger, $handler] = $this->buildLogger();
        config(['logging.channels.redirect_file.sample_rate' => 0.0]);

        (new SampleRateTap)($logger);

        $logger->info('redirect.started', ['slug' => 'abc']);

        $this->assertCount(0, $handler->getRecords());
    }

    public function test_rate_zero_keeps_warning_and_error(): void
    {
        [$logger, $handler] = $this->buildLogger();
        config(['logging.channels.redirect_file.sample_rate' => 0.0]);

        (new SampleRateTap)($logger);

        $logger->warning('redirect.error', []);
        $logger->error('redirect.crash', []);

        $this->assertCount(2, $handler->getRecords());
    }

    public function test_rate_one_keeps_everything(): void
    {
        [$logger, $handler] = $this->buildLogger();
        config(['logging.channels.redirect_file.sample_rate' => 1.0]);

        (new SampleRateTap)($logger);

        $logger->info('redirect.started');
        $logger->info('redirect.dispatched');

        $this->assertCount(2, $handler->getRecords());
    }

    public function test_default_when_no_config_keeps_everything(): void
    {
        [$logger, $handler] = $this->buildLogger();

        (new SampleRateTap)($logger);

        $logger->info('redirect.started');

        $this->assertCount(1, $handler->getRecords());
    }
}
