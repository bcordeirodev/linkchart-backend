<?php

namespace Tests\Unit\Logging;

use App\Logging\Processors\PiiRedactionProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class PiiRedactionProcessorTest extends TestCase
{
    private function rec(array $context, array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: $context,
            extra: $extra,
        );
    }

    public function test_redacts_sensitive_keys(): void
    {
        $p = new PiiRedactionProcessor();

        $out = $p($this->rec(['password' => 'hunter2', 'token' => 'jwt.xxx', 'safe' => 'ok']));

        $this->assertSame('[REDACTED]', $out->context['password']);
        $this->assertSame('[REDACTED]', $out->context['token']);
        $this->assertSame('ok', $out->context['safe']);
    }

    public function test_masks_email_partially(): void
    {
        $p = new PiiRedactionProcessor();

        $out = $p($this->rec(['email' => 'bcordeiro@example.com']));

        $this->assertSame('b***@example.com', $out->context['email']);
    }

    public function test_masks_ipv4(): void
    {
        $p = new PiiRedactionProcessor();

        $out = $p($this->rec(['ip' => '187.10.50.20']));

        $this->assertSame('187.10.x.x', $out->context['ip']);
    }

    public function test_recurses_into_nested_arrays(): void
    {
        $p = new PiiRedactionProcessor();

        $out = $p($this->rec(['payload' => ['password' => 'secret', 'email' => 'a@b.com']]));

        $this->assertSame('[REDACTED]', $out->context['payload']['password']);
        $this->assertSame('a***@b.com', $out->context['payload']['email']);
    }

    public function test_processes_extra_field_too(): void
    {
        $p = new PiiRedactionProcessor();

        $out = $p($this->rec(context: [], extra: ['ip' => '1.2.3.4', 'request_id' => 'req_x']));

        $this->assertSame('1.2.x.x', $out->extra['ip']);
        $this->assertSame('req_x', $out->extra['request_id']);
    }

    public function test_keeps_non_string_values_intact(): void
    {
        $p = new PiiRedactionProcessor();

        $out = $p($this->rec(['link_id' => 42, 'count' => 0]));

        $this->assertSame(42, $out->context['link_id']);
        $this->assertSame(0, $out->context['count']);
    }
}
