<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Garante que a aba Cliques respeita o mesmo drill-down dos gráficos.
 */
class ClicksListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * O filtro `device` do drill-down precisa restringir a listagem de
     * cliques da mesma forma que restringe os gráficos da mesma tela.
     */
    public function test_device_filter_scopes_the_list(): void
    {
        Click::factory()->count(2)->create(['link_id' => $this->link->id, 'device' => 'mobile']);
        Click::factory()->count(5)->create(['link_id' => $this->link->id, 'device' => 'desktop']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/link/{$this->link->id}/clicks-list?device=mobile");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    /**
     * `direct` é bucket COALESCE: as linhas com click_source NULL contam como
     * diretas nas agregações e precisam aparecer aqui também.
     */
    public function test_channel_direct_includes_null_click_source(): void
    {
        Click::factory()->count(2)->create(['link_id' => $this->link->id, 'click_source' => 'direct']);
        Click::factory()->count(3)->create(['link_id' => $this->link->id, 'click_source' => null]);
        Click::factory()->count(4)->create(['link_id' => $this->link->id, 'click_source' => 'social']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/link/{$this->link->id}/clicks-list?channel=direct");

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));
    }

    /**
     * `other` é bucket derivado, não um valor armazenado: getTrafficSourceAnalysis()
     * (InsightsAnalyticsService) e categorizeClickSource() (LinkTrackingService)
     * agrupam ali todo click_source fora de social/search/direct/email/referral
     * — incluindo 'unknown'. A UI mostra "N cliques" nessa barra a partir dessa
     * mesma agregação; `?channel=other` precisa devolver exatamente essas linhas,
     * não zero.
     */
    public function test_channel_other_includes_unnamed_click_sources(): void
    {
        Click::factory()->count(4)->create(['link_id' => $this->link->id, 'click_source' => 'social']);
        Click::factory()->count(2)->create(['link_id' => $this->link->id, 'click_source' => 'unknown']);
        Click::factory()->count(3)->create(['link_id' => $this->link->id, 'click_source' => 'qr_code']);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/link/{$this->link->id}/clicks-list?channel=other");

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));
    }
}
