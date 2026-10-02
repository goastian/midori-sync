<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Unauthenticated native pairing must throttle by IP so one host
 * cannot brute-force pairing codes.
 */
class UnauthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sync.unauth_rate_limit', 3);
        RateLimiter::clear('sync:u:127.0.0.1');
    }

    public function test_pair_redeem_is_throttled_by_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $r = $this->postJson('/api/v1/pair/redeem', [
                'pairing_token' => str_repeat('a', 32),
                'device_name' => 'Tester',
            ]);
            $this->assertNotSame(429, $r->status(), "Request #{$i} should not be 429");
        }

        $this->postJson('/api/v1/pair/redeem', [
            'pairing_token' => str_repeat('a', 32),
            'device_name' => 'Tester',
        ])->assertStatus(429);
    }

}
