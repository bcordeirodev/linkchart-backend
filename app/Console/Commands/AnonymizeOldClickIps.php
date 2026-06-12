<?php

namespace App\Console\Commands;

use App\Logging\AppLogger;
use App\Models\Click;
use Illuminate\Console\Command;

/**
 * LGPD retention sweep: masks click IPs older than the configured window.
 *
 * IPv4 addresses get their last octet zeroed (187.10.55.42 → 187.10.55.0);
 * IPv6 addresses are truncated to their /48 prefix (first 3 hextets). Rows are
 * flagged via ip_anonymized so the daily sweep never rescans them. Recent
 * clicks keep full IPs for antifraud (quality score, session analysis).
 *
 * Scheduled daily in bootstrap/app.php; window configured by
 * tracking.ip_retention_days (env TRACKING_IP_RETENTION_DAYS, default 90).
 */
class AnonymizeOldClickIps extends Command
{
    protected $signature = 'clicks:anonymize-ips
        {--days= : Override the retention window in days (default: config tracking.ip_retention_days)}
        {--chunk=1000 : Rows processed per chunk}';

    protected $description = 'Mask click IPs older than the LGPD retention window';

    /**
     * Sweep clicks older than the cutoff and anonymize their IPs in chunks.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('tracking.ip_retention_days', 90));
        $cutoff = now()->subDays($days);
        $total = 0;

        Click::query()
            ->where('ip_anonymized', false)
            ->where('created_at', '<', $cutoff)
            ->chunkById((int) $this->option('chunk'), function ($clicks) use (&$total) {
                foreach ($clicks as $click) {
                    $click->timestamps = false;
                    $click->forceFill([
                        'ip' => self::maskIp($click->ip),
                        'ip_anonymized' => true,
                    ])->saveQuietly();
                    $total++;
                }
            });

        AppLogger::event('app', 'info', 'privacy.clicks_ip_anonymized', [
            'count' => $total,
            'retention_days' => $days,
            'cutoff' => $cutoff->toDateTimeString(),
        ]);

        $this->info("Anonymized {$total} click IPs older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * Mask an IP for anonymization: zero the last IPv4 octet, or truncate an
     * IPv6 address to its /48 prefix. Unparseable values collapse to 0.0.0.0.
     */
    public static function maskIp(?string $ip): string
    {
        if (! $ip) {
            return '0.0.0.0';
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 3)).'::';
        }

        $parts = explode('.', $ip);

        if (count($parts) === 4) {
            $parts[3] = '0';

            return implode('.', $parts);
        }

        return '0.0.0.0';
    }
}
