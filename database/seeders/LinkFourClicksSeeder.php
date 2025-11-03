<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;

class LinkFourClicksSeeder extends Seeder
{
    /**
     * Seeder para o Link ID 4 - Analytics Dashboard Test
     * Cria dados variados e realísticos para testar todos os endpoints de analytics
     */
    private array $countries = [
        ['name' => 'Brazil', 'iso' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo', 'continent' => 'SA'],
        ['name' => 'United States', 'iso' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York', 'continent' => 'NA'],
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
        ['name' => 'Portugal', 'iso' => 'PT', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon', 'continent' => 'EU'],
        ['name' => 'South Africa', 'iso' => 'ZA', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'continent' => 'AF'],
        ['name' => 'Russia', 'iso' => 'RU', 'currency' => 'RUB', 'timezone' => 'Europe/Moscow', 'continent' => 'EU'],
        ['name' => 'China', 'iso' => 'CN', 'currency' => 'CNY', 'timezone' => 'Asia/Shanghai', 'continent' => 'AS'],
        ['name' => 'Poland', 'iso' => 'PL', 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw', 'continent' => 'EU'],
    ];

    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília', 'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Salvador', 'state' => 'BA', 'state_name' => 'Bahia', 'lat' => -12.9714, 'lng' => -38.5014, 'postal' => '40070-110'],
            ['city' => 'Fortaleza', 'state' => 'CE', 'state_name' => 'Ceará', 'lat' => -3.7319, 'lng' => -38.5267, 'postal' => '60000-000'],
            ['city' => 'Belo Horizonte', 'state' => 'MG', 'state_name' => 'Minas Gerais', 'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30000-000'],
            ['city' => 'Curitiba', 'state' => 'PR', 'state_name' => 'Paraná', 'lat' => -25.4289, 'lng' => -49.2671, 'postal' => '80000-000'],
            ['city' => 'Recife', 'state' => 'PE', 'state_name' => 'Pernambuco', 'lat' => -8.0476, 'lng' => -34.8770, 'postal' => '50000-000'],
            ['city' => 'Porto Alegre', 'state' => 'RS', 'state_name' => 'Rio Grande do Sul', 'lat' => -30.0346, 'lng' => -51.2177, 'postal' => '90000-000'],
            ['city' => 'Manaus', 'state' => 'AM', 'state_name' => 'Amazonas', 'lat' => -3.1190, 'lng' => -60.0217, 'postal' => '69000-000'],
        ],
        'US' => [
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California', 'lat' => 34.0522, 'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Chicago', 'state' => 'IL', 'state_name' => 'Illinois', 'lat' => 41.8781, 'lng' => -87.6298, 'postal' => '60601'],
            ['city' => 'Houston', 'state' => 'TX', 'state_name' => 'Texas', 'lat' => 29.7604, 'lng' => -95.3698, 'postal' => '77001'],
            ['city' => 'Miami', 'state' => 'FL', 'state_name' => 'Florida', 'lat' => 25.7617, 'lng' => -80.1918, 'postal' => '33101'],
            ['city' => 'Seattle', 'state' => 'WA', 'state_name' => 'Washington', 'lat' => 47.6062, 'lng' => -122.3321, 'postal' => '98101'],
            ['city' => 'Boston', 'state' => 'MA', 'state_name' => 'Massachusetts', 'lat' => 42.3601, 'lng' => -71.0589, 'postal' => '02101'],
            ['city' => 'San Francisco', 'state' => 'CA', 'state_name' => 'California', 'lat' => 37.7749, 'lng' => -122.4194, 'postal' => '94101'],
        ],
        'GB' => [
            ['city' => 'London', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 51.5074, 'lng' => -0.1278, 'postal' => 'SW1A 1AA'],
            ['city' => 'Manchester', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 53.4808, 'lng' => -2.2426, 'postal' => 'M1 1AA'],
            ['city' => 'Birmingham', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 52.4862, 'lng' => -1.8904, 'postal' => 'B1 1AA'],
            ['city' => 'Edinburgh', 'state' => 'SCT', 'state_name' => 'Scotland', 'lat' => 55.9533, 'lng' => -3.1883, 'postal' => 'EH1 1AA'],
            ['city' => 'Liverpool', 'state' => 'ENG', 'state_name' => 'England', 'lat' => 53.4084, 'lng' => -2.9916, 'postal' => 'L1 1AA'],
        ],
        'DE' => [
            ['city' => 'Berlin', 'state' => 'BE', 'state_name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050, 'postal' => '10115'],
            ['city' => 'Munich', 'state' => 'BY', 'state_name' => 'Bavaria', 'lat' => 48.1351, 'lng' => 11.5820, 'postal' => '80331'],
            ['city' => 'Hamburg', 'state' => 'HH', 'state_name' => 'Hamburg', 'lat' => 53.5511, 'lng' => 9.9937, 'postal' => '20095'],
            ['city' => 'Frankfurt', 'state' => 'HE', 'state_name' => 'Hesse', 'lat' => 50.1109, 'lng' => 8.6821, 'postal' => '60311'],
            ['city' => 'Cologne', 'state' => 'NW', 'state_name' => 'North Rhine-Westphalia', 'lat' => 50.9375, 'lng' => 6.9603, 'postal' => '50667'],
        ],
        'FR' => [
            ['city' => 'Paris', 'state' => 'IDF', 'state_name' => 'Île-de-France', 'lat' => 48.8566, 'lng' => 2.3522, 'postal' => '75001'],
            ['city' => 'Lyon', 'state' => 'ARA', 'state_name' => 'Auvergne-Rhône-Alpes', 'lat' => 45.7640, 'lng' => 4.8357, 'postal' => '69001'],
            ['city' => 'Marseille', 'state' => 'PAC', 'state_name' => 'Provence-Alpes-Côte d\'Azur', 'lat' => 43.2965, 'lng' => 5.3698, 'postal' => '13001'],
            ['city' => 'Toulouse', 'state' => 'OCC', 'state_name' => 'Occitanie', 'lat' => 43.6047, 'lng' => 1.4442, 'postal' => '31000'],
        ],
        'CA' => [
            ['city' => 'Toronto', 'state' => 'ON', 'state_name' => 'Ontario', 'lat' => 43.6532, 'lng' => -79.3832, 'postal' => 'M5H 2N2'],
            ['city' => 'Vancouver', 'state' => 'BC', 'state_name' => 'British Columbia', 'lat' => 49.2827, 'lng' => -123.1207, 'postal' => 'V6B 1A1'],
            ['city' => 'Montreal', 'state' => 'QC', 'state_name' => 'Quebec', 'lat' => 45.5017, 'lng' => -73.5673, 'postal' => 'H2Y 1C6'],
        ],
        'AU' => [
            ['city' => 'Sydney', 'state' => 'NSW', 'state_name' => 'New South Wales', 'lat' => -33.8688, 'lng' => 151.2093, 'postal' => '2000'],
            ['city' => 'Melbourne', 'state' => 'VIC', 'state_name' => 'Victoria', 'lat' => -37.8136, 'lng' => 144.9631, 'postal' => '3000'],
            ['city' => 'Brisbane', 'state' => 'QLD', 'state_name' => 'Queensland', 'lat' => -27.4698, 'lng' => 153.0251, 'postal' => '4000'],
        ],
        'JP' => [
            ['city' => 'Tokyo', 'state' => '13', 'state_name' => 'Tokyo', 'lat' => 35.6762, 'lng' => 139.6503, 'postal' => '100-0001'],
            ['city' => 'Osaka', 'state' => '27', 'state_name' => 'Osaka', 'lat' => 34.6937, 'lng' => 135.5023, 'postal' => '530-0001'],
            ['city' => 'Kyoto', 'state' => '26', 'state_name' => 'Kyoto', 'lat' => 35.0116, 'lng' => 135.7681, 'postal' => '600-8216'],
        ],
        'IN' => [
            ['city' => 'Mumbai', 'state' => 'MH', 'state_name' => 'Maharashtra', 'lat' => 19.0760, 'lng' => 72.8777, 'postal' => '400001'],
            ['city' => 'Delhi', 'state' => 'DL', 'state_name' => 'Delhi', 'lat' => 28.7041, 'lng' => 77.1025, 'postal' => '110001'],
            ['city' => 'Bangalore', 'state' => 'KA', 'state_name' => 'Karnataka', 'lat' => 12.9716, 'lng' => 77.5946, 'postal' => '560001'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'mobile' => 58,     // 58% mobile
        'desktop' => 35,    // 35% desktop
        'tablet' => 6,      // 6% tablet
        'bot' => 1,         // 1% bots
    ];

    private array $userAgents = [
        'mobile' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 14; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 12; OnePlus 9 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 15_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1',
        ],
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 11.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36 Edg/119.0.0.0',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
        ],
        'bot' => [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; Bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
        ],
    ];

    private array $referrers = [
        'social' => [
            'https://www.facebook.com/',
            'https://twitter.com/',
            'https://www.instagram.com/',
            'https://www.linkedin.com/',
            'https://www.tiktok.com/',
            'https://www.youtube.com/',
            'https://www.reddit.com/',
            'https://wa.me/',
        ],
        'search' => [
            'https://www.google.com/search?q=analytics+dashboard',
            'https://www.bing.com/search?q=link+tracking',
            'https://search.yahoo.com/search?p=url+shortener',
            'https://duckduckgo.com/?q=link+analytics',
            'https://www.google.com.br/search?q=encurtador+links',
        ],
        'direct' => [null, '-', ''],
        'other' => [
            'https://news.ycombinator.com/',
            'https://www.reddit.com/',
            'https://medium.com/',
            'https://dev.to/',
            'https://stackoverflow.com/',
            'https://github.com/',
            'https://discord.com/',
            'https://slack.com/',
        ],
    ];

    public function run(): void
    {
        $this->command->info('🚀 Iniciando criação de clicks para o Link ID 4 (Analytics Dashboard)...');

        // Verificar se o link existe
        $link = Link::find(4);
        if (!$link) {
            $this->command->error('❌ Link com ID 4 não encontrado!');
            return;
        }

        $this->command->info("🔗 Link encontrado: {$link->original_url}");
        $this->command->info("📝 Título: {$link->title}");
        $this->command->info("🔖 Slug: {$link->slug}");

        $clicks = [];
        $totalClicks = 5280; // Número estratégico para bons gráficos
        $batchSize = 500;

        // Período: últimos 60 dias para análise temporal rica
        $startDate = Carbon::now()->subDays(60);
        $endDate = Carbon::now();

        $this->command->info("📅 Período: {$startDate->format('d/m/Y')} até {$endDate->format('d/m/Y')}");
        $this->command->info("🎯 Total de clicks a criar: {$totalClicks}");

        for ($i = 0; $i < $totalClicks; $i++) {
            // Data aleatória no período com distribuição realística
            $clickDate = $this->generateRandomDate($startDate, $endDate);

            // Selecionar país com distribuição variada
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
                'link_id' => 4,
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
                $current = $i + 1;
                $progress = round(($current / $totalClicks) * 100, 1);
                $this->command->info("✅ Progresso: {$progress}% ({$current}/{$totalClicks})");
            }
        }

        // Inserir clicks restantes
        if (count($clicks) > 0) {
            Click::insert($clicks);
        }

        // Atualizar contador de cliques no link
        $link->update(['clicks' => $totalClicks]);

        $this->command->info("🎉 {$totalClicks} clicks criados com sucesso para o Link ID 4!");

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
        // Distribuição baseada em padrões reais de uso global
        $hourWeights = [
            0 => 2, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
            6 => 4, 7 => 6, 8 => 9, 9 => 12, 10 => 14, 11 => 16,
            12 => 17, 13 => 18, 14 => 19, 15 => 20, 16 => 19,
            17 => 17, 18 => 16, 19 => 15, 20 => 13, 21 => 11,
            22 => 8, 23 => 5
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        // Distribuição global variada para testes completos
        $weights = [
            0 => 25,  // BR - 25%
            1 => 20,  // US - 20%
            2 => 12,  // GB - 12%
            3 => 10,  // DE - 10%
            4 => 8,   // FR - 8%
            5 => 5,   // CA - 5%
            6 => 4,   // AU - 4%
            7 => 3,   // JP - 3%
            8 => 3,   // IN - 3%
            9 => 2,   // MX - 2%
            10 => 2,  // ES - 2%
            11 => 2,  // IT - 2%
            12 => 1,  // NL - 1%
            13 => 1,  // AR - 1%
            14 => 1,  // KR - 1%
            15 => 1,  // PT - 1%
            16 => 1,  // ZA - 1%
            17 => 1,  // RU - 1%
            18 => 1,  // CN - 1%
            19 => 1,  // PL - 1%
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
        $types = ['direct' => 35, 'social' => 30, 'search' => 25, 'other' => 10];
        $type = $this->weightedRandom($types);

        $referrers = $this->referrers[$type];
        $referer = $referrers[array_rand($referrers)];

        return empty($referer) ? null : $referer;
    }

    private function generateRealisticIP(string $countryCode): string
    {
        // Faixas de IP por país (realísticas)
        $ipRanges = [
            'BR' => ['200.160.', '189.85.', '177.67.', '191.36.', '186.202.', '187.108.', '201.23.', '179.184.'],
            'US' => ['173.252.', '199.16.', '204.15.', '69.171.', '157.240.', '31.13.', '104.244.', '162.158.'],
            'GB' => ['81.2.', '86.1.', '109.144.', '151.224.', '82.0.', '92.40.', '188.172.', '217.138.'],
            'DE' => ['85.25.', '91.65.', '178.25.', '188.174.', '217.80.', '79.193.', '46.4.', '95.90.'],
            'FR' => ['78.192.', '90.84.', '176.31.', '195.154.', '37.59.', '91.121.', '213.186.', '82.64.'],
            'CA' => ['142.11.', '192.99.', '198.50.', '162.243.', '159.203.', '104.131.', '206.167.', '24.114.'],
            'AU' => ['203.208.', '220.101.', '139.130.', '203.59.', '202.12.', '203.134.', '101.160.', '118.127.'],
            'JP' => ['202.216.', '210.248.', '126.19.', '202.179.', '210.132.', '133.130.', '153.127.', '219.94.'],
            'IN' => ['103.21.', '103.22.', '103.23.', '103.24.', '117.239.', '106.51.', '152.57.', '223.233.'],
            'MX' => ['189.203.', '187.141.', '201.144.', '200.77.', '189.240.', '187.188.', '201.131.', '148.233.'],
            'ES' => ['83.36.', '88.27.', '95.16.', '213.97.', '80.58.', '217.125.', '195.53.', '62.15.'],
            'IT' => ['151.11.', '79.2.', '93.34.', '95.110.', '62.94.', '79.20.', '151.76.', '95.73.'],
            'NL' => ['145.97.', '85.144.', '194.109.', '217.123.', '62.58.', '213.127.', '82.161.', '95.97.'],
            'AR' => ['190.210.', '181.47.', '200.115.', '186.33.', '190.183.', '181.209.', '200.69.', '168.83.'],
            'KR' => ['121.162.', '175.223.', '211.36.', '220.120.', '112.169.', '58.229.', '175.196.', '210.99.'],
            'PT' => ['85.240.', '95.94.', '213.13.', '193.136.', '2.82.', '87.196.', '91.194.', '188.81.'],
            'ZA' => ['105.184.', '197.81.', '41.0.', '41.76.', '169.0.', '41.185.', '105.0.', '154.0.'],
            'RU' => ['77.88.', '95.108.', '178.76.', '213.180.', '85.26.', '194.67.', '91.215.', '46.0.'],
            'CN' => ['223.5.', '117.136.', '183.232.', '218.17.', '112.80.', '123.125.', '220.181.', '61.135.'],
            'PL' => ['83.11.', '89.64.', '178.235.', '91.232.', '213.158.', '62.179.', '188.116.', '31.179.'],
            'DEFAULT' => ['192.168.', '10.0.', '172.16.', '203.0.', '198.51.', '203.0.'],
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
        $this->command->info("\n📊 ESTATÍSTICAS DOS CLICKS CRIADOS PARA LINK ID 4:");

        $total = Click::where('link_id', 4)->count();
        $countries = Click::where('link_id', 4)->distinct('country')->count();
        $devices = Click::where('link_id', 4)->distinct('device')->count();
        $withCoordinates = Click::where('link_id', 4)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->count();

        $this->command->info("✅ Total de clicks: {$total}");
        $this->command->info("🌍 Países únicos: {$countries}");
        $this->command->info("📱 Dispositivos únicos: {$devices}");
        $this->command->info("🗺️ Com coordenadas: {$withCoordinates}");

        // Top 10 países
        $topCountries = Click::where('link_id', 4)
            ->select('country', \DB::raw('count(*) as total'))
            ->groupBy('country')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();

        $this->command->info("\n🏆 TOP 10 PAÍSES:");
        foreach ($topCountries as $country) {
            $percentage = round(($country->total / $total) * 100, 1);
            $this->command->info("   {$country->country}: {$country->total} clicks ({$percentage}%)");
        }

        // Top 10 cidades
        $topCities = Click::where('link_id', 4)
            ->select('city', 'country', \DB::raw('count(*) as total'))
            ->groupBy('city', 'country')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();

        $this->command->info("\n🏙️ TOP 10 CIDADES:");
        foreach ($topCities as $city) {
            $percentage = round(($city->total / $total) * 100, 1);
            $this->command->info("   {$city->city}, {$city->country}: {$city->total} clicks ({$percentage}%)");
        }

        // Distribuição por dispositivo
        $deviceStats = Click::where('link_id', 4)
            ->select('device', \DB::raw('count(*) as total'))
            ->groupBy('device')
            ->orderBy('total', 'desc')
            ->get();

        $this->command->info("\n📱 DISTRIBUIÇÃO POR DISPOSITIVO:");
        foreach ($deviceStats as $device) {
            $percentage = round(($device->total / $total) * 100, 1);
            $this->command->info("   " . ucfirst($device->device) . ": {$device->total} clicks ({$percentage}%)");
        }

        // Clicks por continente
        $continentStats = Click::where('link_id', 4)
            ->select('continent', \DB::raw('count(*) as total'))
            ->groupBy('continent')
            ->orderBy('total', 'desc')
            ->get();

        $continentNames = [
            'SA' => 'América do Sul',
            'NA' => 'América do Norte',
            'EU' => 'Europa',
            'AS' => 'Ásia',
            'OC' => 'Oceania',
            'AF' => 'África',
        ];

        $this->command->info("\n🌏 DISTRIBUIÇÃO POR CONTINENTE:");
        foreach ($continentStats as $continent) {
            $percentage = round(($continent->total / $total) * 100, 1);
            $name = $continentNames[$continent->continent] ?? $continent->continent;
            $this->command->info("   {$name}: {$continent->total} clicks ({$percentage}%)");
        }

        // Clicks nos últimos 7 dias
        $last7Days = Click::where('link_id', 4)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->select(\DB::raw('DATE(created_at) as date'), \DB::raw('count(*) as total'))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        $this->command->info("\n📈 CLICKS DOS ÚLTIMOS 7 DIAS:");
        foreach ($last7Days as $day) {
            $date = Carbon::parse($day->date)->format('d/m/Y (D)');
            $this->command->info("   {$date}: {$day->total} clicks");
        }

        // Referrers
        $topReferrers = Click::where('link_id', 4)
            ->whereNotNull('referer')
            ->where('referer', '!=', '-')
            ->where('referer', '!=', '')
            ->select('referer', \DB::raw('count(*) as total'))
            ->groupBy('referer')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->get();

        $this->command->info("\n🔗 TOP 5 REFERRERS:");
        foreach ($topReferrers as $ref) {
            $domain = parse_url($ref->referer, PHP_URL_HOST) ?? $ref->referer;
            $percentage = round(($ref->total / $total) * 100, 1);
            $this->command->info("   {$domain}: {$ref->total} clicks ({$percentage}%)");
        }

        $directClicks = Click::where('link_id', 4)
            ->where(function($q) {
                $q->whereNull('referer')
                  ->orWhere('referer', '-')
                  ->orWhere('referer', '');
            })
            ->count();
        $directPercentage = round(($directClicks / $total) * 100, 1);
        $this->command->info("   Direct/Unknown: {$directClicks} clicks ({$directPercentage}%)");

        $this->command->info("\n🎯 Link ID 4 pronto para testar todos os endpoints de analytics!");
        $this->command->info("🌐 URL de teste: http://localhost:3000/link/analytic/4");
        $this->command->info("📡 Endpoints disponíveis:");
        $this->command->info("   - GET /api/analytics/link/{id}");
        $this->command->info("   - GET /api/analytics/link/{id}/dashboard");
        $this->command->info("   - GET /api/analytics/link/{id}/geographic");
        $this->command->info("   - GET /api/analytics/link/{id}/temporal");
        $this->command->info("   - GET /api/analytics/link/{id}/audience");
    }
}

