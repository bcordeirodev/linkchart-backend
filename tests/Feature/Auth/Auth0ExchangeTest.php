<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for POST /api/auth/auth0-exchange.
 *
 * Uses Http::fake() to mock the Auth0 /userinfo endpoint so no real HTTP
 * calls are made during the test suite.
 */
class Auth0ExchangeTest extends TestCase
{
    use RefreshDatabase;

    private string $userinfoUrl;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $domain = config('services.auth0.domain');
        $this->userinfoUrl = "https://{$domain}/userinfo";
    }

    /** Creates a new user when none exists with the given auth0_sub or email. */
    public function test_creates_new_user_from_auth0_token(): void
    {
        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'google-oauth2|111',
                'email' => 'newuser@example.com',
                'name' => 'New User',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'any-valid-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'auth0_sub' => 'google-oauth2|111',
        ]);
    }

    /** Links an existing account (by email) that has no auth0_sub yet. */
    public function test_links_existing_user_by_email(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'auth0_sub' => null,
        ]);

        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'google-oauth2|222',
                'email' => 'existing@example.com',
                'name' => 'Existing User',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'any-valid-token',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'auth0_sub' => 'google-oauth2|222',
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    /** Returns the same user on repeat logins via the same auth0_sub. */
    public function test_returns_existing_user_by_auth0_sub(): void
    {
        $user = User::factory()->create(['auth0_sub' => 'google-oauth2|333']);

        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'google-oauth2|333',
                'email' => $user->email,
                'name' => $user->name,
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'any-valid-token',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('users', 1);
        $response->assertJsonPath('data.user.id', $user->id);
    }

    /** Returns 409 when an email is already linked to a different auth0_sub. */
    public function test_returns_409_on_email_conflict(): void
    {
        User::factory()->create([
            'email' => 'taken@example.com',
            'auth0_sub' => 'google-oauth2|other-sub',
        ]);

        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'google-oauth2|new-sub',
                'email' => 'taken@example.com',
                'name' => 'Conflict',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'any-valid-token',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'auth0_email_conflict');
    }

    /** Returns 401 when Auth0 rejects the access token. */
    public function test_returns_401_on_invalid_token(): void
    {
        Http::fake([
            $this->userinfoUrl => Http::response(['error' => 'invalid_token'], 401),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'bad-token',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'auth0_token_invalid');
    }

    /** Returns 422 when access_token is missing from the request body. */
    public function test_returns_422_when_access_token_missing(): void
    {
        $response = $this->postJson('/api/auth/auth0-exchange', []);

        $response->assertStatus(422);
    }
}
