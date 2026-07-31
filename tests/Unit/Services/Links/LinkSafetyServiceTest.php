<?php

namespace Tests\Unit\Services\Links;

use App\Logging\AppLogger;
use App\Services\Links\LinkSafetyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Covers LinkSafetyService::checkUrl, the Google Safe Browsing v4 integration.
 *
 * The key behaviour under test is the diagnosability of API failures: when the
 * upstream returns a non-2xx response the service must fail open AND log the
 * real HTTP status and response body, so a misconfigured production key surfaces
 * its actual cause (403 PERMISSION_DENIED, 400 API_KEY_INVALID, 429, ...) instead
 * of an opaque "safety_api_error_response".
 */
class LinkSafetyServiceTest extends TestCase
{
    /**
     * A non-2xx response fails open and logs the upstream status + body.
     */
    public function test_non_2xx_response_fails_open_and_logs_status_and_body(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);

        Http::fake([
            '*' => Http::response(
                '{"error":{"code":403,"message":"Safe Browsing API has not been used","status":"PERMISSION_DENIED"}}',
                403,
            ),
        ]);

        $captured = [];
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(function (string $event, array $context = []) use (&$captured) {
            $captured = ['event' => $event, 'context' => $context];

            return true;
        });

        $result = (new LinkSafetyService)->checkUrl('https://www.google.com');

        // Fail-open contract preserved.
        $this->assertTrue($result['safe']);
        $this->assertFalse($result['api_available']);

        // The diagnostic context now carries the real status and body.
        $this->assertSame(AppLogger::SAFETY_API_ERROR, $captured['event']);
        $this->assertSame(403, $captured['context']['status'] ?? null);
        $this->assertStringContainsString('PERMISSION_DENIED', (string) ($captured['context']['body'] ?? ''));
    }

    /**
     * A 200 response containing a threat match flags the URL as unsafe.
     */
    public function test_match_flags_url_as_unsafe(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);

        Http::fake([
            '*' => Http::response(
                '{"matches":[{"threatType":"SOCIAL_ENGINEERING"}]}',
                200,
            ),
        ]);

        $result = (new LinkSafetyService)->checkUrl('https://phishing.example');

        $this->assertFalse($result['safe']);
        $this->assertTrue($result['api_available']);
        $this->assertContains('phishing', $result['threats']);
    }

    /**
     * A missing API key skips the check and treats the URL as safe (fail-open).
     */
    public function test_missing_key_skips_check_and_makes_no_http_call(): void
    {
        config(['services.google_safe_browsing.key' => null]);

        Http::fake();

        $result = (new LinkSafetyService)->checkUrl('https://www.google.com');

        Http::assertNothingSent();
        $this->assertTrue($result['safe']);
        $this->assertFalse($result['api_available']);
    }

    /**
     * Otel::recordSafetyCheck is callable without throwing when telemetry is disabled.
     */
    public function test_record_safety_check_is_noop_when_disabled(): void
    {
        config(['otel.enabled' => false]);
        \App\Observability\Otel::recordSafetyCheck('bad_response');
        $this->addToAssertionCount(1);
    }

    // ============================================================
    // Layer 1 — local lexical/brand heuristic (runs before Safe Browsing)
    // ============================================================

    /**
     * A brand token in the host whose registrable domain is not the brand's
     * official domain is blocked locally, without ever calling Safe Browsing.
     * This is the exact shape of the phishing abuse seen in production
     * (instagram-passwords-leaks.vercel.app).
     */
    public function test_brand_impersonation_is_blocked_before_reaching_safe_browsing(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake();

        $result = (new LinkSafetyService)->checkUrl('https://instagram-passwords-leaks.vercel.app/?id=8887167178');

        Http::assertNothingSent();
        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['threats']);
    }

    /**
     * A legitimate subdomain of the official brand domain passes the heuristic
     * and proceeds to the Safe Browsing check (which here returns clean).
     */
    public function test_official_brand_subdomain_passes_heuristic_and_reaches_safe_browsing(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake(['*' => Http::response('{}', 200)]);

        $result = (new LinkSafetyService)->checkUrl('https://www.instagram.com/someprofile');

        Http::assertSentCount(1);
        $this->assertTrue($result['safe']);
        $this->assertTrue($result['api_available']);
    }

    /**
     * A high-signal compound keyword in the host is blocked locally.
     */
    public function test_keyword_denylist_blocks_locally(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake();

        $result = (new LinkSafetyService)->checkUrl('https://free-robux-generator.example.com');

        Http::assertNothingSent();
        $this->assertFalse($result['safe']);
    }

    /**
     * The heuristic protects even when the Safe Browsing key is absent — it must
     * run before the missing-key fail-open early return.
     */
    public function test_heuristic_blocks_even_without_api_key(): void
    {
        config(['services.google_safe_browsing.key' => null]);
        Http::fake();

        $result = (new LinkSafetyService)->checkUrl('https://instagram-passwords-leaks.vercel.app');

        Http::assertNothingSent();
        $this->assertFalse($result['safe']);
    }

    /**
     * With the heuristic disabled via config, a would-be-flagged URL falls
     * through to the Safe Browsing layer instead of being blocked locally.
     */
    public function test_heuristic_can_be_disabled_via_config(): void
    {
        config([
            'services.google_safe_browsing.key' => 'test-key',
            'link_safety.heuristic_enabled' => false,
        ]);
        Http::fake(['*' => Http::response('{}', 200)]);

        $result = (new LinkSafetyService)->checkUrl('https://instagram-passwords-leaks.vercel.app');

        Http::assertSentCount(1);
        $this->assertTrue($result['safe']);
    }

    /**
     * Brand tokens embedded inside an unrelated word must NOT trigger the brand
     * rule (boundary-aware match): "caixa" in "caixadagua", "steam" in "steampunk".
     *
     * @dataProvider brandSubstringFalsePositives
     */
    public function test_brand_token_inside_unrelated_word_is_not_flagged(string $url): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake(['*' => Http::response('{}', 200)]);

        $result = (new LinkSafetyService)->checkUrl($url);

        Http::assertSentCount(1);
        $this->assertTrue($result['safe']);
    }

    public static function brandSubstringFalsePositives(): array
    {
        return [
            'caixa in caixadagua' => ['https://caixadagua.com.br'],
            'steam in steampunk' => ['https://steampunk-store.com'],
            'itau in capacitau' => ['https://capacitau.com.br'],
        ];
    }

    /**
     * A brand token set off by a separator (the real impersonation shape) is
     * still flagged locally.
     */
    public function test_brand_token_with_separator_is_flagged(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake();

        $result = (new LinkSafetyService)->checkUrl('https://steam-free-gift.example.com');

        Http::assertNothingSent();
        $this->assertFalse($result['safe']);
    }

    /**
     * A clean, unrelated URL passes the heuristic and reaches Safe Browsing.
     */
    public function test_clean_url_reaches_safe_browsing(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake(['*' => Http::response('{}', 200)]);

        $result = (new LinkSafetyService)->checkUrl('https://docs.google.com/forms/d/e/abc');

        Http::assertSentCount(1);
        $this->assertTrue($result['safe']);
    }

    // ============================================================
    // Rule D — ephemeral tunnel hosts (trycloudflare, ngrok, ...)
    // ============================================================

    /**
     * A host on an ephemeral tunnel service is blocked locally, without ever
     * calling Safe Browsing. This is the exact shape of the 2026-07-21 phishing
     * abuse (login → dam-dicke-northern-them.trycloudflare.com): the host is
     * minutes old, so neither the brand rule nor Safe Browsing reputation can
     * catch it — but no legitimate destination for a shortened link lives on a
     * throwaway tunnel.
     *
     * @dataProvider ephemeralTunnelHosts
     */
    public function test_ephemeral_tunnel_host_is_blocked_before_reaching_safe_browsing(string $url): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake();

        $result = (new LinkSafetyService)->checkUrl($url);

        Http::assertNothingSent();
        $this->assertFalse($result['safe']);
        $this->assertNotEmpty($result['threats']);
    }

    public static function ephemeralTunnelHosts(): array
    {
        return [
            'trycloudflare' => ['https://dam-dicke-northern-them.trycloudflare.com/login'],
            'ngrok legacy' => ['https://abc123.ngrok.io/'],
            'ngrok free, deep subdomain' => ['https://a.b.ngrok-free.app/x'],
            'localtunnel' => ['https://meu-app.loca.lt'],
            'serveo' => ['https://xyz.serveo.net/callback'],
        ];
    }

    /**
     * The tunnel rule must not overmatch: a domain that merely *contains* a
     * tunnel suffix as a substring (without the dot boundary) is not a tunnel.
     */
    public function test_domain_containing_tunnel_suffix_substring_is_not_flagged(): void
    {
        config(['services.google_safe_browsing.key' => 'test-key']);
        Http::fake(['*' => Http::response('{}', 200)]);

        $result = (new LinkSafetyService)->checkUrl('https://notserveo.net.example.com.br');

        Http::assertSentCount(1);
        $this->assertTrue($result['safe']);
    }

    /**
     * The tunnel rule sits inside Layer 1, so the master kill switch disables
     * it too and the URL falls through to Safe Browsing.
     */
    public function test_tunnel_block_respects_heuristic_kill_switch(): void
    {
        config([
            'services.google_safe_browsing.key' => 'test-key',
            'link_safety.heuristic_enabled' => false,
        ]);
        Http::fake(['*' => Http::response('{}', 200)]);

        $result = (new LinkSafetyService)->checkUrl('https://abc.trycloudflare.com');

        Http::assertSentCount(1);
        $this->assertTrue($result['safe']);
    }
}
