<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;

class ClicksSeeder extends Seeder
{
    /**
     * Dados realísticos para gerar clicks diversos
     */
    private array $countries = [
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'continent' => 'NA'],
        ['name' => 'Brazil', 'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'continent' => 'SA'],
        ['name' => 'United Kingdom', 'iso' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'continent' => 'EU'],
        ['name' => 'Germany', 'iso' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'continent' => 'EU'],
        ['name' => 'France', 'iso' => 'FR', 'currency' => 'EUR', 'timezone' => 'Europe/Paris', 'continent' => 'EU'],
        ['name' => 'Canada', 'iso' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'continent' => 'NA'],
        ['name' => 'Australia', 'iso' => 'AU', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney', 'continent' => 'OC'],
        ['name' => 'Japan', 'iso' => 'JP', 'currency' => 'JPY', 'timezone' => 'Asia/Tokyo', 'continent' => 'AS'],
        ['name' => 'India', 'iso' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'continent' => 'AS'],
        ['name' => 'Mexico', 'iso' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City', 'continent' => 'NA'],
        ['name' => 'Spain', 'iso' => 'ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid', 'continent' => 'EU'],
        ['name' => 'Italy', 'iso' => 'IT', 'currency' => 'EUR', 'timezone' => 'Europe/Rome', 'continent' => 'EU'],
        ['name' => 'Netherlands', 'iso' => 'NL', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam', 'continent' => 'EU'],
        ['name' => 'Argentina', 'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
        ['name' => 'South Korea', 'iso' => 'KR', 'currency' => 'KRW', 'timezone' => 'Asia/Seoul', 'continent' => 'AS'],
    ];

    private array $cities = [
        'US' => [
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California', 'lat' => 34.0522, 'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Chicago', 'state' => 'IL', 'state_name' => 'Illinois', 'lat' => 41.8781, 'lng' => -87.6298, 'postal' => '60601'],
            ['city' => 'Houston', 'state' => 'TX', 'state_name' => 'Texas', 'lat' => 29.7604, 'lng' => -95.3698, 'postal' => '77001'],
            ['city' => 'Miami', 'state' => 'FL', 'state_name' => 'Florida', 'lat' => 25.7617, 'lng' => -80.1918, 'postal' => '33101'],
        ],
        'BR' => [
            ['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília', 'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Salvador', 'state' => 'BA', 'state_name' => 'Bahia', 'lat' => -12.9714, 'lng' => -38.5014, 'postal' => '40070-110'],
        ],
        'GB' => [
            ['city' => 'London', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 51.5074, 'lng' => -0.1278, 'postal' => 'SW1A 1AA'],
            ['city' => 'Manchester', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 53.4808, 'lng' => -2.2426, 'postal' => 'M1 1AA'],
            ['city' => 'Birmingham', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 52.4862, 'lng' => -1.8904, 'postal' => 'B1 1AA'],
        ],
        'DE' => [
            ['city' => 'Berlin', 'state' => 'BE', 'state_name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050, 'postal' => '10115'],
            ['city' => 'Munich', 'state' => 'BY', 'state_name' => 'Bavaria', 'lat' => 48.1351, 'lng' => 11.5820, 'postal' => '80331'],
            ['city' => 'Hamburg', 'state' => 'HH', 'state_name' => 'Hamburg', 'lat' => 53.5511, 'lng' => 9.9937, 'postal' => '20095'],
        ],
        'FR' => [
            ['city' => 'Paris', 'state' => 'IDF', 'state_name' => 'Île-de-France', 'lat' => 48.8566, 'lng' => 2.3522, 'postal' => '75001'],
            ['city' => 'Lyon', 'state' => 'ARA', 'state_name' => 'Auvergne-Rhône-Alpes', 'lat' => 45.7640, 'lng' => 4.8357, 'postal' => '69001'],
        ],
        // Adicionar cidades padrão para outros países
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'mobile' => 60,     // 60% mobile
        'desktop' => 35,    // 35% desktop
        'tablet' => 4,      // 4% tablet
        'bot' => 1,         // 1% bots
    ];

    private array $userAgents = [
        'mobile' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 10; Pixel 4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.91 Mobile Safari/537.36',
        ],
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.114 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:89.0) Gecko/20100101 Firefox/89.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Safari/605.1.15',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.1 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 11; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.120 Safari/537.36',
        ],
        'bot' => [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        ],
    ];

    private array $referrers = [
        'social' => [
            'https://www.facebook.com/',
            'https://twitter.com/',
            'https://www.instagram.com/',
            'https://www.linkedin.com/',
            'https://www.tiktok.com/',
        ],
        'search' => [
            'https://www.google.com/search?q=example',
            'https://www.bing.com/search?q=example',
            'https://search.yahoo.com/search?p=example',
        ],
        'direct' => [null, '-', ''],
        'other' => [
            'https://news.ycombinator.com/',
            'https://www.reddit.com/',
            'https://medium.com/',
            'https://dev.to/',
        ],
    ];

    public function run(): void
    {
        $this->command->info('🚀 Iniciando criação de 6000 clicks para o link ID 2...');

        // Verificar se o link existe
        $link = Link::find(2);
        if (!$link) {
            $this->command->error('❌ Link com ID 2 não encontrado!');
            return;
        }

        $clicks = [];
        $batchSize = 500; // Inserir em lotes para performance

        // Período: últimos 90 dias
        $startDate = Carbon::now()->subDays(90);
        $endDate = Carbon::now();

        $this->command->info("📅 Período: {$startDate->format('d/m/Y')} até {$endDate->format('d/m/Y')}");

        for ($i = 0; $i < 6000; $i++) {
            // Data aleatória no período
            $clickDate = $this->generateRandomDate($startDate, $endDate);

            // Selecionar país com distribuição realística
            $country = $this->selectCountryByWeight();

            // Selecionar cidade do país
            $cityData = $this->getCityData($country['iso']);

            // Selecionar dispositivo
            $device = $this->selectDeviceByWeight();

            // Gerar IP realístico
            $ip = $this->generateRealisticIP($country['iso']);

            // Selecionar user agent baseado no dispositivo
            $userAgent = $this->getUserAgent($device);

            // Selecionar referrer
            $referer = $this->getReferer();

            $clicks[] = [
                'link_id' => 2,
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

            // Inserir em lotes
            if (count($clicks) >= $batchSize) {
                Click::insert($clicks);
                $clicks = [];
                $this->command->info("✅ Inseridos " . ($i + 1) . " clicks...");
            }
        }

        // Inserir clicks restantes
        if (count($clicks) > 0) {
            Click::insert($clicks);
        }

        $this->command->info('🎉 6000 clicks criados com sucesso!');

        // Estatísticas finais
        $this->showStatistics();
    }

    private function generateRandomDate(Carbon $start, Carbon $end): Carbon
    {
        $timestamp = mt_rand($start->timestamp, $end->timestamp);

        // Distribuição mais realística por hora do dia
        $hour = $this->getRealisticHour();
        $minute = mt_rand(0, 59);
        $second = mt_rand(0, 59);

        return Carbon::createFromTimestamp($timestamp)
            ->setTime($hour, $minute, $second);
    }

    private function getRealisticHour(): int
    {
        // Distribuição baseada em padrões reais de uso
        $hourWeights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
            6 => 3, 7 => 5, 8 => 8, 9 => 10, 10 => 12, 11 => 13,
            12 => 14, 13 => 15, 14 => 16, 15 => 17, 16 => 16,
            17 => 15, 18 => 14, 19 => 13, 20 => 12, 21 => 10,
            22 => 8, 23 => 5
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        // Distribuição realística por país
        $weights = [
            0 => 35,  // US - 35%
            1 => 15,  // BR - 15%
            2 => 12,  // GB - 12%
            3 => 8,   // DE - 8%
            4 => 6,   // FR - 6%
            5 => 5,   // CA - 5%
            6 => 4,   // AU - 4%
            7 => 3,   // JP - 3%
            8 => 3,   // IN - 3%
            9 => 3,   // MX - 3%
            10 => 2,  // ES - 2%
            11 => 2,  // IT - 2%
            12 => 1,  // NL - 1%
            13 => 1,  // AR - 1%
            14 => 1,  // KR - 1%
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
        $types = ['direct' => 40, 'social' => 30, 'search' => 20, 'other' => 10];
        $type = $this->weightedRandom($types);

        $referrers = $this->referrers[$type];
        $referer = $referrers[array_rand($referrers)];

        return empty($referer) ? null : $referer;
    }

    private function generateRealisticIP(string $countryCode): string
    {
        // Faixas de IP por país (simplificado)
        $ipRanges = [
            'US' => ['173.252.', '199.16.', '204.15.', '69.171.'],
            'BR' => ['200.160.', '189.85.', '177.67.', '191.36.'],
            'GB' => ['81.2.', '86.1.', '109.144.', '151.224.'],
            'DE' => ['85.25.', '91.65.', '178.25.', '188.174.'],
            'DEFAULT' => ['192.168.', '10.0.', '172.16.', '203.0.'],
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

    private function showStatistics(): void
    {
        $this->command->info("\n📊 ESTATÍSTICAS DOS CLICKS CRIADOS:");

        $total = Click::where('link_id', 2)->count();
        $countries = Click::where('link_id', 2)->distinct('country')->count();
        $devices = Click::where('link_id', 2)->distinct('device')->count();
        $withCoordinates = Click::where('link_id', 2)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->count();

        $this->command->info("✅ Total de clicks: {$total}");
        $this->command->info("🌍 Países únicos: {$countries}");
        $this->command->info("📱 Dispositivos únicos: {$devices}");
        $this->command->info("🗺️ Com coordenadas: {$withCoordinates}");

        // Top 5 países
        $topCountries = Click::where('link_id', 2)
            ->select('country', \DB::raw('count(*) as total'))
            ->groupBy('country')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->get();

        $this->command->info("\n🏆 TOP 5 PAÍSES:");
        foreach ($topCountries as $country) {
            $percentage = round(($country->total / $total) * 100, 1);
            $this->command->info("   {$country->country}: {$country->total} clicks ({$percentage}%)");
        }
    }
}
