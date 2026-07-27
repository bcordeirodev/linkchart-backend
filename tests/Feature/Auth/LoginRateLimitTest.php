<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the `login` rate limiter applied to POST /api/auth/login.
 *
 * The limiter must throttle on two dimensions:
 * - per email: blocks brute force against a single account;
 * - per IP: blocks password spraying (one IP testing a single password
 *   across many different emails, never repeating an email).
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sixth attempt against the same email is throttled (5/min per email).
     */
    public function test_same_email_is_throttled_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'victim@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /**
     * A single IP rotating through distinct emails (password spraying) is
     * throttled after 20 attempts, even though no email repeats.
     */
    public function test_same_ip_is_throttled_across_distinct_emails(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => "spray-{$i}@example.com",
                'password' => 'attempted-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'spray-final@example.com',
            'password' => 'attempted-password',
        ])->assertStatus(429);
    }
}
