<?php

namespace Tests\Feature\Auth;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Models\UserSubdomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for DELETE /api/account (LGPD right-to-erasure account deletion).
 *
 * Covers both confirmation strategies — password for local accounts,
 * typed-email confirmation for Auth0 accounts (which have no password) — and
 * asserts that deletion cascades to the user's links, clicks, and subdomain
 * claims. No `verified` middleware guards this route: unverified users must
 * still be able to delete their own account.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Local account + correct password deletes the user and cascades to
     * their links, clicks, and subdomain claim.
     */
    public function test_deletes_local_account_and_cascade(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
            'auth0_sub' => null,
        ]);
        $token = auth()->guard('api')->login($user);

        $link = Link::factory()->create(['user_id' => $user->id]);
        $click = Click::factory()->create(['link_id' => $link->id]);
        $subdomain = UserSubdomain::factory()->create(['user_id' => $user->id]);

        $response = $this->deleteJson('/api/account', [
            'password' => 'correct-password',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
        $this->assertDatabaseMissing('clicks', ['id' => $click->id]);
        $this->assertDatabaseMissing('user_subdomains', ['id' => $subdomain->id]);
    }

    /**
     * Local account + wrong password rejects the deletion with a 422 and
     * leaves the account and its data intact.
     */
    public function test_wrong_password_rejects_deletion(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
            'auth0_sub' => null,
        ]);
        $token = auth()->guard('api')->login($user);
        $link = Link::factory()->create(['user_id' => $user->id]);

        $response = $this->deleteJson('/api/account', [
            'password' => 'wrong-password',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PASSWORD');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('links', ['id' => $link->id]);
    }

    /**
     * Auth0 account (password === null) + confirmation matching the user's
     * email deletes the user and cascades to their links, clicks, and
     * subdomain claim.
     */
    public function test_deletes_auth0_account_with_email_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'auth0_sub' => 'google-oauth2|deletion-test',
            'email' => 'auth0user@example.com',
        ]);
        $token = auth()->guard('api')->login($user);

        $link = Link::factory()->create(['user_id' => $user->id]);
        $click = Click::factory()->create(['link_id' => $link->id]);
        $subdomain = UserSubdomain::factory()->create(['user_id' => $user->id]);

        $response = $this->deleteJson('/api/account', [
            'confirmation' => 'auth0user@example.com',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('links', ['id' => $link->id]);
        $this->assertDatabaseMissing('clicks', ['id' => $click->id]);
        $this->assertDatabaseMissing('user_subdomains', ['id' => $subdomain->id]);
    }

    /**
     * Auth0 account + confirmation that does not match the user's email
     * rejects the deletion with a 422 and leaves the account intact.
     */
    public function test_wrong_confirmation_rejects_deletion(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'auth0_sub' => 'google-oauth2|deletion-test-2',
            'email' => 'auth0user2@example.com',
        ]);
        $token = auth()->guard('api')->login($user);

        $response = $this->deleteJson('/api/account', [
            'confirmation' => 'wrong@example.com',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_CONFIRMATION');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /** Requests without a valid JWT are rejected with a 401. */
    public function test_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/account', ['password' => 'irrelevant']);

        $response->assertStatus(401);
    }
}
