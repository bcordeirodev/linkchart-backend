<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserSubdomain>
 */
class UserSubdomainFactory extends Factory
{
    protected $model = UserSubdomain::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subdomain' => $this->faker->unique()->lexify('???'),
            'status' => 'active',
        ];
    }

    /**
     * Mark the subdomain as inactive (released).
     */
    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }
}
