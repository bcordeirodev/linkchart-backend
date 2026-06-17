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

    /** Exchange sets the JWT as an httpOnly auth_token cookie (XSS-safe). */
    public function test_exchange_sets_httponly_auth_cookie(): void
    {
        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'google-oauth2|cookie',
                'email' => 'cookie@example.com',
                'name' => 'Cookie User',
                'email_verified' => true,
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'any-valid-token',
        ]);

        $response->assertStatus(200)->assertCookie('auth_token');

        // decrypt=false: the cookie is a raw JWT, not a Laravel-encrypted value.
        $cookie = $response->getCookie('auth_token', false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame($response->json('data.token'), $cookie->getValue());
    }

    /**
     * The auth_token cookie authenticates a protected route with no
     * Authorization header — InjectBearerFromCookie promotes the cookie and the
     * JWT guard accepts it. The token is minted the same way auth0Exchange does
     * (JWTAuth::fromUser); that the exchange response carries this token as the
     * cookie is asserted in test_exchange_sets_httponly_auth_cookie.
     */
    public function test_auth_cookie_authenticates_protected_route(): void
    {
        $user = User::factory()->create(['auth0_sub' => 'google-oauth2|me']);
        $token = \Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);

        // Cookies are passed straight to call() so they populate the request
        // cookie bag (the withCookie test helpers don't, on api routes without
        // EncryptCookies). No Authorization header is set.
        $me = $this->call(
            'GET',
            '/api/me',
            [],
            ['auth_token' => $token],
            [],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $me->assertStatus(200)->assertJsonPath('data.user.id', $user->id);
    }

    /** email_hint must never be used to look up or link accounts (account-takeover vector). */
    public function test_ignores_email_hint_when_userinfo_has_no_email(): void
    {
        $victim = User::factory()->create([
            'email' => 'victim@example.com',
            'auth0_sub' => null,
        ]);

        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'facebook|attacker-1',
                // no email key at all
                'name' => 'Attacker',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'attacker-token',
            'email_hint' => 'victim@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'auth0_userinfo_incomplete');
        $this->assertNull($victim->fresh()->auth0_sub);
        $this->assertDatabaseMissing('users', ['auth0_sub' => 'facebook|attacker-1']);
    }

    /** Unverified emails from /userinfo must not link or create accounts. */
    public function test_rejects_unverified_email_from_userinfo(): void
    {
        $victim = User::factory()->create([
            'email' => 'victim2@example.com',
            'auth0_sub' => null,
        ]);

        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'auth0|db-attacker',
                'email' => 'victim2@example.com',
                'email_verified' => false,
                'name' => 'Attacker',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'attacker-token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'auth0_email_unverified');
        $this->assertNull($victim->fresh()->auth0_sub);
    }

    /** String "false" from non-normalizing Auth0 connections must fail closed. */
    public function test_rejects_string_false_email_verified(): void
    {
        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'samlp|attacker-2',
                'email' => 'someone@example.com',
                'email_verified' => 'false',
                'name' => 'Someone',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'any-token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'auth0_email_unverified');
        $this->assertDatabaseMissing('users', ['auth0_sub' => 'samlp|attacker-2']);
    }

    /** Absent email_verified key (with email present) must fail closed. */
    public function test_rejects_missing_email_verified_key(): void
    {
        Http::fake([
            $this->userinfoUrl => Http::response([
                'sub' => 'oidc|no-verified-claim',
                'email' => 'noclaim@example.com',
                'name' => 'No Claim',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/auth0-exchange', [
            'access_token' => 'any-token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'auth0_email_unverified');
        $this->assertDatabaseMissing('users', ['auth0_sub' => 'oidc|no-verified-claim']);
    }
}
