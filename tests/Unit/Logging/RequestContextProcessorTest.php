<?php

namespace Tests\Unit\Logging;

use App\Logging\Context\RequestContext;
use App\Logging\Processors\RequestContextProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class RequestContextProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clear();
    }

    private function rec(): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: [],
            extra: [],
        );
    }

    public function test_injects_fields_from_active_context(): void
    {
        RequestContext::set(new RequestContext('req_xy', userId: 7, ip: '1.2.3.4', route: 'link.redirect'));
        $p = new RequestContextProcessor;

        $out = $p($this->rec());

        $this->assertSame('req_xy', $out->extra['request_id']);
        $this->assertSame(7, $out->extra['user_id']);
        $this->assertSame('1.2.3.4', $out->extra['ip']);
        $this->assertSame('link.redirect', $out->extra['route']);
        $this->assertSame('testing', $out->extra['env']);
    }

    public function test_omits_request_id_when_no_active_context(): void
    {
        $p = new RequestContextProcessor;

        $out = $p($this->rec());

        $this->assertArrayNotHasKey('request_id', $out->extra);
        $this->assertArrayNotHasKey('user_id', $out->extra);
        $this->assertSame('testing', $out->extra['env']);
    }

    public function test_does_not_overwrite_existing_extra_fields(): void
    {
        RequestContext::set(new RequestContext('req_xy'));
        $p = new RequestContextProcessor;

        $rec = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: [],
            extra: ['request_id' => 'req_existing'],
        );

        $out = $p($rec);

        $this->assertSame('req_existing', $out->extra['request_id']);
    }
}
