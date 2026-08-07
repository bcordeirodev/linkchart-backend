<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garante o contrato de segurança da coluna users.is_admin: default false,
 * cast booleano e imunidade a mass-assignment (a ÚNICA via de promoção é
 * escrita explícita — tinker/forceFill — nunca payload de request).
 */
class AdminUserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_admin_defaults_to_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->refresh()->is_admin);
    }

    public function test_is_admin_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'secret123',
            'is_admin' => true, // deve ser silenciosamente descartado
        ]);

        $this->assertFalse($user->refresh()->is_admin);
    }

    public function test_last_login_at_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Mallory',
            'email' => 'mallory2@example.com',
            'password' => 'secret123',
            'last_login_at' => now(),
        ]);

        $this->assertNull($user->refresh()->last_login_at);
    }
}
