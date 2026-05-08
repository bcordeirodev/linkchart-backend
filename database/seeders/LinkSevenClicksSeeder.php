<?php

namespace Database\Seeders;

use App\Models\Click;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class LinkSevenClicksSeeder extends Seeder
{
    use ClickEnrichmentTrait;
    /**
     * Seeder para o Link ID 7 - Newsletter Lançamento de Produto
     * Foco: Tráfego via email, desktop dominante, público BR concentrado
     */
    private array $countries = [
        ['name' => 'Brazil', 'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'continent' => 'SA'],
        ['name' => 'Portugal', 'iso' => 'PT', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon', 'continent' => 'EU'],
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'continent' => 'NA'],
        ['name' => 'Argentina', 'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
        ['name' => 'Colombia', 'iso' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'continent' => 'SA'],
        ['name' => 'Mexico', 'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'continent' => 'NA'],
    ];

    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Belo Horizonte', 'state' => 'MG', 'state_name' => 'Minas Gerais', 'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30000-000'],
            ['city' => 'Porto Alegre', 'state' => 'RS', 'state_name' => 'Rio Grande do Sul', 'lat' => -30.0346, 'lng' => -51.2177, 'postal' => '90010-150'],
            ['city' => 'Recife', 'state' => 'PE', 'state_name' => 'Pernambuco', 'lat' => -8.0578, 'lng' => -34.8829, 'postal' => '50010-000'],
        ],
        'PT' => [
            ['city' => 'Lisboa', 'state' => 'LIS', 'state_name' => 'Lisboa', 'lat' => 38.7169, 'lng' => -9.1399, 'postal' => '1000-001'],
            ['city' => 'Porto', 'state' => 'POR', 'state_name' => 'Porto', 'lat' => 41.1579, 'lng' => -8.6291, 'postal' => '4000-001'],
        ],
        'US' => [
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Miami', 'state' => 'FL', 'state_name' => 'Florida', 'lat' => 25.7617, 'lng' => -80.1918, 'postal' => '33101'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'desktop' => 70,
        'mobile' => 25,
        'tablet' => 5,
    ];

    private array $userAgents = [
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
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
        'email' => [
            'https://mail.google.com/',
            'https://outlook.live.com/',
            'https://mail.yahoo.com/',
            'https://www.mailchimp.com/',
            'https://substack.com/',
        ],
        'direct' => [null, '-', ''],
        'social' => [
            'https://www.linkedin.com/',
            'https://twitter.com/',
        ],
    ];

    public function run(): void
    {
        Click::where('link_id', 7)->delete();

        $this->command->info('🚀 Criando Link ID 7 (Newsletter - Lançamento de Produto)...');

        $clicks = [];
        $totalClicks = 2100;
        $batchSize = 500;

        // Período: últimos 20 dias (campanha recente e concentrada)
        $startDate = Carbon::now()->subDays(20);
        $endDate = Carbon::now()->subDays(2);

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
                'link_id' => 7,
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

        $this->command->info("🎉 {$totalClicks} clicks criados para Link ID 7!");
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
        // Newsletter: pico manhã (quando as pessoas abrem o email)
        $hourWeights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
            6 => 4, 7 => 10, 8 => 20, 9 => 24, 10 => 22, 11 => 18,
            12 => 14, 13 => 12, 14 => 10, 15 => 9, 16 => 8,
            17 => 7, 18 => 6, 19 => 5, 20 => 5, 21 => 4,
            22 => 3, 23 => 2,
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        $weights = [
            0 => 65,  // BR - 65%
            1 => 12,  // PT - 12%
            2 => 10,  // US - 10%
            3 => 7,   // AR - 7%
            4 => 4,   // CO - 4%
            5 => 2,   // MX - 2%
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
        // Newsletter: maioria via cliente de email
        $types = ['email' => 65, 'direct' => 25, 'social' => 10];
        $type = $this->weightedRandom($types);

        $referrers = $this->referrers[$type];
        $referer = $referrers[array_rand($referrers)];

        return empty($referer) ? null : $referer;
    }

    private function generateRealisticIP(string $countryCode): string
    {
        $ipRanges = [
            'BR' => ['200.160.', '189.85.', '177.67.', '191.36.', '186.202.'],
            'PT' => ['85.241.', '194.65.', '213.28.', '195.23.'],
            'US' => ['173.252.', '199.16.', '204.15.', '69.171.'],
            'AR' => ['190.210.', '181.47.', '200.115.', '186.33.'],
            'CO' => ['190.27.', '181.48.', '200.24.', '186.31.'],
            'MX' => ['189.203.', '187.141.', '201.144.', '200.77.'],
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
