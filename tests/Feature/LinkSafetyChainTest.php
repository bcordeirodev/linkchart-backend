<?php

namespace Tests\Feature;

use App\Services\Links\LinkSafetyService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobre o bypass por encurtador encadeado.
 *
 * `checkUrl` verificava apenas a URL enviada. Com um link apontando para outro
 * encurtador, as duas camadas (heurística local e Safe Browsing) analisavam o
 * **intermediário** — que é sempre limpo — e nunca o destino real. Em produção já
 * havia links assim: `SimularConsignado.s.gy`, `shre.ink`, `5664.in`, além de
 * cadeias internas (`thyqic → ochncy → e2vkdm → usqacg`).
 *
 * A resolução da cadeia é um seam `protected` porque fazer rede em teste de
 * unidade seria lento e frágil.
 */
class LinkSafetyChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_safe_browsing.key' => 'chave-de-teste']);
    }

    /**
     * O destino final carrega token de marca; o intermediário é limpo. Antes,
     * passava.
     */
    public function test_blocks_when_chain_ends_in_brand_impersonation(): void
    {
        Http::fake(['*' => Http::response(['matches' => []], 200)]);

        $service = new ChainResolvingSafetyService('https://nubank-verificar.exemplo.test/login');

        $result = $service->checkUrl('https://s.gy/my-link');

        $this->assertFalse($result['safe']);
    }

    /**
     * O Safe Browsing precisa receber a cadeia inteira, não só o intermediário —
     * uma única chamada, porque threatEntries aceita múltiplas URLs.
     */
    public function test_safe_browsing_receives_the_whole_chain(): void
    {
        Http::fake(['*' => Http::response(['matches' => []], 200)]);

        $service = new ChainResolvingSafetyService('https://destino-final.exemplo.test/pagina');

        $service->checkUrl('https://shre.ink/abc');

        Http::assertSent(function ($request) {
            $entries = $request['threatInfo']['threatEntries'] ?? [];
            $urls = array_column($entries, 'url');

            return count($entries) === 2
                && in_array('https://shre.ink/abc', $urls, true)
                && in_array('https://destino-final.exemplo.test/pagina', $urls, true);
        });
    }

    public function test_flags_when_safe_browsing_hits_the_final_destination(): void
    {
        Http::fake(['*' => Http::response([
            'matches' => [['threatType' => 'SOCIAL_ENGINEERING']],
        ], 200)]);

        $service = new ChainResolvingSafetyService('https://destino-final.exemplo.test/pagina');

        $result = $service->checkUrl('https://5664.in/xyz');

        $this->assertFalse($result['safe']);
    }

    /**
     * Host que não é encurtador conhecido não gera resolução nenhuma: uma URL só,
     * uma entrada no GSB, e exatamente uma requisição de saída. É o que impede a
     * criação de link de virar um buscador de URL arbitrária.
     */
    public function test_non_shortener_host_is_never_resolved(): void
    {
        Http::fake(['*' => Http::response(['matches' => []], 200)]);

        $service = new ChainResolvingSafetyService('https://nubank-verificar.exemplo.test/login');

        $result = $service->checkUrl('https://exemplo.test/pagina-direta');

        $this->assertTrue($result['safe']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => count($request['threatInfo']['threatEntries'] ?? []) === 1);
    }

    /**
     * Se a resolução da cadeia falhar, seguimos verificando a URL enviada. Não
     * criar um modo de falha bloqueante novo num caminho de usuário.
     */
    public function test_failed_resolution_falls_back_to_the_submitted_url(): void
    {
        Http::fake(['*' => Http::response(['matches' => []], 200)]);

        $service = new FailingChainSafetyService;

        $result = $service->checkUrl('https://bit.ly/abc');

        $this->assertTrue($result['safe']);
        Http::assertSent(fn ($request) => count($request['threatInfo']['threatEntries'] ?? []) === 1);
    }
}

/**
 * Test double: devolve um destino final fixo, sem tocar a rede.
 */
class ChainResolvingSafetyService extends LinkSafetyService
{
    /**
     * @param  string|null  $final  Destino final a simular; null = sem redirect.
     */
    public function __construct(private ?string $final) {}

    protected function resolveFinalUrl(string $url): ?string
    {
        return $this->final;
    }
}

/**
 * Test double: simula falha na resolução da cadeia.
 */
class FailingChainSafetyService extends LinkSafetyService
{
    protected function resolveFinalUrl(string $url): ?string
    {
        return null;
    }
}
