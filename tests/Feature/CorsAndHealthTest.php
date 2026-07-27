<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the HTTP plumbing consolidated in the audit fixes:
 *
 *   1. CORS keeps working for api/* (preflight AND actual requests) with
 *      HandleCors registered ONCE, globally, in bootstrap/app.php — instead of
 *      the previous triple registration (web group + api group + global).
 *   2. CORS is scoped: web routes outside api/* and r/* (e.g. /health) no
 *      longer emit Access-Control-Allow-Origin (the '*' catch-all path was
 *      removed from config/cors.php).
 *   3. GET /health serves the hand-written payload from routes/web.php —
 *      characterized here so removing the shadowed withRouting(health:)
 *      registration cannot change the response contract.
 *
 * The suite runs with APP_ENV=testing, so the dev localhost origins are
 * active (see CorsConfigTest for the production-shape guarantees).
 */
class CorsAndHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A browser preflight (OPTIONS + Origin + Access-Control-Request-Method)
     * against an api/* route must be answered with the CORS grant headers.
     */
    public function test_preflight_on_api_route_grants_allowed_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    /**
     * Actual (non-preflight) cross-origin requests to api/* also carry the
     * CORS response headers — this is what the SPA relies on.
     */
    public function test_actual_api_request_carries_cors_headers(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'irrelevant-1',
        ], ['Origin' => 'http://localhost:3000']);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    /**
     * Web routes outside the api/* and r/* path scopes must NOT emit CORS
     * grant headers anymore — the '*' catch-all was removed on purpose.
     */
    public function test_health_route_is_outside_the_cors_path_scope(): void
    {
        $response = $this->get('/health', ['Origin' => 'http://localhost:3000']);

        $response->assertOk();
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    /**
     * Characterization of the /health payload served by routes/web.php: the
     * shape is consumed by uptime monitoring and the deploy health check, so
     * it must survive the removal of the dead withRouting(health:) route.
     */
    public function test_health_endpoint_serves_the_custom_payload(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('services.api', 'running')
            ->assertJsonPath('services.database', 'connected')
            ->assertJsonStructure([
                'status',
                'timestamp',
                'services' => ['database', 'cache', 'api'],
                'version',
            ]);
    }
}
