<?php

namespace Tests\Unit\Models;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the Link mass-assignment surface.
 *
 * `id`, `clicks`, `created_at` and `updated_at` must NOT be fillable: no
 * legitimate code path mass-assigns them (seeders/factories bypass fillable;
 * the DTOs no longer emit them), so a request payload that smuggles them in
 * must be silently discarded — a forged `clicks` would inflate analytics and
 * a forged `id` could collide with an existing row.
 *
 * `user_id` stays fillable on purpose: CreateLinkDTO / CreatePublicLinkDTO
 * legitimately pass it through LinkRepository::create(). Ownership is
 * enforced upstream (the DTO builds it from the authenticated user, never
 * from client input).
 */
class LinkFillableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Forged id, clicks and timestamps in a mass-assignment payload are discarded.
     */
    public function test_create_discards_forged_id_clicks_and_timestamps(): void
    {
        $link = Link::create([
            'slug' => 'fillable-guard',
            'original_url' => 'https://example.com',
            'id' => 999999,
            'clicks' => 5000,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ]);

        $link->refresh();

        $this->assertNotSame(999999, $link->id, 'Forged id must not be mass-assignable.');
        $this->assertSame(0, (int) $link->clicks, 'Forged clicks must not be mass-assignable.');
        $this->assertTrue($link->created_at->year > 2000, 'Forged created_at must not be mass-assignable.');
        $this->assertTrue($link->updated_at->year > 2000, 'Forged updated_at must not be mass-assignable.');
    }

    /**
     * user_id remains mass-assignable — the DTO create path depends on it.
     */
    public function test_create_applies_user_id(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $link = Link::create([
            'slug' => 'fillable-user',
            'original_url' => 'https://example.com',
            'user_id' => $user->id,
        ]);

        $this->assertSame($user->id, $link->refresh()->user_id);
    }
}
