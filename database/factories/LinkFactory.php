<?php

namespace Database\Factories;

use App\Models\Link;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LinkFactory extends Factory
{
    protected $model = Link::class;

    public function definition(): array
    {
        return [
            'user_id' => 2, // Relacionar ao usuário 2
            'slug' => Str::slug($this->faker->unique()->words(2, true)) . '-' . Str::random(5),
            'original_url' => $this->faker->url(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->sentence(6),
            'is_active' => true,
            'clicks' => 0,
            'click_limit' => null,
            'utm_source' => $this->faker->optional()->word(),
            'utm_medium' => $this->faker->optional()->word(),
            'utm_campaign' => $this->faker->optional()->word(),
            'utm_term' => $this->faker->optional()->word(),
            'utm_content' => $this->faker->optional()->word(),
            'expires_at' => null,
            'starts_in' => null,
            'created_at' => now()->subDays(rand(0, 30)),
            'updated_at' => now(),
        ];
    }
}
