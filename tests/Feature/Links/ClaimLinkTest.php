<?php

namespace Tests\Feature\Links;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use App\Services\Links\LinkSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cobre a feature claim-your-link ponta a ponta no backend:
 * a emissão do token no `POST /api/public/shorten` de convidado e o
 * `POST /api/links/claim` autenticado que troca o dono do link.
 *
 * Os dois testes que mais importam aqui:
 *
 *  - test_guest_shorten_persists_only_the_hash: o token em claro NÃO pode estar
 *    no banco. Se um dump de `links` vazar, o hash sozinho não reivindica nada.
 *  - test_second_claim_returns_409_already_claimed: prova que o UPDATE
 *    condicional resolve a corrida no banco. Uma implementação com
 *    SELECT-then-UPDATE passaria nos testes de caminho feliz e daria dois donos
 *    sob concorrência real.
 *
 * O Safe Browsing é mockado no setUp para o shorten público não bater na API
 * do Google.
 */
class ClaimLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->mock(LinkSafetyService::class, function ($mock) {
            $mock->shouldReceive('checkUrl')
                ->andReturn(['safe' => true, 'threats' => []]);
        });

        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        // tokenById() em vez de login(): login() deixa o usuário resolvido no
        // guard para o resto do teste, e o `POST /api/public/shorten` — que
        // lê `auth()->guard('api')->id()` de propósito, sem exigir header —
        // passaria a criar links COM dono. Sem link anônimo não há claim.
        $this->token = auth()->guard('api')->tokenById($this->user->id);
    }

    /**
     * Cabeçalho de autenticação JWT do usuário do setUp.
     *
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /**
     * Cria um link anônimo pelo encurtador público e devolve slug + token.
     *
     * Passa pelo endpoint real de propósito: o contrato que interessa é o da
     * resposta HTTP, não o do service.
     *
     * @return array{slug: string, claim_token: string}
     */
    private function shortenAsGuest(string $url = 'https://example.com/guest-page'): array
    {
        $response = $this->postJson('/api/public/shorten', ['original_url' => $url]);

        $response->assertStatus(201);

        return [
            'slug' => $response->json('data.slug'),
            'claim_token' => $response->json('data.claim_token'),
        ];
    }

    // ============================================================
    // Emissão do token no shorten
    // ============================================================

    public function test_guest_shorten_returns_claim_token(): void
    {
        $response = $this->postJson('/api/public/shorten', [
            'original_url' => 'https://example.com/anon',
        ]);

        $response->assertStatus(201);

        $claimToken = $response->json('data.claim_token');

        $this->assertIsString($claimToken);
        $this->assertSame(40, strlen($claimToken), 'O token de claim tem 40 chars (Str::random(40)).');
    }

    public function test_guest_shorten_persists_only_the_hash(): void
    {
        ['slug' => $slug, 'claim_token' => $claimToken] = $this->shortenAsGuest();

        $link = Link::where('slug', $slug)->firstOrFail();

        $this->assertNotNull($link->claim_token_hash);
        $this->assertNotSame($claimToken, $link->claim_token_hash, 'O token em claro nunca pode ser persistido.');
        $this->assertSame(hash('sha256', $claimToken), $link->claim_token_hash);
    }

    public function test_claim_token_hash_is_never_serialized(): void
    {
        ['slug' => $slug] = $this->shortenAsGuest();

        $link = Link::where('slug', $slug)->firstOrFail();

        $this->assertArrayNotHasKey('claim_token_hash', $link->toArray());
    }

    public function test_authenticated_shorten_receives_no_claim_token(): void
    {
        $response = $this->postJson('/api/public/shorten', [
            'original_url' => 'https://example.com/owned',
        ], $this->auth());

        $response->assertStatus(201);
        $response->assertJsonMissingPath('data.claim_token');

        $link = Link::where('slug', $response->json('data.slug'))->firstOrFail();

        $this->assertSame($this->user->id, $link->user_id);
        $this->assertNull($link->claim_token_hash, 'Link que já nasce com dono não guarda hash de claim.');
    }

    // ============================================================
    // Claim — caminho feliz
    // ============================================================

    public function test_claim_transfers_ownership_and_burns_the_token(): void
    {
        ['slug' => $slug, 'claim_token' => $claimToken] = $this->shortenAsGuest();

        $response = $this->postJson('/api/links/claim', [
            'slug' => $slug,
            'claim_token' => $claimToken,
        ], $this->auth());

        $response->assertOk();
        $response->assertJsonPath('data.slug', $slug);
        $response->assertJsonPath('data.user_id', $this->user->id);

        $link = Link::where('slug', $slug)->firstOrFail();

        $this->assertSame($this->user->id, $link->user_id);
        $this->assertNull($link->claim_token_hash, 'O claim queima o token: o hash é zerado no mesmo UPDATE.');
    }

    public function test_claim_keeps_the_click_history(): void
    {
        ['slug' => $slug, 'claim_token' => $claimToken] = $this->shortenAsGuest();

        $link = Link::where('slug', $slug)->firstOrFail();
        $click = Click::factory()->create(['link_id' => $link->id]);

        $this->postJson('/api/links/claim', [
            'slug' => $slug,
            'claim_token' => $claimToken,
        ], $this->auth())->assertOk();

        // Os cliques apontam para link_id, então a troca de dono os leva junto
        // sem migrar linha nenhuma — é o valor central da feature.
        $this->assertDatabaseHas('clicks', [
            'id' => $click->id,
            'link_id' => $link->id,
        ]);
        $this->assertSame(1, Link::find($link->id)->clicks()->count());
    }

    // ============================================================
    // Claim — modos de falha
    // ============================================================

    public function test_second_claim_returns_409_already_claimed(): void
    {
        ['slug' => $slug, 'claim_token' => $claimToken] = $this->shortenAsGuest();

        $payload = ['slug' => $slug, 'claim_token' => $claimToken];

        $this->postJson('/api/links/claim', $payload, $this->auth())->assertOk();

        // Segunda tentativa com o MESMO token: o UPDATE condicional afeta 0
        // linhas (user_id já não é NULL e o hash foi zerado) e o estado
        // posterior identifica o link como já reivindicado.
        $second = $this->postJson('/api/links/claim', $payload, $this->auth());

        $second->assertStatus(409);
        $second->assertJsonPath('error.code', 'ALREADY_CLAIMED');
    }

    public function test_wrong_token_returns_422_invalid_claim_token(): void
    {
        ['slug' => $slug] = $this->shortenAsGuest();

        $response = $this->postJson('/api/links/claim', [
            'slug' => $slug,
            'claim_token' => str_repeat('x', 40),
        ], $this->auth());

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_CLAIM_TOKEN');

        $this->assertNull(Link::where('slug', $slug)->firstOrFail()->user_id);
    }

    public function test_nonexistent_slug_returns_the_same_422_code(): void
    {
        $response = $this->postJson('/api/links/claim', [
            'slug' => 'nao-existe',
            'claim_token' => str_repeat('x', 40),
        ], $this->auth());

        // Mesmo código do token errado de propósito: um 404 aqui transformaria
        // o endpoint num oráculo de enumeração de slugs.
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_CLAIM_TOKEN');
    }

    public function test_claiming_a_link_that_was_never_anonymous_returns_409(): void
    {
        $other = User::factory()->create();
        $link = Link::factory()->create([
            'user_id' => $other->id,
            'slug' => 'ja-tem-dono',
        ]);

        $response = $this->postJson('/api/links/claim', [
            'slug' => $link->slug,
            'claim_token' => str_repeat('x', 40),
        ], $this->auth());

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ALREADY_CLAIMED');

        $this->assertSame($other->id, Link::find($link->id)->user_id, 'O dono original não pode mudar.');
    }

    public function test_old_anonymous_link_without_token_is_not_claimable(): void
    {
        // Os 599 links anônimos anteriores à feature não têm prova de criação:
        // permitir o claim seria sequestro de link.
        $link = Link::factory()->create([
            'user_id' => null,
            'slug' => 'anonimo-antigo',
            'claim_token_hash' => null,
        ]);

        $response = $this->postJson('/api/links/claim', [
            'slug' => $link->slug,
            'claim_token' => str_repeat('x', 40),
        ], $this->auth());

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_CLAIM_TOKEN');

        $this->assertNull(Link::find($link->id)->user_id);
    }

    public function test_unauthenticated_claim_is_rejected(): void
    {
        ['slug' => $slug, 'claim_token' => $claimToken] = $this->shortenAsGuest();

        $this->postJson('/api/links/claim', [
            'slug' => $slug,
            'claim_token' => $claimToken,
        ])->assertStatus(401);

        $this->assertNull(Link::where('slug', $slug)->firstOrFail()->user_id);
    }

    public function test_missing_fields_are_rejected_by_validation(): void
    {
        $this->postJson('/api/links/claim', [], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['slug', 'claim_token'], 'error.details.fields');
    }
}
