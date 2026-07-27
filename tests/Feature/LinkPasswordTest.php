<?php

namespace Tests\Feature;

use App\Jobs\ProcessLinkClickJob;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

/**
 * Feature: password-protected links.
 *
 * Covers the full lifecycle of the `links.password_hash` column:
 *   - Model behavior: hasPassword(), $hidden serialization, slug-cache invalidation.
 *   - Redirect flow: GET /r/{slug} renders the password form (never a 302, never
 *     destination metadata) for humans, bots and ?preview=1 alike.
 *   - Unlock flow: POST /r/{slug}/unlock with Hash::check, click tracking parity
 *     with the normal human redirect, generic error on wrong password, and the
 *     `redirect-unlock` rate limiter (10/min per IP+slug).
 *   - API: write-only `password` field on create/update, `has_password` exposure,
 *     and the guarantee that `password_hash` never appears in any JSON response.
 */
class LinkPasswordTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    private const HUMAN_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    private const PASSWORD = 'secret123';

    /**
     * Blocks any real HTTP call: if the redirect flow ever tries to fetch OG
     * metadata for a password-protected link, Http::preventStrayRequests()
     * throws, the controller falls to the 404 error page and the test fails —
     * proving the destination URL is never contacted.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Creates an active test link protected by self::PASSWORD (bcrypt-hashed).
     *
     * @param  array<string, mixed>  $overrides  Column overrides forwarded to makeLink().
     */
    private function makeProtectedLink(array $overrides = []): Link
    {
        $link = $this->makeLink($overrides);
        $link->password_hash = Hash::make(self::PASSWORD);
        $link->save();

        return $link->fresh();
    }

    /**
     * Creates a verified user and returns Bearer headers for the API guard.
     *
     * @return array{user: User, headers: array<string, string>}
     */
    private function apiUser(): array
    {
        $user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $token = auth()->guard('api')->login($user);

        return ['user' => $user, 'headers' => ['Authorization' => "Bearer {$token}"]];
    }

    // ============================================================
    // Model
    // ============================================================

    public function test_link_without_password_reports_has_password_false(): void
    {
        $link = $this->makeLink();

        $this->assertFalse($link->hasPassword());
    }

    public function test_link_with_password_hash_reports_has_password_true(): void
    {
        $link = $this->makeProtectedLink();

        $this->assertTrue($link->hasPassword());
    }

    public function test_password_hash_is_hidden_from_model_serialization(): void
    {
        $link = $this->makeProtectedLink();

        $this->assertArrayNotHasKey('password_hash', $link->toArray());
        $this->assertStringNotContainsString('password_hash', $link->toJson());
    }

    // ============================================================
    // Redirect (GET /r/{slug})
    // ============================================================

    public function test_protected_link_shows_password_form_instead_of_redirect(): void
    {
        Queue::fake();
        $link = $this->makeProtectedLink(['original_url' => 'https://example.com/destino']);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $content = $response->getContent();
        $this->assertStringContainsString('type="password"', $content);
        $this->assertStringContainsString('action="/r/'.$link->slug.'/unlock"', $content);
        $this->assertStringContainsString('name="_token"', $content);
        $this->assertStringNotContainsString('example.com/destino', $content);
        Queue::assertNotPushed(ProcessLinkClickJob::class);
        $this->assertSame(0, (int) DB::table('links')->where('id', $link->id)->value('clicks'));
    }

    public function test_bot_gets_password_page_without_destination_metadata(): void
    {
        Queue::fake();
        $link = $this->makeProtectedLink(['original_url' => 'https://example.com/destino']);

        $response = $this->withHeaders(['User-Agent' => 'WhatsApp/2.23.24.76 A'])
            ->get('/r/'.$link->slug);

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('Link protegido', $content);
        $this->assertStringNotContainsString('example.com/destino', $content);
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_preview_param_on_protected_link_shows_password_page(): void
    {
        Queue::fake();
        $link = $this->makeProtectedLink(['original_url' => 'https://example.com/destino']);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug.'?preview=1');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('type="password"', $content);
        $this->assertStringNotContainsString('example.com/destino', $content);
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_expired_check_runs_before_password_check(): void
    {
        Queue::fake();
        $link = $this->makeProtectedLink(['expires_at' => now()->subDay()]);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(404);
        $this->assertStringContainsString('Este link expirou', $response->getContent());
        $this->assertStringNotContainsString('type="password"', $response->getContent());
    }

    public function test_setting_password_takes_effect_despite_slug_cache(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        // Prime o cache do slug com o modelo ainda sem senha (302 normal).
        $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug)
            ->assertStatus(302);

        $link->password_hash = Hash::make(self::PASSWORD);
        $link->save();

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(200);
        $this->assertStringContainsString('type="password"', $response->getContent());
    }

    // ============================================================
    // Unlock (POST /r/{slug}/unlock)
    // ============================================================

    public function test_unlock_with_correct_password_redirects_and_dispatches_job(): void
    {
        Queue::fake();
        $link = $this->makeProtectedLink(['original_url' => 'https://example.com/destino']);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->post('/r/'.$link->slug.'/unlock', ['password' => self::PASSWORD]);

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://example.com/destino');
        Queue::assertPushed(ProcessLinkClickJob::class, function (ProcessLinkClickJob $job) use ($link) {
            $this->assertSame($link->id, $job->linkId);
            $this->assertSame(self::HUMAN_UA, $job->payload['user_agent']);

            return true;
        });
    }

    public function test_unlock_with_correct_password_increments_clicks(): void
    {
        // Sem Queue::fake() — com QUEUE_CONNECTION=sync o job roda inline e o
        // increment acontece dentro dele, igual ao fluxo humano normal.
        $link = $this->makeProtectedLink(['clicks' => 0]);

        $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->post('/r/'.$link->slug.'/unlock', ['password' => self::PASSWORD]);

        $this->assertSame(1, (int) DB::table('links')->where('id', $link->id)->value('clicks'));
    }

    public function test_unlock_with_wrong_password_rerenders_form_without_click(): void
    {
        // Sem Queue::fake(): se um job fosse indevidamente despachado, rodaria
        // inline e incrementaria clicks — a asserção final pegaria a regressão.
        $link = $this->makeProtectedLink(['clicks' => 0, 'original_url' => 'https://example.com/destino']);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->post('/r/'.$link->slug.'/unlock', ['password' => 'wrong-password']);

        $response->assertStatus(422);
        $content = $response->getContent();
        $this->assertStringContainsString('type="password"', $content);
        $this->assertStringContainsString('Senha incorreta', $content);
        $this->assertStringNotContainsString('example.com/destino', $content);
        $this->assertSame(0, (int) DB::table('links')->where('id', $link->id)->value('clicks'));
    }

    public function test_unlock_on_link_without_password_redirects_to_slug_route(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->post('/r/'.$link->slug.'/unlock', ['password' => 'whatever']);

        $response->assertRedirect(route('public.redirect', ['slug' => $link->slug]));
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_unlock_on_nonexistent_slug_returns_error_page(): void
    {
        Queue::fake();

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->post('/r/does-not-exist/unlock', ['password' => 'whatever']);

        $response->assertStatus(404);
        $this->assertStringContainsString('Link não encontrado', $response->getContent());
    }

    public function test_unlock_is_rate_limited_per_ip_and_slug(): void
    {
        Queue::fake();
        $link = $this->makeProtectedLink();

        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders(['User-Agent' => self::HUMAN_UA])
                ->post('/r/'.$link->slug.'/unlock', ['password' => 'wrong-password'])
                ->assertStatus(422);
        }

        $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->post('/r/'.$link->slug.'/unlock', ['password' => 'wrong-password'])
            ->assertStatus(429);
    }

    // ============================================================
    // API (POST /api/links, PUT /api/links/{id})
    // ============================================================

    public function test_create_link_with_password_exposes_has_password_and_hides_hash(): void
    {
        Queue::fake();
        ['headers' => $headers] = $this->apiUser();

        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'password' => self::PASSWORD,
        ], $headers);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.has_password'));
        $this->assertStringNotContainsString('password_hash', $response->getContent());

        $hash = DB::table('links')->where('id', $response->json('data.id'))->value('password_hash');
        $this->assertTrue(Hash::check(self::PASSWORD, $hash));
    }

    public function test_create_link_without_password_has_password_false(): void
    {
        Queue::fake();
        ['headers' => $headers] = $this->apiUser();

        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
        ], $headers);

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.has_password'));
    }

    public function test_create_link_with_too_short_password_is_rejected(): void
    {
        Queue::fake();
        ['headers' => $headers] = $this->apiUser();

        $response = $this->postJson('/api/links', [
            'original_url' => 'https://example.com',
            'password' => '123',
        ], $headers);

        $response->assertStatus(422);
    }

    public function test_update_link_with_password_sets_it(): void
    {
        Queue::fake();
        ['user' => $user, 'headers' => $headers] = $this->apiUser();
        $link = Link::factory()->create(['user_id' => $user->id]);

        $response = $this->putJson("/api/links/{$link->id}", [
            'password' => self::PASSWORD,
        ], $headers);

        $response->assertOk();
        $this->assertTrue($response->json('data.has_password'));
        $this->assertStringNotContainsString('password_hash', $response->getContent());

        $hash = DB::table('links')->where('id', $link->id)->value('password_hash');
        $this->assertTrue(Hash::check(self::PASSWORD, $hash));
    }

    public function test_update_link_with_null_password_removes_it(): void
    {
        Queue::fake();
        ['user' => $user, 'headers' => $headers] = $this->apiUser();
        $link = Link::factory()->create(['user_id' => $user->id]);
        $link->password_hash = Hash::make(self::PASSWORD);
        $link->save();

        $response = $this->putJson("/api/links/{$link->id}", [
            'password' => null,
        ], $headers);

        $response->assertOk();
        $this->assertFalse($response->json('data.has_password'));
        $this->assertNull(DB::table('links')->where('id', $link->id)->value('password_hash'));
    }

    public function test_update_link_without_password_field_keeps_existing_password(): void
    {
        Queue::fake();
        ['user' => $user, 'headers' => $headers] = $this->apiUser();
        $link = Link::factory()->create(['user_id' => $user->id]);
        $link->password_hash = Hash::make(self::PASSWORD);
        $link->save();
        $originalHash = $link->fresh()->password_hash;

        $response = $this->putJson("/api/links/{$link->id}", [
            'title' => 'Novo título',
        ], $headers);

        $response->assertOk();
        $this->assertTrue($response->json('data.has_password'));
        $this->assertSame($originalHash, DB::table('links')->where('id', $link->id)->value('password_hash'));
    }

    public function test_index_and_show_never_expose_password_hash(): void
    {
        Queue::fake();
        ['user' => $user, 'headers' => $headers] = $this->apiUser();
        $link = Link::factory()->create(['user_id' => $user->id]);
        $link->password_hash = Hash::make(self::PASSWORD);
        $link->save();

        $index = $this->getJson('/api/links', $headers);
        $index->assertOk();
        $this->assertStringNotContainsString('password_hash', $index->getContent());

        $show = $this->getJson("/api/links/{$link->id}", $headers);
        $show->assertOk();
        $this->assertStringNotContainsString('password_hash', $show->getContent());
        $this->assertTrue($show->json('data.has_password'));
    }
}
