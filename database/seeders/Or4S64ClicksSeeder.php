<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;

class Or4S64ClicksSeeder extends Seeder
{
    /**
     * Seeder específico para o link or4S64 (ID 35)
     * Cria dados realísticos para testar o front-end
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
        ['name' => 'Argentina', 'iso' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Buenos_Aires', 'continent' => 'SA'],
    ];

    private array $cities = [
        'BR' => [
            ['city' => 'São Paulo', 'state' => 'SP', 'state_name' => 'São Paulo', 'lat' => -23.5505, 'lng' => -46.6333, 'postal' => '01310-100'],
            ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'state_name' => 'Rio de Janeiro', 'lat' => -22.9068, 'lng' => -43.1729, 'postal' => '20040-020'],
            ['city' => 'Brasília', 'state' => 'DF', 'state_name' => 'Distrito Federal', 'lat' => -15.7801, 'lng' => -47.9292, 'postal' => '70040-010'],
            ['city' => 'Salvador', 'state' => 'BA', 'state_name' => 'Bahia', 'lat' => -12.9714, 'lng' => -38.5014, 'postal' => '40070-110'],
            ['city' => 'Fortaleza', 'state' => 'CE', 'state_name' => 'Ceará', 'lat' => -3.7319, 'lng' => -38.5267, 'postal' => '60000-000'],
            ['city' => 'Belo Horizonte', 'state' => 'MG', 'state_name' => 'Minas Gerais', 'lat' => -19.9167, 'lng' => -43.9345, 'postal' => '30000-000'],
        ],
        'US' => [
            ['city' => 'New York', 'state' => 'NY', 'state_name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'postal' => '10001'],
            ['city' => 'Los Angeles', 'state' => 'CA', 'state_name' => 'California', 'lat' => 34.0522, 'lng' => -118.2437, 'postal' => '90001'],
            ['city' => 'Chicago', 'state' => 'IL', 'state_name' => 'Illinois', 'lat' => 41.8781, 'lng' => -87.6298, 'postal' => '60601'],
            ['city' => 'Houston', 'state' => 'TX', 'state_name' => 'Texas', 'lat' => 29.7604, 'lng' => -95.3698, 'postal' => '77001'],
            ['city' => 'Miami', 'state' => 'FL', 'state_name' => 'Florida', 'lat' => 25.7617, 'lng' => -80.1918, 'postal' => '33101'],
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
            ['city' => 'Paris', 'state' => 'IDF', 'state_name' => 'Ile-de-France', 'lat' => 48.8566, 'lng' => 2.3522, 'postal' => '75001'],
            ['city' => 'Lyon', 'state' => 'ARA', 'state_name' => 'Auvergne-Rhone-Alpes', 'lat' => 45.7640, 'lng' => 4.8357, 'postal' => '69001'],
            ['city' => 'Marseille', 'state' => 'PAC', 'state_name' => 'Provence-Alpes-Cote d\'Azur', 'lat' => 43.2965, 'lng' => 5.3698, 'postal' => '13001'],
        ],
        'DEFAULT' => [
            ['city' => 'Capital', 'state' => 'ST', 'state_name' => 'State', 'lat' => 0, 'lng' => 0, 'postal' => '00000'],
        ],
    ];

    private array $devices = [
        'mobile' => 55,     // 55% mobile
        'desktop' => 38,    // 38% desktop
        'tablet' => 6,      // 6% tablet
        'bot' => 1,         // 1% bots
    ];

    private array $userAgents = [
        'mobile' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 12; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.104 Mobile Safari/537.36',
            'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/94.0.4606.85 Mobile Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 15_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; SM-S908B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Mobile Safari/537.36',
        ],
        'desktop' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:107.0) Gecko/20100101 Firefox/107.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.1 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0 Safari/537.36',
        ],
        'tablet' => [
            'Mozilla/5.0 (iPad; CPU OS 16_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.1 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 12; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.104 Safari/537.36',
            'Mozilla/5.0 (iPad; CPU OS 15_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1',
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
            'https://www.youtube.com/',
            'https://wa.me/',
            'https://t.me/',
        ],
        'search' => [
            'https://www.google.com/search?q=link+encurtador',
            'https://www.bing.com/search?q=encurtar+url',
            'https://search.yahoo.com/search?p=short+link',
            'https://duckduckgo.com/?q=url+shortener',
            'https://www.google.com.br/search?q=encurtador+de+links',
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
        $this->command->info('🚀 Iniciando criação de clicks para o link or4S64 (ID 35)...');

        // Verificar se o link existe
        $link = Link::where('slug', 'or4S64')->first();
        if (!$link) {
            $this->command->error('❌ Link com slug or4S64 não encontrado!');
            return;
        }

        $this->command->info("🔗 Link encontrado: {$link->original_url}");

        // Limpar clicks existentes para este link
        Click::where('link_id', $link->id)->delete();
        $this->command->info('🧹 Clicks anteriores removidos');

        $clicks = [];
        $totalClicks = 1247; // Número interessante para teste
        $batchSize = 100;

        // Período: últimos 30 dias para dados mais recentes
        $startDate = Carbon::now()->subDays(30);
        $endDate = Carbon::now();

        $this->command->info("📅 Período: {$startDate->format('d/m/Y')} até {$endDate->format('d/m/Y')}");
        $this->command->info("🎯 Total de clicks a criar: {$totalClicks}");

        for ($i = 0; $i < $totalClicks; $i++) {
            // Data aleatória no período com distribuição mais realística
            $clickDate = $this->generateRandomDate($startDate, $endDate);

            // Selecionar país com distribuição focada no Brasil
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
                'link_id' => $link->id,
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

        $this->command->info("🎉 {$totalClicks} clicks criados com sucesso para o link or4S64!");

        // Estatísticas finais
        $this->showStatistics($link->id);
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
        // Distribuição baseada em padrões brasileiros de uso
        $hourWeights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
            6 => 4, 7 => 6, 8 => 9, 9 => 12, 10 => 14, 11 => 15,
            12 => 16, 13 => 17, 14 => 18, 15 => 19, 16 => 18,
            17 => 16, 18 => 15, 19 => 14, 20 => 13, 21 => 11,
            22 => 8, 23 => 5
        ];

        return $this->weightedRandom($hourWeights);
    }

    private function selectCountryByWeight(): array
    {
        // Distribuição focada no Brasil para teste
        $weights = [
            0 => 45,  // BR - 45%
            1 => 20,  // US - 20%
            2 => 8,   // GB - 8%
            3 => 6,   // DE - 6%
            4 => 5,   // FR - 5%
            5 => 4,   // CA - 4%
            6 => 3,   // AU - 3%
            7 => 2,   // JP - 2%
            8 => 2,   // IN - 2%
            9 => 2,   // MX - 2%
            10 => 2,  // ES - 2%
            11 => 1,  // AR - 1%
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
        $types = ['direct' => 35, 'social' => 35, 'search' => 20, 'other' => 10];
        $type = $this->weightedRandom($types);

        $referrers = $this->referrers[$type];
        $referer = $referrers[array_rand($referrers)];

        return empty($referer) ? null : $referer;
    }

    private function generateRealisticIP(string $countryCode): string
    {
        // Faixas de IP por país (mais realísticas)
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
            'AR' => ['190.210.', '181.47.', '200.115.', '186.33.', '190.183.', '181.209.', '200.69.', '168.83.'],
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

    private function showStatistics(int $linkId): void
    {
        $this->command->info("\n📊 ESTATÍSTICAS DOS CLICKS CRIADOS PARA or4S64:");

        $total = Click::where('link_id', $linkId)->count();
        $countries = Click::where('link_id', $linkId)->distinct('country')->count();
        $devices = Click::where('link_id', $linkId)->distinct('device')->count();
        $withCoordinates = Click::where('link_id', $linkId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->count();

        $this->command->info("✅ Total de clicks: {$total}");
        $this->command->info("🌍 Países únicos: {$countries}");
        $this->command->info("📱 Dispositivos únicos: {$devices}");
        $this->command->info("🗺️ Com coordenadas: {$withCoordinates}");

        // Top 5 países
        $topCountries = Click::where('link_id', $linkId)
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

        // Top 5 cidades
        $topCities = Click::where('link_id', $linkId)
            ->select('city', 'country', \DB::raw('count(*) as total'))
            ->groupBy('city', 'country')
            ->orderBy('total', 'desc')
            ->limit(5)
            ->get();

        $this->command->info("\n🏙️ TOP 5 CIDADES:");
        foreach ($topCities as $city) {
            $percentage = round(($city->total / $total) * 100, 1);
            $this->command->info("   {$city->city}, {$city->country}: {$city->total} clicks ({$percentage}%)");
        }

        // Distribuição por dispositivo
        $deviceStats = Click::where('link_id', $linkId)
            ->select('device', \DB::raw('count(*) as total'))
            ->groupBy('device')
            ->orderBy('total', 'desc')
            ->get();

        $this->command->info("\n📱 DISTRIBUIÇÃO POR DISPOSITIVO:");
        foreach ($deviceStats as $device) {
            $percentage = round(($device->total / $total) * 100, 1);
            $this->command->info("   {$device->device}: {$device->total} clicks ({$percentage}%)");
        }

        // Clicks por dia (últimos 7 dias)
        $recentClicks = Click::where('link_id', $linkId)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->select(\DB::raw('DATE(created_at) as date'), \DB::raw('count(*) as total'))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        $this->command->info("\n📈 CLICKS DOS ÚLTIMOS 7 DIAS:");
        foreach ($recentClicks as $day) {
            $date = Carbon::parse($day->date)->format('d/m/Y');
            $this->command->info("   {$date}: {$day->total} clicks");
        }

        $this->command->info("\n🎯 Link or4S64 pronto para teste no front-end!");
        $this->command->info("🌐 Acesse: /analytics/or4S64");
    }
}
