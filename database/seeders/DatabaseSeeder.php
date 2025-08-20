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
            'shorted_url' => config('app.url') . '/r/teste-analytics',
            'is_active' => true,
            'clicks' => 0,
        ]);

        // Popular clicks com dados realísticos
        $this->call([
            ClicksSeeder::class,
        ]);
    }
}
