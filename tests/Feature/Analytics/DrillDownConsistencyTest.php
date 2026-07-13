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

    /**
     * DiversityInsightGenerator só dispara com mais de 5 países distintos.
     * Sem filtro, o link atinge 8 países (acima do limiar); filtrando por
     * `country=Brazil`, o recorte cai para 1 único país e o insight precisa
     * desaparecer. Se um refactor futuro trocar o `applyToQuery()` do
     * gerador pela query global, ele voltaria a contar os 8 países mesmo sob
     * o filtro — a ausência do insight é a asserção que pega essa regressão,
     * já que o gerador não produz percentual (só um número absoluto de
     * países, que nenhum outro teste desta classe verifica).
     */
    public function test_diversity_insight_disappears_when_the_country_filter_narrows_to_one_country(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        // 7 países fora do filtro, 1 clique cada — soma ao Brasil para ficar
        // acima do limiar de 5 quando não há filtro algum.
        foreach (['Germany', 'France', 'Italy', 'Spain', 'Portugal', 'Japan', 'Canada'] as $country) {
            Click::factory()->create([
                'link_id' => $link->id,
                'country' => $country,
                'is_bot' => false,
            ]);
        }

        // Único país dentro do filtro.
        Click::factory()->count(3)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'is_bot' => false,
        ]);

        $unfiltered = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights")
            ->assertOk();

        $filtered = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?country=Brazil")
            ->assertOk();

        $diversityUnfiltered = collect($unfiltered->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.diversity.international.title');
        $this->assertNotNull($diversityUnfiltered, 'Sem filtro, 8 países deveriam disparar o insight de diversidade.');
        $this->assertSame(8, $diversityUnfiltered['description_params']['countries']);

        $diversityFiltered = collect($filtered->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.diversity.international.title');
        $this->assertNull(
            $diversityFiltered,
            'Com country=Brazil o recorte tem 1 único país; o insight de diversidade não deveria existir.'
        );
    }

    /**
     * SecurityInsightGenerator só dispara quando um IP ultrapassa 50 cliques
     * DENTRO do recorte filtrado. O mesmo IP aparece em dois países: 51
     * cliques no Brasil (cruza o limiar isoladamente) e 20 na Alemanha (fica
     * abaixo). Filtrando por Alemanha, o insight não pode aparecer — se o
     * filtro por país for removido do gerador, a contagem global do IP (71)
     * cruzaria o limiar e o insight vazaria para o recorte errado.
     */
    public function test_security_insight_ip_threshold_respects_the_country_filter(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        Click::factory()->count(51)->create([
            'link_id' => $link->id,
            'ip' => '10.0.0.1',
            'country' => 'Brazil',
            'is_bot' => false,
        ]);

        Click::factory()->count(20)->create([
            'link_id' => $link->id,
            'ip' => '10.0.0.1',
            'country' => 'Germany',
            'is_bot' => false,
        ]);

        $brazil = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?country=Brazil")
            ->assertOk();

        $germany = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?country=Germany")
            ->assertOk();

        $securityBrazil = collect($brazil->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.security.suspicious.title');
        $this->assertNotNull($securityBrazil, 'No Brasil o IP tem 51 cliques, deveria disparar o alerta de segurança.');
        $this->assertSame(1, $securityBrazil['description_params']['count']);

        $securityGermany = collect($germany->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.security.suspicious.title');
        $this->assertNull(
            $securityGermany,
            'Na Alemanha o mesmo IP tem só 20 cliques; sem o filtro por país vazaria o total global (71) e disparia indevidamente.'
        );
    }

    /**
     * TemporalInsightGenerator reporta o horário de pico agrupando pelas
     * horas dos cliques. Fora do filtro (Alemanha), o pico é às 3h com 20
     * cliques — mais que qualquer hora dentro do filtro. Dentro do filtro
     * (Brasil), o pico real é às 14h (8 cliques). Se o `applyToQuery()` for
     * removido do gerador, o pico reportado sob country=Brazil viraria 3h,
     * dominado pelos cliques da Alemanha.
     */
    public function test_temporal_insight_peak_hour_respects_the_country_filter(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        // Fora do filtro: pico às 3h, mais cliques que qualquer hora do Brasil.
        Click::factory()->count(20)->create([
            'link_id' => $link->id,
            'country' => 'Germany',
            'is_bot' => false,
            'created_at' => now()->setTime(3, 0, 0),
        ]);

        // Dentro do filtro: pico real às 14h.
        Click::factory()->count(8)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'is_bot' => false,
            'created_at' => now()->setTime(14, 0, 0),
        ]);
        Click::factory()->count(2)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'is_bot' => false,
            'created_at' => now()->setTime(5, 0, 0),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?country=Brazil")
            ->assertOk();

        $temporal = collect($response->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.temporal.peakHour.title');

        $this->assertNotNull($temporal, 'O insight de horário de pico deveria existir.');
        $this->assertEquals(
            14,
            $temporal['description_params']['hour'],
            'Sem o filtro por país o pico reportado seria 3h (dominado pelos cliques da Alemanha).'
        );
    }

    /**
     * PerformanceInsightGenerator reporta o tempo médio de resposta. Fora do
     * filtro (Alemanha), os cliques são lentos (900ms); dentro do filtro
     * (Brasil), são rápidos (100ms). Se o filtro por país for removido do
     * gerador, a média misturaria os dois grupos (~740ms) e tanto o valor
     * quanto o título (bom vs. lento) mudariam sob country=Brazil.
     */
    public function test_performance_insight_average_response_time_respects_the_country_filter(): void
    {
        $link = Link::factory()->create(['user_id' => $this->user->id]);

        Click::factory()->count(20)->create([
            'link_id' => $link->id,
            'country' => 'Germany',
            'is_bot' => false,
            'response_time' => 900,
        ]);

        Click::factory()->count(5)->create([
            'link_id' => $link->id,
            'country' => 'Brazil',
            'is_bot' => false,
            'response_time' => 100,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson("/api/analytics/link/{$link->id}/insights?country=Brazil")
            ->assertOk();

        $performance = collect($response->json('data.insights'))
            ->firstWhere('title_key', 'insights.generators.performance.good.title');

        $this->assertNotNull(
            $performance,
            'Filtrado por Brasil a média é 100ms (boa performance); sem o filtro viraria ~740ms e o título mudaria para "lento".'
        );
        $this->assertEquals(100.0, $performance['description_params']['avg']);
    }
}
