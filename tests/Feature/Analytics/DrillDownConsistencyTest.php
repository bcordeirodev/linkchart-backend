<?php

namespace Tests\Feature\Analytics;

use App\Models\Click;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sob um mesmo filtro, painéis diferentes precisam somar o mesmo total.
 *
 * Foi a ausência deste teste que deixou o bug dos geradores de insight passar:
 * o InsightsFilterTest só olhava analytics_data.quality e nunca o array insights,
 * onde o numerador global dividido pelo denominador filtrado produzia percentuais
 * acima de 100%.
 *
 * Além dos três casos originais (consistência entre painéis, teto de 100% e
 * isolamento de cache por país), esta classe cobre dois gaps encontrados nas
 * revisões das tasks anteriores:
 *   - cobertura funcional dos 8 geradores de insight (não só DeviceInsightGenerator),
 *     com foco nos que derivam uma razão (Device, Geographic, Retention) e no
 *     EngagementInsightGenerator, que usa applyDimensions() de propósito por ter
 *     janela própria de 7d/14d;
 *   - isolamento de cache do orchestrator para a dimensão `continent`, análogo
 *     ao caso de `country` já coberto.
 */
class DrillDownConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Link $link;

    /**
     * Seeds a verified user and a link with a fixed, asymmetric click
     * distribution (6 Brazil/mobile, 4 Brazil/desktop, 10 US/mobile) reused
     * by the panel-consistency and cache-isolation tests below.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $this->link = Link::factory()->create(['user_id' => $this->user->id]);

        // 6 cliques Brasil/mobile, 4 Brasil/desktop, 10 EUA/mobile
        Click::factory()->count(6)->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
            'device' => 'mobile',
            'is_bot' => false,
        ]);
        Click::factory()->count(4)->create([
            'link_id' => $this->link->id,
            'country' => 'Brazil',
            'continent' => 'SA',
            'device' => 'desktop',
            'is_bot' => false,
        ]);
        Click::factory()->count(10)->create([
            'link_id' => $this->link->id,
            'country' => 'United States',
            'continent' => 'NA',
            'device' => 'mobile',
            'is_bot' => false,
        ]);
    }

    /**
     * Filtrando por Brasil, o dashboard, os insights e a lista de cliques
     * precisam todos enxergar 10 cliques — nem 20, nem 6.
     */
    public function test_every_endpoint_agrees_on_the_total_under_a_country_filter(): void
    {
        $q = 'country=Brazil';

        $dashboard = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?{$q}")
            ->assertOk();

        $insights = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?{$q}")
            ->assertOk();

        // Rota da lista de cliques é singular: /api/link/{id}/clicks-list.
        $clicks = $this->actingAs($this->user, 'api')
            ->getJson("/api/link/{$this->link->id}/clicks-list?{$q}")
            ->assertOk();

        $this->assertSame(10, $dashboard->json('data.summary.total_clicks'));
        $this->assertSame(10, $insights->json('data.analytics_data.quality.total_clicks'));
        $this->assertCount(10, $clicks->json('data'));
    }

    /**
     * Percentual de insight nunca pode passar de 100%.
     */
    public function test_insight_percentages_never_exceed_one_hundred(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/insights?country=Brazil&device=mobile")
            ->assertOk();

        foreach ($response->json('data.insights') as $insight) {
            $pct = $insight['description_params']['pct'] ?? null;
            if ($pct !== null) {
                $this->assertLessThanOrEqual(100.0, (float) $pct, "Insight '{$insight['title']}' passou de 100%.");
            }
        }
    }

    /**
     * O cache de 60s do orchestrator é indexado pela cacheKey do DTO. Se uma
     * dimensão não entrar na chave, a segunda requisição (filtrada) recebe o
     * payload da primeira (sem filtro).
     */
    public function test_cache_does_not_leak_across_filters(): void
    {
        $unfiltered = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard")
            ->assertOk();

        $filtered = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?country=Brazil")
            ->assertOk();

        $this->assertSame(20, $unfiltered->json('data.summary.total_clicks'));
        $this->assertSame(10, $filtered->json('data.summary.total_clicks'));
    }

    /**
     * Mesmo teste que test_cache_does_not_leak_across_filters, mas para a
     * dimensão `continent`. Reforçamos a base com mais 5 cliques NA para que
     * um vazamento de cache troque o TOTAL (não apenas a lista) — NA=15 e
     * SA=10 tornam qualquer reuso indevido do payload da primeira requisição
     * detectável.
     */
    public function test_cache_does_not_leak_across_continent_filters(): void
    {
        Click::factory()->count(5)->create([
            'link_id' => $this->link->id,
            'country' => 'Canada',
            'continent' => 'NA',
            'device' => 'desktop',
            'is_bot' => false,
        ]);

        $na = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?continent=NA")
            ->assertOk();

        $sa = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$this->link->id}/dashboard?continent=SA")
            ->assertOk();

        $this->assertSame(15, $na->json('data.summary.total_clicks'));
        $this->assertSame(10, $sa->json('data.summary.total_clicks'));
    }

    /**
     * DeviceInsightGenerator e RetentionInsightGenerator precisam calcular
     * numerador E denominador sobre o mesmo subconjunto filtrado (`country=Brazil`).
     * RetentionInsightGenerator é o único gerador com DUAS queries filtradas
     * (total de visitantes distintos e visitantes recorrentes) — um regressão
     * que esqueça de filtrar uma delas reintroduz o bug em silêncio.
     *
     * Os cliques fora do filtro (Alemanha) têm características deliberadamente
     * opostas às de dentro (100% desktop, 100% recorrente) para que qualquer
     * vazamento do numerador global produza um percentual visivelmente errado
     * em vez de coincidir por acaso com o valor correto.
     */
    public function test_device_and_retention_insights_respect_the_country_filter(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        // Fora do filtro: 20 cliques Alemanha, todos desktop e recorrentes.
        Click::factory()->count(20)->create([
            'link_id' => $link->id,
            'country' => 'Germany',
            'device' => 'desktop',
            'is_return_visitor' => true,
            'is_bot' => false,
        ]);

        // Dentro do filtro (Brasil): 10 cliques — 7 desktop (4 recorrentes,
        // 3 não) / 3 mobile (não recorrentes).
        Click::factory()->count(4)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'device' => 'desktop',
            'is_return_visitor' => true,
            'is_bot' => false,
        ]);
        Click::factory()->count(3)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'device' => 'desktop',
            'is_return_visitor' => false,
            'is_bot' => false,
        ]);
        Click::factory()->count(3)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'device' => 'mobile',
            'is_return_visitor' => false,
            'is_bot' => false,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?country=Brazil")
            ->assertOk();

        $insights = collect($response->json('data.insights'));

        $device = $insights->firstWhere('title_key', 'insights.generators.device.dominant.title');
        $this->assertNotNull($device, 'O insight de dispositivo deveria existir.');
        $this->assertSame('desktop', $device['description_params']['device']);
        // 7 desktop / 10 totais no Brasil = 70%. Sem o filtro vazaria para
        // 27 desktop (20 Alemanha + 7 Brasil) / 10 = 270%.
        $this->assertEquals(70.0, $device['description_params']['pct']);
        $this->assertLessThanOrEqual(100.0, $device['description_params']['pct']);

        $retention = $insights->firstWhere('title_key', 'insights.generators.retention.rate.title');
        $this->assertNotNull($retention, 'O insight de retenção deveria existir.');
        // 4 recorrentes / 10 totais no Brasil = 40%. Sem o filtro vazaria
        // para 24 (20 Alemanha + 4 Brasil) / 10 = 240%.
        $this->assertEquals(40.0, $retention['description_params']['rate']);
        $this->assertLessThanOrEqual(100.0, $retention['description_params']['rate']);
    }

    /**
     * GeographicInsightGenerator precisa calcular o país dominante sobre o
     * subconjunto filtrado por `device=mobile`, não sobre todos os cliques do
     * link. Usa uma dimensão diferente da testada em cima (device em vez de
     * country) porque filtrar por country tornaria o próprio teste do
     * gerador geográfico degenerado (o país filtrado sempre bateria 100%).
     */
    public function test_geographic_insight_percentage_respects_the_device_filter(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        // Fora do filtro: 30 cliques desktop, todos Alemanha.
        Click::factory()->count(30)->create([
            'link_id' => $link->id,
            'country' => 'Germany',
            'device' => 'desktop',
            'is_bot' => false,
        ]);

        // Dentro do filtro (mobile): 6 França + 4 Brasil.
        Click::factory()->count(6)->create([
            'link_id' => $link->id,
            'country' => 'France',
            'device' => 'mobile',
            'is_bot' => false,
        ]);
        Click::factory()->count(4)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'device' => 'mobile',
            'is_bot' => false,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?device=mobile")
            ->assertOk();

        $geo = collect($response->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.geographic.mainMarket.title');

        $this->assertNotNull($geo, 'O insight geográfico deveria existir.');
        $this->assertSame('France', $geo['description_params']['country']);
        // 6 França / 10 móveis = 60%. Sem o filtro de device vazaria para
        // 30 (Alemanha, inteiramente fora do recorte) / 10 = 300%.
        $this->assertEquals(60.0, $geo['description_params']['pct']);
        $this->assertLessThanOrEqual(100.0, $geo['description_params']['pct']);
    }

    /**
     * EngagementInsightGenerator usa applyDimensions() DE PROPÓSITO — ele
     * compara os últimos 7 dias contra os 7 anteriores e possui janela
     * própria, então aplicar `date_from` por cima destruiria a comparação.
     * Este teste prova as duas metades desse contrato ao mesmo tempo:
     *   1. a dimensão `country=Brazil` É aplicada às duas janelas (o rate
     *      esperado só bate se ambas as contagens estiverem filtradas);
     *   2. `date_from` NÃO é aplicado: ele é escolhido para cair DENTRO da
     *      janela recente, de forma que se o gerador trocasse
     *      applyDimensions() por applyToQuery(), a janela "anterior"
     *      (8-14 dias atrás) seria zerada e o insight desapareceria.
     */
    public function test_engagement_insight_applies_dimensions_but_preserves_its_own_window(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        // Janela recente (últimos 7 dias): 12 Brasil + 3 Alemanha.
        Click::factory()->count(12)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'is_bot' => false,
            'created_at' => now()->subDays(2),
        ]);
        Click::factory()->count(3)->create([
            'link_id' => $link->id,
            'country' => 'Germany',
            'is_bot' => false,
            'created_at' => now()->subDays(2),
        ]);

        // Janela anterior (8-14 dias atrás): 4 Brasil + 10 Alemanha.
        Click::factory()->count(4)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'is_bot' => false,
            'created_at' => now()->subDays(10),
        ]);
        Click::factory()->count(10)->create([
            'link_id' => $link->id,
            'country' => 'Germany',
            'is_bot' => false,
            'created_at' => now()->subDays(10),
        ]);

        // Dentro da janela recente (5 dias atrás) — não pode "vazar" para a
        // janela anterior do gerador.
        $dateFrom = now()->subDays(5)->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?country=Brazil&date_from={$dateFrom}")
            ->assertOk();

        $engagement = collect($response->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.engagement.positiveGrowth.title');

        $this->assertNotNull(
            $engagement,
            'O insight de engajamento deveria existir — a janela própria (7d/14d) não pode ser zerada pelo date_from.'
        );
        // Recente (Brasil) = 12, anterior (Brasil) = 4 => (12-4)/4*100 = 200%.
        // Sem a dimensão country aplicada às janelas, seria 15 recentes vs 14
        // anteriores => ~7,1%, abaixo do limiar de 20% que dispara o insight.
        $this->assertEquals(200.0, $engagement['description_params']['rate']);
    }
}
