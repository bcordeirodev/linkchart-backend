<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Click;
use App\Models\Link;
use Carbon\Carbon;

class RecentClicksSeeder extends Seeder
{
    /**
     * Criar clicks recentes para testar o dashboard
     */
    public function run(): void
    {
        // Buscar links do usuário 2
        $userLinks = Link::where('user_id', 2)->get();

        if ($userLinks->isEmpty()) {
            $this->command->info('Nenhum link encontrado para o usuário 2');
            return;
        }

        $countries = ['Brazil', 'United States', 'Germany', 'France', 'Japan', 'Canada', 'Australia', 'Italy', 'Spain', 'Netherlands'];
        $cities = ['São Paulo', 'New York', 'Berlin', 'Paris', 'Tokyo', 'Toronto', 'Sydney', 'Rome', 'Madrid', 'Amsterdam'];
        $devices = ['desktop', 'mobile', 'tablet'];
        $browsers = ['Chrome', 'Firefox', 'Safari', 'Edge'];
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Android 11; Mobile; rv:68.0) Gecko/68.0 Firefox/88.0'
        ];

        $totalClicks = 0;

        // Criar clicks para os últimos 7 dias
        for ($day = 0; $day < 7; $day++) {
            $date = Carbon::now()->subDays($day);

            // Variar quantidade por dia (menos clicks para evitar erro)
            $dailyClicks = $date->isWeekend() ? rand(20, 50) : rand(30, 80);

            for ($click = 0; $click < $dailyClicks; $click++) {
                $link = $userLinks->random();
                $hour = $this->getRandomHourWithDistribution();
                $clickTime = $date->copy()->setHour($hour)->setMinute(rand(0, 59))->setSecond(rand(0, 59));

                // Criar click individual usando Eloquent
                Click::create([
                    'link_id' => $link->id,
                    'ip' => $this->generateRandomIP(),
                    'user_agent' => $userAgents[array_rand($userAgents)],
                    'referer' => rand(0, 3) == 0 ? 'https://google.com' : null,
                    'country' => $countries[array_rand($countries)],
                    'city' => $cities[array_rand($cities)],
                    'device' => $devices[array_rand($devices)],
                    'created_at' => $clickTime,
                    'updated_at' => $clickTime,
                ]);

                $totalClicks++;

                // Mostrar progresso a cada 50 clicks
                if ($totalClicks % 50 == 0) {
                    $this->command->info("Inseridos {$totalClicks} clicks...");
                }
            }
        }

        $this->command->info("✅ Seeder concluído! Total de {$totalClicks} clicks recentes criados.");

        // Atualizar contadores dos links
        foreach ($userLinks as $link) {
            $clickCount = Click::where('link_id', $link->id)->count();
            $link->update(['clicks' => $clickCount]);
        }

        $this->command->info("✅ Contadores dos links atualizados!");
    }

    /**
     * Gerar hora aleatória com distribuição realista
     * Mais clicks durante horário comercial
     */
    private function getRandomHourWithDistribution(): int
    {
        $weights = [
            0 => 2,   // 00:00 - baixo
            1 => 1,   // 01:00 - muito baixo
            2 => 1,   // 02:00 - muito baixo
            3 => 1,   // 03:00 - muito baixo
            4 => 1,   // 04:00 - muito baixo
            5 => 2,   // 05:00 - baixo
            6 => 4,   // 06:00 - médio baixo
            7 => 6,   // 07:00 - médio
            8 => 8,   // 08:00 - alto
            9 => 10,  // 09:00 - muito alto
            10 => 12, // 10:00 - pico
            11 => 12, // 11:00 - pico
            12 => 10, // 12:00 - alto
            13 => 11, // 13:00 - muito alto
            14 => 12, // 14:00 - pico
            15 => 12, // 15:00 - pico
            16 => 11, // 16:00 - muito alto
            17 => 9,  // 17:00 - alto
            18 => 8,  // 18:00 - médio alto
            19 => 7,  // 19:00 - médio
            20 => 6,  // 20:00 - médio
            21 => 5,  // 21:00 - médio baixo
            22 => 4,  // 22:00 - baixo
            23 => 3,  // 23:00 - baixo
        ];

        $totalWeight = array_sum($weights);
        $random = rand(1, $totalWeight);

        $currentWeight = 0;
        foreach ($weights as $hour => $weight) {
            $currentWeight += $weight;
            if ($random <= $currentWeight) {
                return $hour;
            }
        }

        return rand(9, 17); // Fallback para horário comercial
    }

    /**
     * Gerar IP aleatório
     */
    private function generateRandomIP(): string
    {
        return rand(1, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 255);
    }
}
