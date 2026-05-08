<?php

namespace Tests\Feature;

use App\Jobs\ProcessLinkClickJob;
use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

class RedirectTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    private const HUMAN_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response('<html><head><title>Fake</title></head><body/></html>', 200),
        ]);
    }

    public function test_human_visitor_receives_302_redirect_to_original_url(): void
    {
        Queue::fake();
        $link = $this->makeLink(['original_url' => 'https://example.com/destino']);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://example.com/destino');
    }

    public function test_human_visitor_dispatches_tracking_job_with_expected_payload(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        $this->withHeaders([
            'User-Agent' => self::HUMAN_UA,
            'Referer' => 'https://twitter.com/foo',
            'Accept-Language' => 'pt-BR,pt;q=0.9',
        ])->get('/r/'.$link->slug.'?utm_source=twitter&utm_medium=social&ignored=x');

        Queue::assertPushed(ProcessLinkClickJob::class, function (ProcessLinkClickJob $job) use ($link) {
            $this->assertSame($link->id, $job->linkId);
            $this->assertSame(self::HUMAN_UA, $job->payload['user_agent']);
            $this->assertSame('https://twitter.com/foo', $job->payload['referer']);
            $this->assertSame('pt-BR,pt;q=0.9', $job->payload['accept_language']);
            $this->assertSame([
                'utm_source' => 'twitter',
                'utm_medium' => 'social',
            ], $job->payload['query_params']);
            $this->assertArrayNotHasKey('ignored', $job->payload['query_params']);
            $this->assertIsFloat($job->payload['http_response_ms']);

            return true;
        });
    }

    public function test_human_visitor_increments_clicks_counter(): void
    {
        // Não usa Queue::fake() — com QUEUE_CONNECTION=sync o job roda inline.
        // O increment acontece dentro do job, após Click::create com sucesso.
        $link = $this->makeLink(['clicks' => 0]);

        $this->withHeaders(['User-Agent' => self::HUMAN_UA])->get('/r/'.$link->slug);

        $this->assertSame(1, (int) DB::table('links')->where('id', $link->id)->value('clicks'));
    }

    public function test_click_limit_returns_error_page(): void
    {
        Queue::fake();
        $link = $this->makeLink(['click_limit' => 5, 'clicks' => 5]);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])->get('/r/'.$link->slug);

        $response->assertStatus(404);
        $this->assertStringContainsString('atingiu o limite de cliques', $response->getContent());
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_whatsapp_bot_receives_html_with_open_graph_tags(): void
    {
        Queue::fake();
        $link = $this->makeLink(['original_url' => 'https://example.com/landing']);

        $response = $this->withHeaders([
            'User-Agent' => 'WhatsApp/2.23.24.76 A',
        ])->get('/r/'.$link->slug);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('<meta property="og:type" content="website">', $response->getContent());
        $this->assertStringContainsString('<meta property="og:url" content="https://example.com/landing">', $response->getContent());
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_telegram_bot_receives_html_without_redirect(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        $response = $this->withHeaders([
            'User-Agent' => 'TelegramBot (like TwitterBot)',
        ])->get('/r/'.$link->slug);

        $response->assertStatus(200);
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_preview_query_param_serves_html_without_tracking(): void
    {
        Queue::fake();
        $link = $this->makeLink(['clicks' => 0]);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug.'?preview=1');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        Queue::assertNotPushed(ProcessLinkClickJob::class);
        $this->assertSame(0, (int) DB::table('links')->where('id', $link->id)->value('clicks'));
    }

    public function test_nonexistent_slug_returns_error_page(): void
    {
        Queue::fake();

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/does-not-exist');

        $response->assertStatus(404);
        $this->assertStringContainsString('Link não encontrado', $response->getContent());
        Queue::assertNothingPushed();
    }

    public function test_inactive_link_returns_error_page(): void
    {
        Queue::fake();
        $link = $this->makeLink(['is_active' => false]);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(404);
        $this->assertStringContainsString('Link não encontrado', $response->getContent());
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_expired_link_returns_error_page(): void
    {
        Queue::fake();
        $link = $this->makeLink(['expires_at' => now()->subDay()]);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(404);
        $this->assertStringContainsString('Este link expirou', $response->getContent());
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_link_not_yet_started_returns_error_page(): void
    {
        Queue::fake();
        $link = $this->makeLink(['starts_in' => now()->addDay()]);

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(404);
        $this->assertStringContainsString('Este link ainda não está disponível', $response->getContent());
        Queue::assertNotPushed(ProcessLinkClickJob::class);
    }

    public function test_error_page_contains_back_to_frontend_button(): void
    {
        Queue::fake();

        $response = $this->get('/r/missing-slug');

        $response->assertStatus(404);
        $this->assertMatchesRegularExpression(
            '/<a\s+href="[^"]+"\s+class="btn">\s*Voltar à Página Inicial\s*<\/a>/u',
            $response->getContent()
        );
    }

    public function test_cached_link_lookup_is_used_on_repeated_requests(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        $this->withHeaders(['User-Agent' => self::HUMAN_UA])->get('/r/'.$link->slug);

        $this->assertTrue(Cache::has(Link::slugCacheKey($link->slug)));

        $cached = Cache::get(Link::slugCacheKey($link->slug));
        $this->assertInstanceOf(Link::class, $cached);
        $this->assertSame($link->id, $cached->id);
    }

    public function test_response_carries_x_request_id_header(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])->get('/r/'.$link->slug);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertStringStartsWith('req_', $response->headers->get('X-Request-Id'));
    }

    public function test_x_request_id_is_propagated_to_dispatched_job_payload(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        $response = $this->withHeaders(['User-Agent' => self::HUMAN_UA])->get('/r/'.$link->slug);
        $rid = $response->headers->get('X-Request-Id');

        Queue::assertPushed(ProcessLinkClickJob::class, function (ProcessLinkClickJob $job) use ($rid) {
            $this->assertSame($rid, $job->payload['request_id'] ?? null);

            return true;
        });
    }

    public function test_inbound_x_request_id_header_is_reused(): void
    {
        Queue::fake();
        $link = $this->makeLink();

        $response = $this->withHeaders([
            'User-Agent' => self::HUMAN_UA,
            'X-Request-Id' => 'req_external_abc',
        ])->get('/r/'.$link->slug);

        $this->assertSame('req_external_abc', $response->headers->get('X-Request-Id'));

        Queue::assertPushed(ProcessLinkClickJob::class, function (ProcessLinkClickJob $job) {
            $this->assertSame('req_external_abc', $job->payload['request_id'] ?? null);

            return true;
        });
    }
}
