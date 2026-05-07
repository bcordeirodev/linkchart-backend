<?php

namespace Tests\Feature\Logging;

use App\Http\Middleware\AssignRequestId;
use App\Logging\Context\RequestContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class AssignRequestIdMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clear();
    }

    public function test_generates_id_when_header_absent(): void
    {
        $mw = new AssignRequestId();
        $captured = null;

        $response = $mw->handle(Request::create('/test'), function () use (&$captured) {
            $captured = RequestContext::current();
            return new Response('ok');
        });

        $this->assertNotNull($captured);
        $this->assertStringStartsWith('req_', $captured->requestId);
        $this->assertSame($captured->requestId, $response->headers->get('X-Request-Id'));
    }

    public function test_reuses_inbound_x_request_id_header(): void
    {
        $mw = new AssignRequestId();
        $captured = null;
        $req = Request::create('/test');
        $req->headers->set('X-Request-Id', 'req_external_123');

        $response = $mw->handle($req, function () use (&$captured) {
            $captured = RequestContext::current();
            return new Response('ok');
        });

        $this->assertSame('req_external_123', $captured->requestId);
        $this->assertSame('req_external_123', $response->headers->get('X-Request-Id'));
    }

    public function test_populates_ip_and_route_in_context(): void
    {
        $mw = new AssignRequestId();
        $captured = null;
        $req = Request::create('/r/abc');
        $req->server->set('REMOTE_ADDR', '8.8.8.8');

        $mw->handle($req, function () use (&$captured) {
            $captured = RequestContext::current();
            return new Response('ok');
        });

        $this->assertSame('8.8.8.8', $captured->ip);
        $this->assertSame('r/abc', $captured->route);
    }

    public function test_clears_context_after_response(): void
    {
        $mw = new AssignRequestId();

        $mw->handle(Request::create('/t'), fn () => new Response('ok'));

        $this->assertNull(RequestContext::current());
    }
}
