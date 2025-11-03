<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;

class LinkFiveClicksSeeder extends Seeder
{
    /**
     * Seeder para o Link ID 5 - E-commerce Campaign
     * Foco: Tráfego principalmente mobile, alta concentração BR/US
     */
    private array $countries = [
        ['name' => 'Brazil', 'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'continent' => 'SA'],
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'continent' => 'NA'],
        ['name' => 'Mexico', 'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'continent' => 'NA'],
        ['name' => 'Argentina', 'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
        ['name' => 'Colombia', 'iso' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'continent' => 'SA'],
        ['name' => 'Chile', 'iso' => 'CL', 'currency' => 'CLP', 'timezone' => 'America/Santiago', 'continent' => 'SA'],
        ['name' => 'Peru', 'iso' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'continent' => 'SA'],
        ['name' => 'Canada', 'iso' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'continent' => 'NA'],
    ];

    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília', 'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Belo Horizonte', 'state' => 'MG', 'state_name' => 'Minas Gerais', 'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30000-000'],
            ['city' => 'Curitiba', 'state' => 'PR', 'state_name' => 'Paraná', 'lat' => -25.4289, 'lng' => -49.2671, 'postal' => '80000-000'],
        ],
        'US' => [
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California', 'lat' => 34.0522, 'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Miami', 'state' => 'FL', 'state_name' => 'Florida', 'lat' => 25.7617, 'lng' => -80.1918, 'postal' => '33101'],
            ['city' => 'Chicago', 'state' => 'IL', 'state_name' => 'Illinois', 'lat' => 41.8781, 'lng' => -87.6298, 'postal' => '60601'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'mobile' => 72,     // 72% mobile (e-commerce mobile first)
        'desktop' => 22,    // 22% desktop
        'tablet' => 6,      // 6% tablet
    ];

    private array $userAgents = [
        'mobile' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 14; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36',
        ],
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        ],
    ];

    private array $referrers = [
        'social' => [
            'https://www.instagram.com/',
            'https://www.tiktok.com/',
            'https://www.facebook.com/',
            'https://wa.me/',
        ],
        'direct' => [null, '-', ''],
        'ads' => [
            'https://ads.google.com/',
            'https://www.facebook.com/ads/',
            'https://ads.instagram.com/',
        ],
    ];

    public function run(): void
    {
        $this->command->info('🚀 Criando Link ID 5 (E-commerce Campaign)...');

        $clicks = [];
        $totalClicks = 3850; // Alto volume de clicks
        $batchSize = 500;

        // Período: últimos 45 dias
        $startDate = Carbon::now()->subDays(45);
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

            $clicks[] = [
                'link_id' => 5,
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
            ];

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

        $this->command->info("🎉 {$totalClicks} clicks criados para Link ID 5!");
    }

    private function generateRandomDate(Carbon $start, Carbon $end): Carbon
    {
        $timestamp = mt_rand($start->timestamp, $end->timestamp);
        $hour = $this->getRealisticHour();
        $minute = mt_rand(0, 59);
        $second = mt_rand(0, 59);

        return Carbon::createFromTimestamp($timestamp)
            ->setTime($hour, $minute, $second);
    }

    private function getRealisticHour(): int
    {
        // E-commerce: picos à tarde/noite
        $hourWeights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
            6 => 3, 7 => 5, 8 => 8, 9 => 11, 10 => 14, 11 => 16,
            12 => 18, 13 => 20, 14 => 22, 15 => 24, 16 => 23,
            17 => 22, 18 => 21, 19 => 20, 20 => 18, 21 => 16,
            22 => 12, 23 => 8
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        // Forte concentração BR/US para e-commerce
        $weights = [
            0 => 45,  // BR - 45%
            1 => 30,  // US - 30%
            2 => 10,  // MX - 10%
            3 => 5,   // AR - 5%
            4 => 4,   // CO - 4%
            5 => 3,   // CL - 3%
            6 => 2,   // PE - 2%
            7 => 1,   // CA - 1%
        ];

        $index = $this->weightedRandom($weights);
        return $this->countries[$index];
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
        // E-commerce: muito tráfego de social/ads
        $types = ['social' => 45, 'ads' => 30, 'direct' => 25];
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
            'CL' => ['190.98.', '200.104.', '181.72.', '190.163.'],
            'PE' => ['190.234.', '200.37.', '181.65.', '190.235.'],
            'CA' => ['142.11.', '192.99.', '198.50.', '162.243.'],
            'DEFAULT' => ['192.168.', '10.0.', '172.16.'],
        ];

        $ranges = $ipRanges[$countryCode] ?? $ipRanges['DEFAULT'];
        $prefix = $ranges[array_rand($ranges)];

        return $prefix . mt_rand(1, 254) . '.' . mt_rand(1, 254);
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

