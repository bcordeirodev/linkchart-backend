<?php

namespace Tests\Feature;

use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestLinks;
use Tests\TestCase;

/**
 * Characterization tests that lock the CURRENT rendered bytes of the
 * bot/preview interstitial page and the 404 error page served by
 * {@see \App\Http\Controllers\Links\RedirectController}.
 *
 * These tests exist to make the HTML-to-Blade refactor (and any future change
 * to the interstitial/error markup) provably output-preserving. They assert the
 * exact bytes the controller emits today — including the context-aware escaping
 * (e() for HTML text, htmlspecialchars ENT_QUOTES|ENT_HTML5 for attributes,
 * json_encode JSON_HEX_* for the JS target literal). If a refactor changes even
 * one byte of the relevant fragments, these assertions fail.
 *
 * The OG metadata fetch is stubbed via Http::fake so the rendered fields are
 * fully deterministic and controlled by the test, not by the network.
 */
class RedirectPreviewCharacterizationTest extends TestCase
{
    use CreatesTestLinks, RefreshDatabase;

    private const BOT_UA = 'WhatsApp/2.23.24.76 A';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Replace the Http fake stub callbacks with a single deterministic HTML
     * response so the fetched OG metadata is fully controlled by the test.
     */
    private function fakeFetchHtml(string $html): void
    {
        Http::fake(['*' => Http::response($html, 200)]);
    }

    /**
     * Bot preview path renders the full OG + Twitter meta-tag block with the
     * fetched og:title / og:description / og:image and the link's original_url.
     */
    public function test_bot_preview_renders_expected_open_graph_block(): void
    {
        Queue::fake();
        $this->fakeFetchHtml(
            '<html><head>'
            .'<meta property="og:title" content="My Product"/>'
            .'<meta property="og:description" content="Best product ever"/>'
            .'<meta property="og:image" content="https://cdn.example.com/img.jpg"/>'
            .'</head><body/></html>'
        );

        $link = $this->makeLink(['original_url' => 'https://example.com/landing']);

        $response = $this->withHeaders(['User-Agent' => self::BOT_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        // Symfony normalises/sorts the Cache-Control directives and adds `private`.
        $response->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
        $response->assertHeader('Pragma', 'no-cache');
        $response->assertHeader('Expires', '0');

        $content = $response->getContent();

        // og:image was fetched (no fallback needed) so the bot delay is 5s in the
        // <meta refresh>, while the visible countdown / JS are hard-coded to 2s.
        $this->assertStringContainsString(
            '<meta http-equiv="refresh" content="5;url=https://example.com/landing">',
            $content
        );

        // Open Graph block — exact bytes.
        $this->assertStringContainsString('<meta property="og:type" content="website">', $content);
        $this->assertStringContainsString('<meta property="og:url" content="https://example.com/landing">', $content);
        $this->assertStringContainsString('<meta property="og:title" content="My Product">', $content);
        $this->assertStringContainsString('<meta property="og:description" content="Best product ever">', $content);
        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example.com/img.jpg">', $content);

        // Twitter block — exact bytes.
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $content);
        $this->assertStringContainsString('<meta name="twitter:url" content="https://example.com/landing">', $content);
        $this->assertStringContainsString('<meta name="twitter:title" content="My Product">', $content);
        $this->assertStringContainsString('<meta name="twitter:description" content="Best product ever">', $content);
        $this->assertStringContainsString('<meta name="twitter:image" content="https://cdn.example.com/img.jpg">', $content);

        // Canonical + site metadata.
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/landing">', $content);
        $this->assertStringContainsString('<meta property="og:site_name" content="LinkChart">', $content);
        $this->assertStringContainsString('<meta property="og:locale" content="pt_BR">', $content);

        // Page chrome.
        $this->assertStringContainsString('<title>My Product</title>', $content);
        $this->assertStringContainsString('<h1>My Product</h1>', $content);
        $this->assertStringContainsString('<a href="https://example.com/landing" class="btn">Ir Agora</a>', $content);
        $this->assertStringContainsString('🔗 Powered by LinkChart', $content);
        // json_encode with JSON_HEX_* also escapes forward slashes (default behaviour).
        $jsUrl = json_encode('https://example.com/landing', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->assertStringContainsString('window.location.href = '.$jsUrl.';', $content);
        $this->assertStringContainsString('window.location.replace('.$jsUrl.');', $content);
    }

    /**
     * YouTube oEmbed path supplies og_image_width/height, which must emit the
     * og:image:width / og:image:height tags. Locks that conditional fragment.
     */
    public function test_youtube_oembed_renders_image_dimension_tags(): void
    {
        Queue::fake();
        Http::fake([
            'youtube.com/oembed*' => Http::response([
                'title' => 'A Great Video',
                'author_name' => 'Some Channel',
                'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            ], 200),
            '*' => Http::response('<html></html>', 200),
        ]);

        $link = $this->makeLink(['original_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        $response = $this->withHeaders(['User-Agent' => self::BOT_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('<meta property="og:type" content="video.other">', $content);
        $this->assertStringContainsString('<meta property="og:title" content="A Great Video">', $content);
        $this->assertStringContainsString('<meta property="og:description" content="Por Some Channel • YouTube">', $content);
        $this->assertStringContainsString(
            '<meta property="og:image" content="https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg">',
            $content
        );
        $this->assertStringContainsString('<meta property="og:image:width" content="1280">', $content);
        $this->assertStringContainsString('<meta property="og:image:height" content="720">', $content);
    }

    /**
     * XSS safety: malicious characters in the original_url and the fetched
     * og:title / og:description must appear escaped in the output exactly as
     * they are today. Captures the actual current escaped bytes per context:
     *   - HTML text (h1/title)           -> e()  : < > & " ' encoded
     *   - attribute (meta/href)          -> htmlspecialchars ENT_QUOTES|ENT_HTML5
     *   - JS literal (window.location)   -> json_encode JSON_HEX_*
     */
    public function test_xss_payloads_are_escaped_in_output(): void
    {
        Queue::fake();
        $this->fakeFetchHtml(
            '<html><head>'
            .'<meta property="og:title" content="Hi &quot;you&quot; &amp; me"/>'
            .'<meta property="og:description" content="d&lt;script&gt;d"/>'
            .'</head><body/></html>'
        );

        // original_url carries quote/angle-bracket/script payload. Stored directly
        // (factory create — no HTTP validation), so it reaches the renderer raw.
        $maliciousUrl = 'https://evil.test/p?a="><script>alert(1)</script>&b=\'x\'';
        $link = $this->makeLink(['original_url' => $maliciousUrl]);

        $response = $this->withHeaders(['User-Agent' => self::BOT_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(200);
        $content = $response->getContent();

        // --- HTML text context (og:title via e()) ---
        // og:title decoded from the fetched HTML entities is: Hi "you" & me
        // e() encodes: " -> &quot;  & -> &amp;  (single quotes stay; ENT_QUOTES on e())
        $expectedTitleHtml = e('Hi "you" & me');
        $this->assertStringContainsString('<h1>'.$expectedTitleHtml.'</h1>', $content);
        $this->assertStringContainsString('<title>'.$expectedTitleHtml.'</title>', $content);
        // Raw <script> from the title must NOT be present unescaped.
        $this->assertStringNotContainsString('<h1>Hi "you"', $content);

        // --- description via e() (decoded: d<script>d) ---
        $expectedDescHtml = e('d<script>d');
        $this->assertStringContainsString('content="'.$expectedDescHtml.'">', $content);
        $this->assertStringNotContainsString('content="d<script>d">', $content);

        // --- attribute context (original_url via htmlspecialchars ENT_QUOTES|ENT_HTML5) ---
        $expectedAttrUrl = htmlspecialchars($maliciousUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('<meta property="og:url" content="'.$expectedAttrUrl.'">', $content);
        $this->assertStringContainsString('<link rel="canonical" href="'.$expectedAttrUrl.'">', $content);
        // The raw </script> from the URL must not break out into a real script close.
        $this->assertStringNotContainsString('content="https://evil.test/p?a="><script>', $content);

        // --- JS literal context (original_url via json_encode JSON_HEX_*) ---
        $expectedJsUrl = json_encode($maliciousUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $this->assertStringContainsString('window.location.href = '.$expectedJsUrl.';', $content);
        $this->assertStringContainsString('window.location.replace('.$expectedJsUrl.');', $content);
        // JSON_HEX_TAG must have neutralised the </script> sequence inside the JS.
        $this->assertStringNotContainsString('alert(1)</script>', $content);
    }

    /**
     * The 404 error page renders the escaped message and the frontend URL link
     * with the exact markup the back-to-home regression test depends on.
     */
    public function test_error_page_renders_escaped_message_and_frontend_link(): void
    {
        Queue::fake();

        $response = $this->withHeaders(['User-Agent' => self::BOT_UA])
            ->get('/r/does-not-exist');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $content = $response->getContent();
        $frontendUrl = config('app.frontend_url');

        $this->assertStringContainsString('<title>Link não encontrado</title>', $content);
        $this->assertStringContainsString('<div class="icon">❌</div>', $content);
        $this->assertStringContainsString('<h1>Oops!</h1>', $content);
        $this->assertStringContainsString('<p>'.e('Link não encontrado ou inativo').'</p>', $content);
        $this->assertStringContainsString(
            '<a href="'.$frontendUrl.'" class="btn">Voltar à Página Inicial</a>',
            $content
        );
    }

    /**
     * Locks the full byte-for-byte interstitial document for a fixed input so a
     * Blade refactor can be proven identical. Uses a deterministic fetched
     * metadata set and a stable original_url. The bot refresh delay is 5s.
     */
    public function test_full_interstitial_document_is_byte_stable(): void
    {
        Queue::fake();
        $this->fakeFetchHtml(
            '<html><head>'
            .'<meta property="og:title" content="Stable Title"/>'
            .'<meta property="og:description" content="Stable Desc"/>'
            .'<meta property="og:image" content="https://cdn.example.com/i.png"/>'
            .'</head><body/></html>'
        );

        $link = $this->makeLink(['original_url' => 'https://example.com/stable']);

        $response = $this->withHeaders(['User-Agent' => self::BOT_UA])
            ->get('/r/'.$link->slug);

        $response->assertStatus(200);

        // Persist a golden snapshot on first run; assert equality thereafter.
        $goldenPath = __DIR__.'/__snapshots__/redirect_interstitial.html';
        @mkdir(dirname($goldenPath), 0o777, true);
        if (! file_exists($goldenPath)) {
            file_put_contents($goldenPath, $response->getContent());
            $this->markTestIncomplete('Golden snapshot created; re-run to assert byte equality.');
        }

        $this->assertSame(
            file_get_contents($goldenPath),
            $response->getContent(),
            'Interstitial HTML changed byte-for-byte vs golden snapshot.'
        );
    }

    /**
     * Locks the full byte-for-byte error document against a golden snapshot.
     */
    public function test_full_error_document_is_byte_stable(): void
    {
        Queue::fake();

        $response = $this->withHeaders(['User-Agent' => self::BOT_UA])
            ->get('/r/does-not-exist');

        $response->assertStatus(404);

        $goldenPath = __DIR__.'/__snapshots__/redirect_error.html';
        @mkdir(dirname($goldenPath), 0o777, true);
        if (! file_exists($goldenPath)) {
            file_put_contents($goldenPath, $response->getContent());
            $this->markTestIncomplete('Golden snapshot created; re-run to assert byte equality.');
        }

        $this->assertSame(
            file_get_contents($goldenPath),
            $response->getContent(),
            'Error HTML changed byte-for-byte vs golden snapshot.'
        );
    }
}
