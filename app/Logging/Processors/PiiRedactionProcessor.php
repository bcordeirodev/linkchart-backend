<?php

namespace App\Logging\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Centralised PII redaction applied to context+extra of every log record.
 *
 * - Sensitive keys are replaced with '[REDACTED]' regardless of value.
 * - PII keys (email, ip) are masked partially so the log keeps debug value
 *
 *   ('b***@example.com', '187.10.x.x') without leaking the full identifier.
 * - Recurses into nested arrays.
 *
 * Auth and audit channels intentionally do NOT include this processor in their
 * tap, so they keep the raw email/ip needed for incident response and
 * compliance (see ChannelTap).
 */
final class PiiRedactionProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'jwt',
        'access_token',
        'refresh_token',
        'api_key',
        'authorization',
        'secret',
        'credit_card',
        'cvv',
    ];

    /** {@inheritDoc} */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->redact($value);

                continue;
            }

            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $out[$key] = '[REDACTED]';

                continue;
            }

            if ($key === 'email' && is_string($value)) {
                $out[$key] = $this->maskEmail($value);

                continue;
            }

            if ($key === 'ip' && is_string($value)) {
                $out[$key] = $this->maskIp($value);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * 'bcordeiro@example.com' -> 'b***@example.com'
     */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '[REDACTED_EMAIL]';
        }

        return $email[0].'***'.substr($email, $at);
    }

    /**
     * '187.10.50.20' -> '187.10.x.x' (keeps geographic ASN region, hides host).
     */
    private function maskIp(string $ip): string
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '[REDACTED_IP]';
        }

        return $parts[0].'.'.$parts[1].'.x.x';
    }
}
