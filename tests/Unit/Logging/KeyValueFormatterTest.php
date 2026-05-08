<?php

namespace Tests\Unit\Logging;

use App\Logging\Formatters\KeyValueFormatter;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class KeyValueFormatterTest extends TestCase
{
    private function record(string $message, array $context = [], array $extra = [], Level $level = Level::Info): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable('2026-05-07T14:23:01+00:00'),
            channel: 'redirect',
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function test_formats_simple_key_value_pairs(): void
    {
        $f = new KeyValueFormatter;

        $line = $f->format($this->record('redirect.started', ['slug' => 'abc', 'link_id' => 42]));

        $this->assertStringContainsString('event=redirect.started', $line);
        $this->assertStringContainsString('slug=abc', $line);
        $this->assertStringContainsString('link_id=42', $line);
        $this->assertStringEndsWith("\n", $line);
    }

    public function test_quotes_strings_with_spaces(): void
    {
        $f = new KeyValueFormatter;

        $line = $f->format($this->record('og.fetch_failed', ['error' => 'connection refused']));

        $this->assertStringContainsString('error="connection refused"', $line);
    }

    public function test_escapes_inner_quotes(): void
    {
        $f = new KeyValueFormatter;

        $line = $f->format($this->record('test.event', ['msg' => 'he said "hi"']));

        $this->assertStringContainsString('msg="he said \\"hi\\""', $line);
    }

    public function test_serializes_arrays_as_inline_json(): void
    {
        $f = new KeyValueFormatter;

        $line = $f->format($this->record('audit.link_change', ['diff' => ['title' => 'X']]));

        $this->assertStringContainsString('diff={"title":"X"}', $line);
    }

    public function test_omits_null_values(): void
    {
        $f = new KeyValueFormatter;

        $line = $f->format($this->record('redirect.started', ['slug' => 'abc', 'user_id' => null]));

        $this->assertStringContainsString('slug=abc', $line);
        $this->assertStringNotContainsString('user_id=', $line);
    }

    public function test_includes_timestamp_level_and_channel(): void
    {
        $f = new KeyValueFormatter;

        $line = $f->format($this->record('redirect.started'));

        $this->assertStringContainsString('2026-05-07', $line);
        $this->assertStringContainsString('INFO', $line);
        $this->assertStringContainsString('redirect.started', $line);
    }

    public function test_emits_extra_fields(): void
    {
        $f = new KeyValueFormatter;

        $line = $f->format($this->record('redirect.started', ['slug' => 'abc'], ['request_id' => 'req_x12']));

        $this->assertStringContainsString('request_id=req_x12', $line);
    }
}
