<?php

namespace Database\Factories;

use App\Models\Click;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClickFactory extends Factory
{
    protected $model = Click::class;

    public function definition(): array
    {
        return [
            'link_id' => null, // Definido dinamicamente no seeder
            'ip' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'referer' => $this->faker->optional()->url(),
            'country' => $this->faker->countryCode(),
            'city' => $this->faker->city(),
            'device' => $this->faker->randomElement(['mobile', 'desktop', 'tablet']),
            'created_at' => now()->subDays(rand(0, 30)),
            'updated_at' => now(),
        ];
    }
}
