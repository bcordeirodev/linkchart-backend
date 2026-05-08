<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('users')->upsert(
            [
                [
                    'id' => 2,
                    'name' => 'Usuário Teste',
                    'email' => 'usuario2@example.com',
                    'password' => Hash::make('password'),
                    'email_verified' => true,
                    'email_verified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ],
            ['email'],
            ['name', 'password', 'email_verified', 'email_verified_at', 'updated_at']
        );

        // Advance the users sequence past the manually inserted ID
        DB::statement("SELECT setval('users_id_seq', (SELECT MAX(id) FROM users))");

        $now = now()->toDateTimeString();
        $links = [
            ['id' => 2, 'slug' => 'teste-analytics',  'title' => 'Link de Teste Analytics',          'original_url' => 'https://www.example.com'],
            ['id' => 3, 'slug' => 'teste-completo',    'title' => 'Link de Teste Completo',           'original_url' => 'https://www.example.org'],
            ['id' => 4, 'slug' => 'analytics-test',    'title' => 'Link Analytics Dashboard',         'original_url' => 'https://linkcharts.com.br'],
            ['id' => 5, 'slug' => 'bf2024',            'title' => 'E-commerce Black Friday 2024',     'original_url' => 'https://shop.example.com/black-friday'],
            ['id' => 6, 'slug' => 'react-patterns',    'title' => 'Advanced React Patterns Guide',    'original_url' => 'https://blog.tech.com/react-patterns'],
            ['id' => 7, 'slug' => 'newsletter-launch', 'title' => 'Newsletter — Lançamento de Produto', 'original_url' => 'https://newsletter.example.com/lancamento-2024'],
            ['id' => 8, 'slug' => 'tutorial-yt',       'title' => 'Tutorial em Vídeo — YouTube',      'original_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['id' => 9, 'slug' => 'linkedin-post',     'title' => 'Post LinkedIn — Case de Sucesso',  'original_url' => 'https://www.linkedin.com/posts/linkcharts-case-sucesso'],
        ];

        foreach ($links as $link) {
            DB::table('links')->upsert(
                array_merge($link, [
                    'user_id' => 2,
                    'is_active' => true,
                    'clicks' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
                ['slug'],
                ['title', 'original_url', 'updated_at']
            );
        }

        // Advance the links sequence past the manually inserted IDs
        DB::statement("SELECT setval('links_id_seq', (SELECT MAX(id) FROM links))");

        $this->call([
            ClicksSeeder::class,
            LinkThreeClicksSeeder::class,
            LinkFourClicksSeeder::class,
            LinkFiveClicksSeeder::class,
            LinkSixClicksSeeder::class,
            LinkSevenClicksSeeder::class,
            LinkEightClicksSeeder::class,
            LinkNineClicksSeeder::class,
        ]);
    }
}
