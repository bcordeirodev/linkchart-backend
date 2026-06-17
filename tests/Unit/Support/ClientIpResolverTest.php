<?php

namespace Tests\Unit\Support;

use App\Support\ClientIpResolver;
use Tests\TestCase;

/**
 * Unit coverage for the shared client-IP resolver: header precedence, the
 * X-Forwarded-For first-token parsing, and invalid-IP rejection/fallthrough.
 *
 * These assert the NON-production validation path (any syntactically valid IP is
 * accepted), matching the SQLite test environment where app.env !== 'production'.
 */
class ClientIpResolverTest extends TestCase
{
    private ClientIpResolver $resolver;

    /**
     * Builds a fresh resolver for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ClientIpResolver;
    }

    /**
     * The ?real_ip query param wins over every other source when valid.
     */
    public function test_query_param_has_highest_precedence(): void
    {
        $ip = $this->resolver->resolve([
            ClientIpResolver::SOURCE_QUERY_PARAM => '203.0.113.1',
            ClientIpResolver::SOURCE_X_REAL_IP => '198.51.100.2',
            ClientIpResolver::SOURCE_X_FORWARDED_FOR => '198.51.100.3',
            ClientIpResolver::SOURCE_CF_CONNECTING_IP => '198.51.100.4',
        ], '10.0.0.1');

        $this->assertSame('203.0.113.1', $ip);
    }

    /**
     * X-Real-IP wins when no query param is present.
     */
    public function test_x_real_ip_precedes_forwarded_and_cloudflare(): void
    {
        $ip = $this->resolver->resolve([
            ClientIpResolver::SOURCE_X_REAL_IP => '198.51.100.2',
            ClientIpResolver::SOURCE_X_FORWARDED_FOR => '198.51.100.3',
            ClientIpResolver::SOURCE_CF_CONNECTING_IP => '198.51.100.4',
        ], '10.0.0.1');

        $this->assertSame('198.51.100.2', $ip);
    }

    /**
     * Only the first token of a multi-IP X-Forwarded-For chain is used, trimmed.
     */
    public function test_forwarded_for_uses_first_trimmed_token(): void
    {
        $ip = $this->resolver->resolve([
            ClientIpResolver::SOURCE_X_FORWARDED_FOR => ' 203.0.113.9 , 70.0.0.1, 70.0.0.2',
        ], '10.0.0.1');

        $this->assertSame('203.0.113.9', $ip);
    }

    /**
     * Cloudflare header is used when it is the only valid source.
     */
    public function test_cloudflare_header_used_when_others_absent(): void
    {
        $ip = $this->resolver->resolve([
            ClientIpResolver::SOURCE_CF_CONNECTING_IP => '203.0.113.50',
        ], '10.0.0.1');

        $this->assertSame('203.0.113.50', $ip);
    }

    /**
     * Invalid candidates are skipped and resolution falls through to the next source.
     */
    public function test_invalid_candidate_falls_through_to_next_source(): void
    {
        $ip = $this->resolver->resolve([
            ClientIpResolver::SOURCE_QUERY_PARAM => 'not-an-ip',
            ClientIpResolver::SOURCE_X_REAL_IP => '999.999.999.999',
            ClientIpResolver::SOURCE_X_FORWARDED_FOR => '203.0.113.77',
        ], '10.0.0.1');

        $this->assertSame('203.0.113.77', $ip);
    }

    /**
     * When no candidate is valid, the provided fallback IP is returned.
     */
    public function test_falls_back_when_no_candidate_valid(): void
    {
        $ip = $this->resolver->resolve([
            ClientIpResolver::SOURCE_X_REAL_IP => 'garbage',
            ClientIpResolver::SOURCE_X_FORWARDED_FOR => '',
        ], '192.0.2.55');

        $this->assertSame('192.0.2.55', $ip);
    }

    /**
     * An empty fallback degrades to the loopback address.
     */
    public function test_empty_fallback_degrades_to_loopback(): void
    {
        $ip = $this->resolver->resolve([], '');

        $this->assertSame('127.0.0.1', $ip);
    }

    /**
     * Null and empty candidate values are ignored entirely.
     */
    public function test_null_and_empty_candidates_are_ignored(): void
    {
        $ip = $this->resolver->resolve([
            ClientIpResolver::SOURCE_QUERY_PARAM => null,
            ClientIpResolver::SOURCE_X_REAL_IP => '',
            ClientIpResolver::SOURCE_CF_CONNECTING_IP => '203.0.113.88',
        ], '10.0.0.1');

        $this->assertSame('203.0.113.88', $ip);
    }

    /**
     * The onResolved hook fires once with the winning source and IP.
     */
    public function test_on_resolved_hook_reports_winning_source(): void
    {
        $seen = [];
        $this->resolver->resolve([
            ClientIpResolver::SOURCE_X_FORWARDED_FOR => '203.0.113.9, 70.0.0.1',
        ], '10.0.0.1', function (string $source, string $ip, array $context) use (&$seen): void {
            $seen = compact('source', 'ip', 'context');
        });

        $this->assertSame(ClientIpResolver::SOURCE_X_FORWARDED_FOR, $seen['source']);
        $this->assertSame('203.0.113.9', $seen['ip']);
        $this->assertSame('203.0.113.9, 70.0.0.1', $seen['context']['full_chain']);
    }

    /**
     * isValidIp accepts well-formed addresses and rejects malformed ones (non-prod).
     */
    public function test_is_valid_ip_basic_validation(): void
    {
        $this->assertTrue($this->resolver->isValidIp('8.8.8.8'));
        $this->assertTrue($this->resolver->isValidIp('2001:4860:4860::8888'));
        $this->assertFalse($this->resolver->isValidIp('not-an-ip'));
        $this->assertFalse($this->resolver->isValidIp('256.256.256.256'));
    }
}
