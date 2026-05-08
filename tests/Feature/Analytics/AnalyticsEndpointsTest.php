<?php

namespace Tests\Feature\Analytics;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyticsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified'    => true,
            'email_verified_at' => now(),
        ]);
        $this->token = auth()->guard('api')->login($this->user);
        $this->link  = Link::factory()->create(['user_id' => $this->user->id]);
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    /** @return array<string, array{string}> */
    public static function analyticsEndpoints(): array
    {
        return [
            'dashboard'    => ['/api/analytics/link/%d/dashboard'],
            'comprehensive' => ['/api/analytics/link/%d/comprehensive'],
            'geographic'   => ['/api/analytics/link/%d/geographic'],
            'insights'     => ['/api/analytics/link/%d/insights'],
            'temporal'     => ['/api/analytics/link/%d/temporal'],
            'audience'     => ['/api/analytics/link/%d/audience'],
        ];
    }

    #[DataProvider('analyticsEndpoints')]
    public function test_returns_401_without_token(string $pattern): void
    {
        auth()->guard('api')->logout();

        $url = sprintf($pattern, $this->link->id);
        $this->getJson($url)->assertStatus(401);
    }

    #[DataProvider('analyticsEndpoints')]
    public function test_returns_404_for_link_owned_by_another_user(string $pattern): void
    {
        $other = User::factory()->create();
        $foreignLink = Link::factory()->create(['user_id' => $other->id]);

        $url = sprintf($pattern, $foreignLink->id);
        $this->getJson($url, $this->auth())->assertStatus(404);
    }

    #[DataProvider('analyticsEndpoints')]
    public function test_returns_200_with_success_for_owned_link(string $pattern): void
    {
        $url = sprintf($pattern, $this->link->id);

        $this->getJson($url, $this->auth())
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_geographic_endpoint_returns_data_and_meta_blocks(): void
    {
        \App\Models\Click::factory()->count(3)->create([
            'link_id'   => $this->link->id,
            'latitude'  => -23.5,
            'longitude' => -46.6,
            'country'   => 'Brazil',
            'iso_code'  => 'BR',
            'state'     => 'SP',
            'state_name'=> 'São Paulo',
            'city'      => 'São Paulo',
            'continent' => 'SA',
        ]);

        $url = sprintf('/api/analytics/link/%d/geographic', $this->link->id);

        $this->getJson($url, $this->auth())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'heatmap_data',
                    'top_countries',
                    'top_states',
                    'top_cities',
                    'continents',
                ],
                'meta' => [
                    'total_clicks',
                    'unique_countries',
                    'unique_states',
                    'unique_cities',
                    'max_clicks',
                    'total_locations',
                    'last_updated',
                    'link_info' => ['id', 'title', 'short_url', 'is_active'],
                ],
            ]);
    }

    public function test_removed_heatmap_endpoint_returns_404(): void
    {
        $url = sprintf('/api/analytics/link/%d/heatmap', $this->link->id);

        $this->getJson($url, $this->auth())->assertStatus(404);
    }
}
