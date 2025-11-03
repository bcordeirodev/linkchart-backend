<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;

class LinkSixClicksSeeder extends Seeder
{
    /**
     * Seeder para o Link ID 6 - Tech Blog International
     * Foco: Tráfego global diversificado, principalmente desktop, tráfego orgânico
     */
    private array $countries = [
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'continent' => 'NA'],
        ['name' => 'United Kingdom', 'iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'continent' => 'EU'],
        ['name' => 'Germany', 'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'continent' => 'EU'],
        ['name' => 'India', 'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'continent' => 'AS'],
        ['name' => 'Canada', 'iso' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'continent' => 'NA'],
        ['name' => 'France', 'iso' => 'FR', 'currency' => 'EUR', 'timezone' => 'Europe/Paris', 'continent' => 'EU'],
        ['name' => 'Brazil', 'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'continent' => 'SA'],
        ['name' => 'Australia', 'iso' => 'AU', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney', 'continent' => 'OC'],
        ['name' => 'Netherlands', 'iso' => 'NL', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'continent' => 'EU'],
        ['name' => 'Spain', 'iso' => 'ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid', 'continent' => 'EU'],
        ['name' => 'Japan', 'iso' => 'JP', 'currency' => 'JPY', 'timezone' => 'Asia/Tokyo', 'continent' => 'AS'],
        ['name' => 'South Korea', 'iso' => 'KR', 'currency' => 'KRW', 'timezone' => 'Asia/Seoul', 'continent' => 'AS'],
        ['name' => 'Singapore', 'iso' => 'SG', 'currency' => 'SGD', 'timezone' => 'Asia/Singapore', 'continent' => 'AS'],
        ['name' => 'Sweden', 'iso' => 'SE', 'currency' => 'SEK', 'timezone' => 'Europe/Stockholm', 'continent' => 'EU'],
        ['name' => 'Switzerland', 'iso' => 'CH', 'currency' => 'CHF', 'timezone' => 'Europe/Zurich', 'continent' => 'EU'],
    ];

    private array $cities = [
        'US' => [
            ['city' => 'San Francisco', 'state' => 'CA', 'state_name' => 'California', 'lat' => 37.7749, 'lng' => -122.4194, 'postal' => '94101'],
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Seattle', 'state' => 'WA', 'state_name' => 'Washington', 'lat' => 47.6062, 'lng' => -122.3321, 'postal' => '98101'],
            ['city' => 'Austin', 'state' => 'TX', 'state_name' => 'Texas', 'lat' => 30.2672, 'lng' => -97.7431, 'postal' => '78701'],
        ],
        'GB' => [
            ['city' => 'London', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 51.5074, 'lng' => -0.1278, 'postal' => 'SW1A 1AA'],
            ['city' => 'Manchester', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 53.4808, 'lng' => -2.2426, 'postal' => 'M1 1AA'],
        ],
        'DE' => [
            ['city' => 'Berlin', 'state' => 'BE', 'state_name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050, 'postal' => '10115'],
            ['city' => 'Munich', 'state' => 'BY', 'state_name' => 'Bavaria', 'lat' => 48.1351, 'lng' => 11.5820, 'postal' => '80331'],
        ],
        'IN' => [
            ['city' => 'Bangalore', 'state' => 'KA', 'state_name' => 'Karnataka', 'lat' => 12.9716, 'lng' => 77.5946, 'postal' => '560001'],
            ['city' => 'Hyderabad', 'state' => 'TG', 'state_name' => 'Telangana', 'lat' => 17.3850, 'lng' => 78.4867, 'postal' => '500001'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'desktop' => 65,    // 65% desktop (tech blog readers)
        'mobile' => 28,     // 28% mobile
        'tablet' => 7,      // 7% tablet
    ];

    private array $userAgents = [
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ],
        'mobile' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        ],
    ];

    private array $referrers = [
        'search' => [
            'https://www.google.com/search?q=programming+tutorial',
            'https://www.google.com/search?q=web+development',
            'https://www.google.com/search?q=javascript+tips',
            'https://duckduckgo.com/?q=react+best+practices',
            'https://www.bing.com/search?q=devops+tools',
        ],
        'tech' => [
            'https://news.ycombinator.com/',
            'https://dev.to/',
            'https://stackoverflow.com/',
            'https://github.com/',
            'https://medium.com/',
        ],
        'direct' => [null, '-', ''],
        'social' => [
            'https://twitter.com/',
            'https://www.linkedin.com/',
            'https://www.reddit.com/r/programming/',
        ],
    ];

    public function run(): void
    {
        $this->command->info('🚀 Criando Link ID 6 (Tech Blog International)...');

        $clicks = [];
        $totalClicks = 4620; // Volume médio-alto
        $batchSize = 500;

        // Período: últimos 90 dias (crescimento gradual)
        $startDate = Carbon::now()->subDays(90);
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
                'link_id' => 6,
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

        $this->command->info("🎉 {$totalClicks} clicks criados para Link ID 6!");
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
        // Tech blog: horário comercial global
        $hourWeights = [
            0 => 2, 1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 3,
            6 => 5, 7 => 8, 8 => 12, 9 => 16, 10 => 19, 11 => 20,
            12 => 18, 13 => 19, 14 => 20, 15 => 19, 16 => 18,
            17 => 16, 18 => 14, 19 => 12, 20 => 10, 21 => 8,
            22 => 6, 23 => 4
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        // Distribuição global equilibrada (tech blog)
        $weights = [
            0 => 22,  // US - 22%
            1 => 15,  // GB - 15%
            2 => 12,  // DE - 12%
            3 => 10,  // IN - 10%
            4 => 9,   // CA - 9%
            5 => 8,   // FR - 8%
            6 => 6,   // BR - 6%
            7 => 5,   // AU - 5%
            8 => 4,   // NL - 4%
            9 => 3,   // ES - 3%
            10 => 2,  // JP - 2%
            11 => 2,  // KR - 2%
            12 => 1,  // SG - 1%
            13 => 1,  // SE - 1%
            14 => 1,  // CH - 1%
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
        // Tech blog: principalmente orgânico
        $types = ['search' => 50, 'tech' => 25, 'direct' => 15, 'social' => 10];
        $type = $this->weightedRandom($types);

        $referrers = $this->referrers[$type];
        $referer = $referrers[array_rand($referrers)];

        return empty($referer) ? null : $referer;
    }

    private function generateRealisticIP(string $countryCode): string
    {
        $ipRanges = [
            'US' => ['173.252.', '199.16.', '204.15.', '69.171.', '157.240.'],
            'GB' => ['81.2.', '86.1.', '109.144.', '151.224.', '82.0.'],
            'DE' => ['85.25.', '91.65.', '178.25.', '188.174.', '217.80.'],
            'IN' => ['103.21.', '103.22.', '117.239.', '106.51.', '152.57.'],
            'CA' => ['142.11.', '192.99.', '198.50.', '162.243.', '159.203.'],
            'FR' => ['78.192.', '90.84.', '176.31.', '195.154.', '37.59.'],
            'BR' => ['200.160.', '189.85.', '177.67.', '191.36.'],
            'AU' => ['203.208.', '220.101.', '139.130.', '203.59.'],
            'NL' => ['145.97.', '85.144.', '194.109.', '217.123.'],
            'ES' => ['83.36.', '88.27.', '95.16.', '213.97.'],
            'JP' => ['202.216.', '210.248.', '126.19.', '202.179.'],
            'KR' => ['121.162.', '175.223.', '211.36.', '220.120.'],
            'SG' => ['202.171.', '203.116.', '210.23.', '202.93.'],
            'SE' => ['194.9.', '195.67.', '213.66.', '81.224.'],
            'CH' => ['178.209.', '195.65.', '62.2.', '212.243.'],
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

