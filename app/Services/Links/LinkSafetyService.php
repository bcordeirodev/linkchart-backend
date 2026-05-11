<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use Illuminate\Support\Facades\Http;

/**
 * Checks a URL against the Google Safe Browsing v4 API for known threats.
 *
 * Called during link creation/update to prevent malware, phishing, unwanted
 * software, and potentially harmful application URLs from being shortened.
 *
 * Configuration: GOOGLE_SAFE_BROWSING_KEY environment variable (read via
 * config/services.php key: google_safe_browsing.key). If the key is missing,
 * the check is skipped and the URL is treated as safe (fail-open).
 *
 * Side effects:
 *   - Outbound HTTP POST to Google Safe Browsing API (timeout 5s).
 *   - Logs via AppLogger::safetyApiUnavailable (key missing),
 *     AppLogger::safetyApiError (HTTP failure or exception),
 *     AppLogger::safetyUrlFlagged (threat detected) — channel: app.
 */
class LinkSafetyService
{
    private const API_URL = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    private const THREAT_TYPES = [
        'MALWARE',
        'SOCIAL_ENGINEERING',
        'UNWANTED_SOFTWARE',
        'POTENTIALLY_HARMFUL_APPLICATION',
    ];

    private const THREAT_LABELS = [
        'MALWARE' => 'malware',
        'SOCIAL_ENGINEERING' => 'phishing',
        'UNWANTED_SOFTWARE' => 'software indesejado',
        'POTENTIALLY_HARMFUL_APPLICATION' => 'aplicação prejudicial',
    ];

    /**
     * Checks a URL against the Google Safe Browsing v4 API.
     *
     * Threat types checked: MALWARE, SOCIAL_ENGINEERING (phishing),
     * UNWANTED_SOFTWARE, POTENTIALLY_HARMFUL_APPLICATION.
     *
     * Fails open (safe=true, api_available=false) if the API key is not
     * configured or if the API returns a non-2xx response or throws.
     *
     * @param  string  $url  The URL to check.
     * @return array{safe: bool, threats: string[], api_available: bool}
     */
    public function checkUrl(string $url): array
    {
        $apiKey = config('services.google_safe_browsing.key');

        if (empty($apiKey)) {
            AppLogger::safetyApiUnavailable('GOOGLE_SAFE_BROWSING_KEY missing');

            return ['safe' => true, 'threats' => [], 'api_available' => false];
        }

        try {
            $response = Http::timeout(5)->post(self::API_URL.'?key='.$apiKey, [
                'client' => [
                    'clientId' => 'link-charts',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes' => self::THREAT_TYPES,
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => [['url' => $url]],
                ],
            ]);

            if ($response->failed()) {
                AppLogger::safetyApiError(new \RuntimeException('safety_api_error_response'), $url);

                return ['safe' => true, 'threats' => [], 'api_available' => false];
            }

            $matches = $response->json('matches', []);

            if (empty($matches)) {
                return ['safe' => true, 'threats' => [], 'api_available' => true];
            }

            $threatTypes = array_map(fn ($m) => $m['threatType'] ?? 'unknown', $matches);
            AppLogger::safetyUrlFlagged($url, $threatTypes);

            $threats = array_values(array_unique(array_map(
                fn ($m) => self::THREAT_LABELS[$m['threatType']] ?? strtolower($m['threatType']),
                $matches
            )));

            return ['safe' => false, 'threats' => $threats, 'api_available' => true];
        } catch (\Throwable $e) {
            AppLogger::safetyApiError($e, $url);

            return ['safe' => true, 'threats' => [], 'api_available' => false];
        }
    }
}
