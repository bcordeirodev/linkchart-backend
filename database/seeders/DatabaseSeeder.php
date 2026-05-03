<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        \App\Models\User::updateOrCreate(
            ['email' => 'usuario2@example.com'],
            [
                'id' => 2,
                'name' => 'Usuário Teste',
                'password' => bcrypt('password'),
                'email_verified' => true,
                'email_verified_at' => now(),
            ]
        );

        \App\Models\Link::updateOrCreate(
            ['slug' => 'teste-analytics'],
            ['id' => 2, 'user_id' => 2, 'title' => 'Link de Teste Analytics', 'original_url' => 'https://www.example.com', 'is_active' => true, 'clicks' => 0]
        );
        \App\Models\Link::updateOrCreate(
            ['slug' => 'teste-completo'],
            ['id' => 3, 'user_id' => 2, 'title' => 'Link de Teste Completo', 'original_url' => 'https://www.example.org', 'is_active' => true, 'clicks' => 0]
        );
        \App\Models\Link::updateOrCreate(
            ['slug' => 'analytics-test'],
            ['id' => 4, 'user_id' => 2, 'title' => 'Link Analytics Dashboard', 'original_url' => 'https://linkcharts.com.br', 'is_active' => true, 'clicks' => 0]
        );
        \App\Models\Link::updateOrCreate(
            ['slug' => 'bf2024'],
            ['id' => 5, 'user_id' => 2, 'title' => 'E-commerce Black Friday 2024', 'original_url' => 'https://shop.example.com/black-friday', 'is_active' => true, 'clicks' => 0]
        );
        \App\Models\Link::updateOrCreate(
            ['slug' => 'react-patterns'],
            ['id' => 6, 'user_id' => 2, 'title' => 'Advanced React Patterns Guide', 'original_url' => 'https://blog.tech.com/react-patterns', 'is_active' => true, 'clicks' => 0]
        );

        $this->call([
            ClicksSeeder::class,
            LinkThreeClicksSeeder::class,
            LinkFourClicksSeeder::class,
            LinkFiveClicksSeeder::class,
            LinkSixClicksSeeder::class,
        ]);
    }
}
