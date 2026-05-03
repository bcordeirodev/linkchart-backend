<?php

namespace App\Services\Links;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        'MALWARE'                        => 'malware',
        'SOCIAL_ENGINEERING'             => 'phishing',
        'UNWANTED_SOFTWARE'              => 'software indesejado',
        'POTENTIALLY_HARMFUL_APPLICATION'=> 'aplicação prejudicial',
    ];

    public function checkUrl(string $url): array
    {
        $apiKey = config('services.google_safe_browsing.key');

        if (empty($apiKey)) {
            Log::warning('LinkSafetyService: GOOGLE_SAFE_BROWSING_KEY não configurada — verificação ignorada.');
            return ['safe' => true, 'threats' => [], 'api_available' => false];
        }

        try {
            $response = Http::timeout(5)->post(self::API_URL . '?key=' . $apiKey, [
                'client' => [
                    'clientId'      => 'link-charts',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes'      => self::THREAT_TYPES,
                    'platformTypes'    => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries'    => [['url' => $url]],
                ],
            ]);

            if ($response->failed()) {
                Log::error('LinkSafetyService: erro na API', [
                    'status' => $response->status(),
                    'url'    => $url,
                ]);
                return ['safe' => true, 'threats' => [], 'api_available' => false];
            }

            $matches = $response->json('matches', []);

            if (empty($matches)) {
                return ['safe' => true, 'threats' => [], 'api_available' => true];
            }

            $threats = array_values(array_unique(array_map(
                fn($m) => self::THREAT_LABELS[$m['threatType']] ?? strtolower($m['threatType']),
                $matches
            )));

            return ['safe' => false, 'threats' => $threats, 'api_available' => true];
        } catch (\Throwable $e) {
            Log::error('LinkSafetyService: exceção ao verificar URL', [
                'message' => $e->getMessage(),
                'url'     => $url,
            ]);
            return ['safe' => true, 'threats' => [], 'api_available' => false];
        }
    }
}
