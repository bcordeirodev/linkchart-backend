<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Link;
use App\Models\Click;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Limpa as tabelas
        \App\Models\Click::truncate();
        \App\Models\Link::truncate();
        \App\Models\User::truncate();

        // Garante que o usuário 2 existe
        \App\Models\User::factory()->create([
            'id' => 2,
            'name' => 'Usuário Teste',
            'email' => 'usuario2@example.com',
            'password' => bcrypt('password'),
        ]);

        // Criar link de teste
        \App\Models\Link::factory()->create([
            'id' => 2,
            'user_id' => 2,
            'title' => 'Link de Teste Analytics',
            'slug' => 'teste-analytics',
            'original_url' => 'https://www.example.com',
            'is_active' => true,
            'clicks' => 0,
        ]);

        // Criar link ID 3 para testes
        \App\Models\Link::factory()->create([
            'id' => 3,
            'user_id' => 2,
            'title' => 'Link de Teste Completo',
            'slug' => 'teste-completo',
            'original_url' => 'https://www.example.org',
            'is_active' => true,
            'clicks' => 0,
        ]);

        // Criar link ID 4 para testes de analytics
        \App\Models\Link::factory()->create([
            'id' => 4,
            'user_id' => 2,
            'title' => 'Link Analytics Dashboard',
            'slug' => 'analytics-test',
            'original_url' => 'https://linkcharts.com.br',
            'is_active' => true,
            'clicks' => 0,
        ]);

        // Popular clicks com dados realísticos
        $this->call([
            ClicksSeeder::class,
            LinkThreeClicksSeeder::class,
            LinkFourClicksSeeder::class,
        ]);
    }
}
