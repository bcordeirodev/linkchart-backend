<?php

namespace Tests\Unit\Services;

use App\Services\Links\SafeFetchUrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SSRF URL validator. DNS resolution is stubbed via an
 * anonymous subclass so no real lookups happen in the suite.
 */
class SafeFetchUrlValidatorTest extends TestCase
{
    /** Builds a validator whose DNS resolver returns a fixed address list. */
    private function validatorResolvingTo(array $ips): SafeFetchUrlValidator
    {
        return new class($ips) extends SafeFetchUrlValidator
        {
            public function __construct(private readonly array $ips) {}

            protected function resolveAddresses(string $host): array
            {
                return $this->ips;
            }
        };
    }

    /** Non-HTTP schemes are rejected outright. */
    public function test_rejects_non_http_schemes(): void
    {
        $this->assertFalse($this->validatorResolvingTo([])->isSafe('ftp://example.com/x'));
        $this->assertFalse($this->validatorResolvingTo([])->isSafe('file:///etc/passwd'));
    }

    /** Loopback aliases and internal TLDs never pass. */
    public function test_rejects_loopback_aliases_and_internal_tlds(): void
    {
        $v = $this->validatorResolvingTo([]);
        $this->assertFalse($v->isSafe('http://localhost/x'));
        $this->assertFalse($v->isSafe('http://0.0.0.0/x'));
        $this->assertFalse($v->isSafe('http://api.internal/x'));
        $this->assertFalse($v->isSafe('http://printer.local/x'));
    }

    /** Literal private/reserved IPv4 and IPv6 are rejected. */
    public function test_rejects_private_literal_ips(): void
    {
        $v = $this->validatorResolvingTo([]);
        $this->assertFalse($v->isSafe('http://10.0.0.5/x'));
        $this->assertFalse($v->isSafe('http://192.168.1.1/x'));
        $this->assertFalse($v->isSafe('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse($v->isSafe('http://[::1]/x'));
        $this->assertFalse($v->isSafe('http://[fd00::1]/x'));
    }

    /** Hostname resolving to a private IPv4 (DNS rebinding) is rejected. */
    public function test_rejects_hostname_resolving_to_private_ipv4(): void
    {
        $v = $this->validatorResolvingTo(['10.0.0.5']);
        $this->assertFalse($v->isSafe('http://rebind.example.com/x'));
    }

    /** A private AAAA record among public records still rejects (any-private rule). */
    public function test_rejects_hostname_resolving_to_private_ipv6(): void
    {
        $v = $this->validatorResolvingTo(['2606:4700::6810:84e5', 'fd00::1']);
        $this->assertFalse($v->isSafe('http://rebind6.example.com/x'));
    }

    /** Hostnames resolving only to public addresses pass. */
    public function test_accepts_hostname_resolving_to_public_addresses(): void
    {
        $v = $this->validatorResolvingTo(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);
        $this->assertTrue($v->isSafe('https://example.com/page'));
    }

    /** Resolution failure is allowed through (the fetch itself will fail). */
    public function test_accepts_when_resolution_fails(): void
    {
        $this->assertTrue($this->validatorResolvingTo([])->isSafe('https://does-not-resolve.example/x'));
    }

    /** Integer/hex/octal IP literal forms must be rejected (resolver backstop). */
    public function test_rejects_integer_form_ip_literals(): void
    {
        $v = new SafeFetchUrlValidator;
        $this->assertFalse($v->isSafe('http://2130706433/'));
        $this->assertFalse($v->isSafe('http://0x7f000001/'));
        $this->assertFalse($v->isSafe('http://017700000001/'));
    }

    /** IPv4-mapped IPv6 literals embedding private addresses are rejected. */
    public function test_rejects_ipv4_mapped_ipv6_literals(): void
    {
        $v = $this->validatorResolvingTo([]);
        $this->assertFalse($v->isSafe('http://[::ffff:127.0.0.1]/x'));
        $this->assertFalse($v->isSafe('http://[::ffff:169.254.169.254]/x'));
    }

    /** A mapped-private address among AAAA results is rejected. */
    public function test_rejects_mapped_private_in_resolved_addresses(): void
    {
        $v = $this->validatorResolvingTo(['::ffff:10.0.0.1']);
        $this->assertFalse($v->isSafe('http://sneaky.example.com/x'));
    }

    /** Trailing-dot hosts are normalised before all checks. */
    public function test_rejects_localhost_with_trailing_dot(): void
    {
        $this->assertFalse($this->validatorResolvingTo([])->isSafe('http://localhost./x'));
    }
}
