<?php

namespace Database\Factories;

use App\Models\BioPage;
use App\Models\BioPageItem;
use App\Models\Link;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for {@see \App\Models\BioPageItem}.
 */
class BioPageItemFactory extends Factory
{
    protected $model = BioPageItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bio_page_id' => BioPage::factory(),
            'link_id' => Link::factory(),
            'label' => $this->faker->words(2, true),
            'position' => 0,
            'is_active' => true,
        ];
    }
}
