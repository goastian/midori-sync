<?php

namespace Tests;

use App\Models\Collection;
use App\Models\Device;
use App\Models\User;
use App\Services\SyncAuthService;
use App\Services\SyncIdentityService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Collections use a static in-memory cache keyed by name.
        // Reset it between tests so that RefreshDatabase rollbacks do not
        // leave stale model instances memoized across the suite.
        Collection::flushNameCache();
        Http::preventStrayRequests();
    }

    protected function createNativeSessionToken(User $user, ?Device $device = null, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        config(['services.sync.local_dev' => true]);
        $user->update(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $device ??= Device::create([
            'user_id' => $user->id,
            'device_id' => (string) Str::uuid(),
            'name' => 'Midori Desktop',
            'type' => 'desktop',
        ]);

        return app(SyncAuthService::class)->createSessionToken($user, $device->id, $ipAddress, $userAgent, protocolVersion: 2);
    }
}
