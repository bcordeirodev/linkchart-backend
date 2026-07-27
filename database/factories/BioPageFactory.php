<?php

namespace Database\Factories;

use App\Models\BioPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for {@see \App\Models\BioPage}.
 *
 * `handle` uses `unique()` and is generated lowercase-alnum to satisfy both
 * the DB unique constraint and the `^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$` format
 * enforced by {@see \App\Http\Requests\Bio\UpsertBioPageRequest} — tests that
 * go through the model factory directly (bypassing the HTTP layer) still get
 * a handle that would also pass validation.
 */
class BioPageFactory extends Factory
{
    protected $model = BioPage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'handle' => strtolower($this->faker->unique()->lexify('handle????')),
            'title' => $this->faker->name(),
            'bio' => $this->faker->optional()->sentence(10),
            'theme' => 'dark',
            'is_active' => true,
        ];
    }
}
