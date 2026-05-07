<?php

namespace App\Services\Links;

use App\Logging\AppLogger;
use Illuminate\Support\Facades\Http;

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
