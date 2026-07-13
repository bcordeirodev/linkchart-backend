<?php

namespace Database\Factories;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for {@see \App\Models\Tag}.
 *
 * `name` uses `unique()` so bulk-creating tags for the same user (e.g. to
 * exercise the 20-tag cap) never collides with the `(user_id, name)` unique
 * constraint. `color` uses Faker's `hexColor()`, which returns a lowercase
 * 7-char string (e.g. "#1a2b3c") matching the `/^#[0-9a-fA-F]{6}$/` format
 * enforced by CreateTagRequest.
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->unique()->word(),
            'color' => $this->faker->hexColor(),
        ];
    }
}
