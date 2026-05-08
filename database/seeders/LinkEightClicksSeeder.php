<?php

namespace Database\Seeders;

use App\Models\Click;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LinkEightClicksSeeder extends Seeder
{
    use ClickEnrichmentTrait;

    /**
     * Seeder para o Link ID 8 - Tutorial YouTube
     * Foco: Mobile-first, forte BR, tráfego via YouTube e social
     */
    private array $countries = [
        ['name' => 'Brazil', 'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'continent' => 'SA'],
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'continent' => 'NA'],
        ['name' => 'Mexico', 'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'continent' => 'NA'],
        ['name' => 'Argentina', 'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
        ['name' => 'Colombia', 'iso' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'continent' => 'SA'],
        ['name' => 'Portugal', 'iso' => 'PT', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon', 'continent' => 'EU'],
        ['name' => 'Chile', 'iso' => 'CL', 'currency' => 'CLP', 'timezone' => 'America/Santiago', 'continent' => 'SA'],
        ['name' => 'Spain', 'iso' => 'ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid', 'continent' => 'EU'],
    ];

    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Fortaleza', 'state' => 'CE', 'state_name' => 'Ceará', 'lat' => -3.7319, 'lng' => -38.5267, 'postal' => '60020-000'],
            ['city' => 'Salvador', 'state' => 'BA', 'state_name' => 'Bahia', 'lat' => -12.9714, 'lng' => -38.5014, 'postal' => '40020-010'],
            ['city' => 'Manaus', 'state' => 'AM', 'state_name' => 'Amazonas', 'lat' => -3.1190, 'lng' => -60.0217, 'postal' => '69005-141'],
        ],
        'US' => [
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California', 'lat' => 34.0522, 'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
        ],
        'MX' => [
            ['city' => 'Cidade do México', 'state' => 'CDMX', 'state_name' => 'Cidade do México', 'lat' => 19.4326, 'lng' => -99.1332, 'postal' => '06600'],
            ['city' => 'Guadalajara', 'state' => 'JAL', 'state_name' => 'Jalisco', 'lat' => 20.6597, 'lng' => -103.3496, 'postal' => '44100'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'mobile' => 80,
        'desktop' => 15,
        'tablet' => 5,
    ];

    private array $userAgents = [
        'mobile' => [
            'Mozilla/5.0 (Linux; Android 14; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 12; Redmi Note 11) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Mobile Safari/537.36',
        ],
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ],
    ];

    private array $referrers = [
        'youtube' => [
            'https://www.youtube.com/',
            'https://youtu.be/',
            'https://m.youtube.com/',
        ],
        'social' => [
            'https://www.instagram.com/',
            'https://www.tiktok.com/',
            'https://twitter.com/',
            'https://wa.me/',
        ],
        'direct' => [null, '-', ''],
        'search' => [
            'https://www.google.com/search?q=tutorial',
            'https://www.google.com/search?q=como+fazer',
        ],
    ];

    public function run(): void
    {
        Click::where('link_id', 8)->delete();

        $this->command->info('🚀 Criando Link ID 8 (Tutorial YouTube)...');

        $clicks = [];
        $totalClicks = 5800;
        $batchSize = 500;

        // Período: últimos 60 dias (crescimento gradual após publicação)
        $startDate = Carbon::now()->subDays(60);
        $endDate = Carbon::now();

        $this->command->info("📅 Período: {$startDate->format('d/m/Y')} até {$endDate->format('d/m/Y')}");
        $this->command->info("🎯 Total de clicks: {$totalClicks}");

        for ($i = 0; $i < $totalClicks; $i++) {
            $clickDate = $this->generateRandomDate($startDate, $endDate);
            $country = $this->selectCountryByWeight();
            $cityData = $this->getCityData($country['iso']);
            $device = $this->selectDeviceByWeight();
            $ip = $this->generateRealisticIP($country['iso']);
            $userAgent = $this->getUserAgent($device);
            $referer = $this->getReferer();

            $clicks[] = array_merge([
                'link_id' => 8,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'referer' => $referer,
                'country' => $country['name'],
                'iso_code' => $country['iso'],
                'state' => $cityData['state'],
                'state_name' => $cityData['state_name'],
                'city' => $cityData['city'],
                'postal_code' => $cityData['postal'],
                'latitude' => $cityData['lat'],
                'longitude' => $cityData['lng'],
                'timezone' => $country['timezone'],
                'continent' => $country['continent'],
                'currency' => $country['currency'],
                'device' => $device,
                'created_at' => $clickDate,
                'updated_at' => $clickDate,
            ], $this->enrichClickData($userAgent, $device, $referer, $clickDate, $country['iso']));

            if (count($clicks) >= $batchSize) {
                Click::insert($clicks);
                $clicks = [];
                $progress = round((($i + 1) / $totalClicks) * 100, 1);
                $this->command->info("✅ {$progress}% ({($i+1)}/{$totalClicks})");
            }
        }

        if (count($clicks) > 0) {
            Click::insert($clicks);
        }

        $this->command->info("🎉 {$totalClicks} clicks criados para Link ID 8!");
    }

    private function generateRandomDate(Carbon $start, Carbon $end): Carbon
    {
        $timestamp = mt_rand($start->timestamp, $end->timestamp);
        $hour = $this->getRealisticHour();

        return Carbon::createFromTimestamp($timestamp)
            ->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
    }

    private function getRealisticHour(): int
    {
        // YouTube: pico à tarde e noite
        $hourWeights = [
            0 => 3, 1 => 2, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
            6 => 3, 7 => 5, 8 => 7, 9 => 9, 10 => 11, 11 => 12,
            12 => 14, 13 => 16, 14 => 17, 15 => 18, 16 => 20,
            17 => 22, 18 => 24, 19 => 23, 20 => 22, 21 => 20,
            22 => 15, 23 => 10,
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        $weights = [
            0 => 55,  // BR - 55%
            1 => 15,  // US - 15%
            2 => 10,  // MX - 10%
            3 => 8,   // AR - 8%
            4 => 5,   // CO - 5%
            5 => 4,   // PT - 4%
            6 => 2,   // CL - 2%
            7 => 1,   // ES - 1%
        ];

        return $this->countries[$this->weightedRandom($weights)];
    }

    private function getCityData(string $countryCode): array
    {
        $cities = $this->cities[$countryCode] ?? $this->cities['DEFAULT'];

        return $cities[array_rand($cities)];
    }

    private function selectDeviceByWeight(): string
    {
        return $this->weightedRandom($this->devices);
    }

    private function getUserAgent(string $device): string
    {
        $agents = $this->userAgents[$device] ?? $this->userAgents['desktop'];

        return $agents[array_rand($agents)];
    }

    private function getReferer(): ?string
    {
        // YouTube: principalmente da plataforma e social
        $types = ['youtube' => 50, 'social' => 25, 'direct' => 15, 'search' => 10];
        $type = $this->weightedRandom($types);

        $referrers = $this->referrers[$type];
        $referer = $referrers[array_rand($referrers)];

        return empty($referer) ? null : $referer;
    }

    private function generateRealisticIP(string $countryCode): string
    {
        $ipRanges = [
            'BR' => ['200.160.', '189.85.', '177.67.', '191.36.', '186.202.'],
            'US' => ['173.252.', '199.16.', '204.15.', '69.171.', '157.240.'],
            'MX' => ['189.203.', '187.141.', '201.144.', '200.77.'],
            'AR' => ['190.210.', '181.47.', '200.115.', '186.33.'],
            'CO' => ['190.27.', '181.48.', '200.24.', '186.31.'],
            'PT' => ['85.241.', '194.65.', '213.28.', '195.23.'],
            'CL' => ['190.98.', '200.104.', '181.72.', '190.163.'],
            'ES' => ['83.36.', '88.27.', '95.16.', '213.97.'],
            'DEFAULT' => ['192.168.', '10.0.', '172.16.'],
        ];

        $ranges = $ipRanges[$countryCode] ?? $ipRanges['DEFAULT'];
        $prefix = $ranges[array_rand($ranges)];

        return $prefix.mt_rand(1, 254).'.'.mt_rand(1, 254);
    }

    private function weightedRandom(array $weights)
    {
        $total = array_sum($weights);
        $random = mt_rand(1, $total);

        $current = 0;
        foreach ($weights as $key => $weight) {
            $current += $weight;
            if ($random <= $current) {
                return $key;
            }
        }

        return array_key_first($weights);
    }
}
