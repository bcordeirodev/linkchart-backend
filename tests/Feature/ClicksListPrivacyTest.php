<?php

namespace Tests\Feature;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LGPD guardrails for GET /api/link/{id}/clicks-list.
 *
 * The clicks tab must not expose the visitor's full IP address (personal data
 * under the LGPD) nor leak PII carried in referer query strings/fragments.
 */
class ClicksListPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->link = Link::factory()->create(['user_id' => $this->user->id]);
        $this->token = auth()->guard('api')->login($this->user);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_response_does_not_expose_full_ip(): void
    {
        Click::factory()->create([
            'link_id' => $this->link->id,
            'ip' => '187.10.50.20',
        ]);

        $response = $this->getJson("/api/link/{$this->link->id}/clicks-list", $this->auth());

        $response->assertOk();
        $this->assertStringNotContainsString('187.10.50.20', $response->getContent());
        $this->assertArrayNotHasKey('ip', $response->json('data.0'));
    }

    public function test_referer_query_string_is_stripped(): void
    {
        Click::factory()->create([
            'link_id' => $this->link->id,
            'referer' => 'https://example.com/landing?token=abc123&email=user@example.com',
        ]);

        $response = $this->getJson("/api/link/{$this->link->id}/clicks-list", $this->auth());

        $response->assertOk();
        $referer = $response->json('data.0.referer');
        $this->assertSame('https://example.com/landing', $referer);
        $this->assertStringNotContainsString('token', $response->getContent());
        $this->assertStringNotContainsString('user@example.com', $response->getContent());
    }

    public function test_ip_is_not_an_allowed_sort_column(): void
    {
        Click::factory()->create(['link_id' => $this->link->id]);

        $response = $this->getJson("/api/link/{$this->link->id}/clicks-list?sort_by=ip", $this->auth());

        $response->assertOk();
        // Unknown/disallowed sort columns fall back to created_at.
        $this->assertSame('created_at', $response->json('meta.sort_by'));
    }
}
