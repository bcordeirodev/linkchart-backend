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
 *   - Bio-page subdomains (e.g. https://bruno.linkcharts.com.br) are allowed
 *     in every environment via a wildcard pattern — the explicit
 *     allowed_origins list only ever covers the apex + www.
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
     * allowed — only the real product domains and the bio-subdomain wildcard.
     */
    public function test_production_cors_has_no_localhost_origins_or_patterns(): void
    {
        $config = $this->loadCorsConfigForEnv('production');

        foreach ($config['allowed_origins_patterns'] as $pattern) {
            $this->assertStringNotContainsString('localhost', $pattern);
            $this->assertStringNotContainsString('127.0.0.1', $pattern);
        }

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

    /**
     * Production must allow bio-page subdomains of the real product domain
     * (e.g. https://bruno.linkcharts.com.br, the per-creator bio page host)
     * via a wildcard pattern — the explicit allowed_origins list only ever
     * covers the apex + www, and enumerating every creator subdomain there
     * is not viable.
     */
    public function test_production_cors_allows_linkcharts_subdomain_wildcard(): void
    {
        $config = $this->loadCorsConfigForEnv('production');

        $matches = array_filter(
            $config['allowed_origins_patterns'],
            fn (string $pattern) => preg_match($pattern, 'https://bruno.linkcharts.com.br') === 1,
        );
        $this->assertNotEmpty($matches, 'expected a pattern matching a linkcharts.com.br subdomain in production');

        foreach ($config['allowed_origins_patterns'] as $pattern) {
            $this->assertNotSame(1, preg_match($pattern, 'https://evil.com'), "{$pattern} must not match an unrelated origin");
            $this->assertNotSame(1, preg_match($pattern, 'https://linkcharts.com.br.evil.com'), "{$pattern} must not match a suffix-spoofed origin");
            $this->assertNotSame(1, preg_match($pattern, 'http://bruno.linkcharts.com.br'), "{$pattern} must not match plain http in production");
        }
    }

    /**
     * Outside production, bio-page subdomains resolve as `{sub}.localhost`
     * (see middleware.ts's extractBioSubdomain, which accepts `localhost` as
     * a root domain unconditionally) — with or without the Next dev server's
     * port, since the dev nginx front door serves the bio host on :80.
     */
    public function test_non_production_cors_allows_localhost_subdomain_wildcard(): void
    {
        $config = $this->loadCorsConfigForEnv('local');

        foreach (['http://discord.localhost', 'http://bruno.localhost:3000'] as $origin) {
            $matches = array_filter(
                $config['allowed_origins_patterns'],
                fn (string $pattern) => preg_match($pattern, $origin) === 1,
            );
            $this->assertNotEmpty($matches, "expected a pattern matching {$origin} outside production");
        }
    }
}
