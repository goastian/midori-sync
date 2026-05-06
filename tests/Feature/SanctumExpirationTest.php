<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pins the contract documented in ADR-0002: Sanctum is not used for
 * sync tokens; SyncSession owns the TTL. This test exists so that any
 * change to the Sanctum config (e.g. wiring it into /api/v1) shows up
 * in CI as an explicit policy update.
 */
class SanctumExpirationTest extends TestCase
{
    public function test_sanctum_expiration_is_configurable_via_env(): void
    {
        // Default (no env set) preserves Sanctum's behaviour, which is
        // documented as "do not issue Sanctum tokens".
        $this->assertNull(config('sanctum.expiration'));
    }

    public function test_sync_token_ttl_is_finite_and_in_range(): void
    {
        $ttl = (int) config('services.sync.token_ttl');
        $this->assertGreaterThan(0, $ttl);
        // Sanity ceiling: 7 days. Real default is 3600s.
        $this->assertLessThanOrEqual(7 * 24 * 3600, $ttl);
    }

    public function test_v1_auth_routes_do_not_use_sanctum_guard(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1') || str_starts_with($r->uri(), 'api/ext'));

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            foreach ($middleware as $m) {
                $this->assertStringNotContainsString(
                    'sanctum',
                    is_string($m) ? strtolower($m) : '',
                    "Route {$route->uri()} must not be gated by Sanctum (use ValidateSyncToken)."
                );
            }
        }
    }
}
