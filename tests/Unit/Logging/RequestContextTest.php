<?php

namespace Tests\Unit\Logging;

use App\Logging\Context\RequestContext;
use Tests\TestCase;

class RequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clear();
    }

    public function test_current_returns_null_when_nothing_set(): void
    {
        $this->assertNull(RequestContext::current());
    }

    public function test_set_and_current_round_trip(): void
    {
        RequestContext::set(new RequestContext('req_abc', userId: 7, ip: '1.2.3.4', route: 'link.redirect'));

        $ctx = RequestContext::current();

        $this->assertNotNull($ctx);
        $this->assertSame('req_abc', $ctx->requestId);
        $this->assertSame(7, $ctx->userId);
        $this->assertSame('1.2.3.4', $ctx->ip);
        $this->assertSame('link.redirect', $ctx->route);
    }

    public function test_clear_resets_to_null(): void
    {
        RequestContext::set(new RequestContext('req_abc'));
        RequestContext::clear();

        $this->assertNull(RequestContext::current());
    }

    public function test_set_overwrites_existing_context(): void
    {
        RequestContext::set(new RequestContext('req_a'));
        RequestContext::set(new RequestContext('req_b'));

        $this->assertSame('req_b', RequestContext::current()->requestId);
    }
}
