<?php

namespace Database\Seeders;

use App\Models\Click;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LinkNineClicksSeeder extends Seeder
{
    use ClickEnrichmentTrait;
    /**
     * Seeder para o Link ID 9 - Post LinkedIn (Case de Sucesso)
     * Foco: Desktop dominante, público profissional global, tráfego orgânico LinkedIn
     */
    private array $countries = [
        ['name' => 'Brazil', 'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'continent' => 'SA'],
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'continent' => 'NA'],
        ['name' => 'United Kingdom', 'iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'continent' => 'EU'],
        ['name' => 'Germany', 'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'continent' => 'EU'],
        ['name' => 'Canada', 'iso' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'continent' => 'NA'],
        ['name' => 'Portugal', 'iso' => 'PT', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon', 'continent' => 'EU'],
        ['name' => 'France', 'iso' => 'FR', 'currency' => 'EUR', 'timezone' => 'Europe/Paris', 'continent' => 'EU'],
        ['name' => 'Netherlands', 'iso' => 'NL', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'continent' => 'EU'],
        ['name' => 'Australia', 'iso' => 'AU', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney', 'continent' => 'OC'],
        ['name' => 'India', 'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'continent' => 'AS'],
    ];

    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Curitiba', 'state' => 'PR', 'state_name' => 'Paraná', 'lat' => -25.4289, 'lng' => -49.2671, 'postal' => '80000-000'],
        ],
        'US' => [
            ['city' => 'San Francisco', 'state' => 'CA', 'state_name' => 'California', 'lat' => 37.7749, 'lng' => -122.4194, 'postal' => '94101'],
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Chicago', 'state' => 'IL', 'state_name' => 'Illinois', 'lat' => 41.8781, 'lng' => -87.6298, 'postal' => '60601'],
        ],
        'GB' => [
            ['city' => 'London', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 51.5074, 'lng' => -0.1278, 'postal' => 'SW1A 1AA'],
        ],
        'DE' => [
            ['city' => 'Berlin', 'state' => 'BE', 'state_name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050, 'postal' => '10115'],
            ['city' => 'Frankfurt', 'state' => 'HE', 'state_name' => 'Hesse', 'lat' => 50.1109, 'lng' => 8.6821, 'postal' => '60311'],
        ],
        'IN' => [
            ['city' => 'Bangalore', 'state' => 'KA', 'state_name' => 'Karnataka', 'lat' => 12.9716, 'lng' => 77.5946, 'postal' => '560001'],
            ['city' => 'Mumbai', 'state' => 'MH', 'state_name' => 'Maharashtra', 'lat' => 19.0760, 'lng' => 72.8777, 'postal' => '400001'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'desktop' => 75,
        'mobile' => 20,
        'tablet' => 5,
    ];

    private array $userAgents = [
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ],
        'mobile' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 14; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ],
    ];

    private array $referrers = [
        'linkedin' => [
            'https://www.linkedin.com/',
            'https://lnkd.in/',
            'https://linkedin.com/feed/',
            'https://www.linkedin.com/posts/',
        ],
        'direct' => [null, '-', ''],
        'email' => [
            'https://mail.google.com/',
            'https://outlook.live.com/',
        ],
        'search' => [
            'https://www.google.com/search?q=case+de+sucesso',
            'https://www.google.com/search?q=link+shortener+analytics',
        ],
    ];

    public function run(): void
    {
        Click::where('link_id', 9)->delete();

        $this->command->info('🚀 Criando Link ID 9 (LinkedIn - Case de Sucesso)...');

        $clicks = [];
        $totalClicks = 980;
        $batchSize = 500;

        // Período: últimos 14 dias (viral recente, pico nos primeiros dias)
        $startDate = Carbon::now()->subDays(14);
        $endDate = Carbon::now()->subDay();

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
                'link_id' => 9,
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

        $this->command->info("🎉 {$totalClicks} clicks criados para Link ID 9!");
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
        // LinkedIn: horário comercial (9h–18h), com pico no meio da manhã
        $hourWeights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
            6 => 3, 7 => 5, 8 => 10, 9 => 18, 10 => 22, 11 => 20,
            12 => 16, 13 => 18, 14 => 20, 15 => 19, 16 => 17,
            17 => 14, 18 => 10, 19 => 7, 20 => 5, 21 => 4,
            22 => 3, 23 => 2,
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        $weights = [
            0 => 35,  // BR - 35%
            1 => 20,  // US - 20%
            2 => 12,  // GB - 12%
            3 => 10,  // DE - 10%
            4 => 7,   // CA - 7%
            5 => 6,   // PT - 6%
            6 => 4,   // FR - 4%
            7 => 2,   // NL - 2%
            8 => 2,   // AU - 2%
            9 => 2,   // IN - 2%
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
        // LinkedIn: tráfego quase todo da própria plataforma
        $types = ['linkedin' => 70, 'direct' => 15, 'email' => 10, 'search' => 5];
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
            'GB' => ['81.2.', '86.1.', '109.144.', '151.224.', '82.0.'],
            'DE' => ['85.25.', '91.65.', '178.25.', '188.174.', '217.80.'],
            'CA' => ['142.11.', '192.99.', '198.50.', '162.243.', '159.203.'],
            'PT' => ['85.241.', '194.65.', '213.28.', '195.23.'],
            'FR' => ['78.192.', '90.84.', '176.31.', '195.154.', '37.59.'],
            'NL' => ['145.97.', '85.144.', '194.109.', '217.123.'],
            'AU' => ['203.208.', '220.101.', '139.130.', '203.59.'],
            'IN' => ['103.21.', '103.22.', '117.239.', '106.51.', '152.57.'],
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
