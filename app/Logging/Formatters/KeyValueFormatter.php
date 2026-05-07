<?php

namespace App\Logging\Formatters;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Renders log records as a single line of '[ts] channel.LEVEL: message k=v k="v with space"'.
 *
 * Rules:
 *   - keys with no whitespace/quote/equals: bare value          (slug=abc)
 *   - keys with whitespace/quotes/equals : double-quoted        (error="not found")
 *   - inner quotes are backslash-escaped
 *   - arrays/objects: inline json                               (diff={"a":1})
 *   - null values are omitted entirely
 *   - context and extra are merged (context takes priority on key collision)
 */
final class KeyValueFormatter implements FormatterInterface
{
    /** @inheritDoc */
    public function format(LogRecord $record): string
    {
        $ts      = $record->datetime->format('Y-m-d H:i:s');
        $level   = $record->level->getName();
        $channel = $record->channel;
        $message = $record->message;

        // Merge extra and context, then add message as 'event' key
        $data = array_merge($record->extra, $record->context);
        $data['event'] = $message;

        $pairs = $this->serialize($data);

        $line = sprintf('[%s] %s.%s: %s', $ts, $channel, $level, $message);
        if ($pairs !== '') {
            $line .= ' '.$pairs;
        }

        return $line."\n";
    }

    /** @inheritDoc */
    public function formatBatch(array $records): string
    {
        return implode('', array_map(fn (LogRecord $r) => $this->format($r), $records));
    }

    /**
     * Convert an associative array into 'k=v k="v with space"' tokens.
     *
     * @param  array<string,mixed>  $data
     */
    private function serialize(array $data): string
    {
        $tokens = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $tokens[] = $key.'='.$this->encode($value);
        }
        return implode(' ', $tokens);
    }

    /**
     * Encode a single value for the key=value line.
     */
    private function encode(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $str = (string) $value;

        if (preg_match('/[\s"=]/', $str) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $str).'"';
        }

        return $str;
    }
}
