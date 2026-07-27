<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Guards the shape of config/cors.php across environments.
 *
 * The config file is env-sensitive (localhost origins/patterns are dev-only),
 * so these tests re-evaluate the file with APP_ENV mutated in the superglobals
 * — the same mechanism env() reads from — instead of relying on the config
 * already loaded by the test application (always "testing").
 *
 * Invariants:
 *   - The catch-all '*' path must never come back: CORS applies only to
 *     'api/*' and 'r/*' (audit finding — '*' made every web route, /health
 *     included, emit permissive CORS headers with credentials support).
 *   - localhost origins/patterns exist only OUTSIDE production.
 */
class CorsConfigTest extends TestCase
{
    /**
     * Evaluates config/cors.php as if APP_ENV were $appEnv, restoring the
     * real values afterwards so the rest of the suite is unaffected.
     *
     * @param  string  $appEnv  The APP_ENV value to simulate (e.g. 'production').
     * @return array<string, mixed> The evaluated CORS config array.
     */
    private function loadCorsConfigForEnv(string $appEnv): array
    {
        $backupEnv = $_ENV['APP_ENV'] ?? null;
        $backupServer = $_SERVER['APP_ENV'] ?? null;

        $_ENV['APP_ENV'] = $appEnv;
        $_SERVER['APP_ENV'] = $appEnv;

        try {
            return require base_path('config/cors.php');
        } finally {
            $_ENV['APP_ENV'] = $backupEnv;
            $_SERVER['APP_ENV'] = $backupServer;

            if ($backupEnv === null) {
                unset($_ENV['APP_ENV']);
            }
            if ($backupServer === null) {
                unset($_SERVER['APP_ENV']);
            }
        }
    }

    /**
     * The CORS paths must be scoped to the API and redirect routes only —
     * the '*' catch-all is forbidden in every environment.
     */
    public function test_cors_paths_never_include_the_catch_all_wildcard(): void
    {
        foreach (['production', 'local', 'testing'] as $env) {
            $config = $this->loadCorsConfigForEnv($env);

            $this->assertNotContains('*', $config['paths'], "paths must not contain '*' in {$env}");
            $this->assertContains('api/*', $config['paths'], "api/* must stay covered in {$env}");
            $this->assertContains('r/*', $config['paths'], "r/* must stay covered in {$env}");
        }
    }

    /**
     * In production, no localhost/127.0.0.1 origin or origin pattern may be
     * allowed — only the real product domains.
     */
    public function test_production_cors_has_no_localhost_origins_or_patterns(): void
    {
        $config = $this->loadCorsConfigForEnv('production');

        $this->assertSame([], $config['allowed_origins_patterns']);

        foreach ($config['allowed_origins'] as $origin) {
            $this->assertStringNotContainsString('localhost', $origin);
            $this->assertStringNotContainsString('127.0.0.1', $origin);
        }

        $this->assertContains('https://linkcharts.com.br', $config['allowed_origins']);
        $this->assertContains('https://www.linkcharts.com.br', $config['allowed_origins']);
    }

    /**
     * Outside production the dev origins keep working: explicit localhost
     * origins and the any-port localhost/127.0.0.1 patterns are present.
     */
    public function test_non_production_cors_keeps_localhost_dev_origins(): void
    {
        $config = $this->loadCorsConfigForEnv('local');

        $this->assertContains('http://localhost:3000', $config['allowed_origins']);
        $this->assertContains('#^https?://localhost:\d+$#', $config['allowed_origins_patterns']);
        $this->assertContains('#^https?://127\.0\.0\.1:\d+$#', $config['allowed_origins_patterns']);
    }
}
