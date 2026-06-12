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
 *
 * Analytics impact: unique-visitor metrics use DISTINCT(ip), so rows older
 * than the retention window merge per /24 (IPv4) or /48 (IPv6) after the
 * sweep — all-time unique counts and retention insights become approximate
 * for pre-window data. Accepted LGPD trade-off.
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
     * Mask an IP for anonymization: zero the last IPv4 octet, or zero
     * everything past the /48 prefix for IPv6. Works on the packed binary
     * form, so compressed IPv6 (the canonical form PostgreSQL returns from
     * inet columns) always yields a valid canonical address. Unparseable
     * values collapse to 0.0.0.0.
     */
    public static function maskIp(?string $ip): string
    {
        $packed = $ip ? @inet_pton($ip) : false;

        if ($packed === false) {
            return '0.0.0.0';
        }

        if (strlen($packed) === 4) {
            $packed[3] = "\x00";

            return inet_ntop($packed);
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\x00", 10));
    }
}
