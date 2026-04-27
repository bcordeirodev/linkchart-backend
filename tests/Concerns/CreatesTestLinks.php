<?php

namespace Tests\Concerns;

use App\Models\Link;
use App\Models\User;

trait CreatesTestLinks
{
    protected function makeLink(array $overrides = []): Link
    {
        $user = User::factory()->create();

        return Link::factory()->create(array_merge([
            'user_id' => $user->id,
            'slug' => 'abc123',
            'original_url' => 'https://example.com/destino',
            'is_active' => true,
        ], $overrides));
    }
}
