<?php

namespace Tests\Feature\Observability;

use App\Observability\Otel;
use Tests\TestCase;

class OtelDisabledTest extends TestCase
{
    public function test_otel_is_disabled_in_test_env(): void
    {
        $this->assertFalse(Otel::enabled());
    }

    public function test_tracer_returns_noop_and_does_not_throw_when_disabled(): void
    {
        $span = Otel::tracer()->spanBuilder('test')->startSpan();
        $span->end();

        // No exception = pass. No-op span context is invalid.
        $this->assertFalse($span->getContext()->isValid());
    }

    public function test_record_redirect_is_safe_when_disabled(): void
    {
        Otel::recordRedirect(302, 0.012, 'BR', 'mobile');
        $this->assertTrue(true);
    }
}
