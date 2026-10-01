<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SyncIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncDevTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_requires_explicit_development_mode(): void
    {
        config(['services.sync.local_dev' => false]);
        $this->artisan('sync:dev')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_production_even_when_the_flag_is_set(): void
    {
        config(['services.sync.local_dev' => true]);
        app()->detectEnvironment(fn () => 'production');
        $this->artisan('sync:dev')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_refuses_a_database_without_the_isolation_prefix(): void
    {
        config(['services.sync.local_dev' => true, 'database.connections.pgsql.database' => 'midori_sync']);
        $this->artisan('sync:dev', ['--create-database' => true])->assertFailed();
    }

    public function test_command_refuses_url_overrides_that_bypass_the_checked_database(): void
    {
        config(['services.sync.local_dev' => true, 'database.connections.pgsql.url' => 'postgresql://localhost/unrelated']);
        $this->artisan('sync:dev', ['--create-database' => true])->assertFailed();
    }

    public function test_new_codes_do_not_create_sessions_or_devices_until_redeemed(): void
    {
        config(['services.sync.local_dev' => true]);
        User::factory()->create([
            'authentik_id' => 'midori-local-development',
            'authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER,
        ]);
        $this->artisan('sync:dev', ['action' => 'pair'])->assertSuccessful();
        $this->assertDatabaseCount('sync_pairing_codes', 2);
        $this->assertDatabaseCount('sync_sessions', 0);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_pairing_command_does_not_rebind_an_unprepared_identity(): void
    {
        config(['services.sync.local_dev' => true]);
        $user = User::factory()->create(['authentik_id' => 'midori-local-development']);
        $this->artisan('sync:dev', ['action' => 'pair'])->assertFailed();
        $this->assertNull($user->fresh()->authentik_issuer);
        $this->assertDatabaseCount('sync_pairing_codes', 0);
    }
}
