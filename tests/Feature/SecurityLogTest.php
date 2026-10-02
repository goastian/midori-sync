<?php

namespace Tests\Feature;

use App\Exceptions\SyncQuotaException;
use App\Models\User;
use App\Services\SyncStorageService;
use App\Support\SecurityLog;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Smoke tests over the security audit channel: token revocation, quota
 * exceeded and invalid token paths must emit a structured event on the
 * `security` log channel with stable field names.
 */
class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CollectionSeeder::class);
    }

    public function test_invalid_token_emits_token_invalid_event(): void
    {
        $captured = [];
        Log::shouldReceive('channel')->with('security')->andReturnUsing(function () use (&$captured) {
            return new class($captured)
            {
                public function __construct(private array &$captured) {}

                public function info($msg, $ctx): void
                {
                    $this->captured[] = ['info', $msg, $ctx];
                }

                public function warning($msg, $ctx): void
                {
                    $this->captured[] = ['warning', $msg, $ctx];
                }

                public function error($msg, $ctx): void
                {
                    $this->captured[] = ['error', $msg, $ctx];
                }
            };
        });

        $this->getJson('/api/v1/devices', ['Authorization' => 'Bearer notreal'])
            ->assertStatus(401);

        $events = array_column($captured, 1);
        $this->assertContains(SecurityLog::EVENT_TOKEN_INVALID, $events);
    }

    public function test_quota_exceeded_emits_event(): void
    {
        $captured = [];
        Log::shouldReceive('channel')->with('security')->andReturnUsing(function () use (&$captured) {
            return new class($captured)
            {
                public function __construct(private array &$captured) {}

                public function info($msg, $ctx): void
                {
                    $this->captured[] = ['info', $msg, $ctx];
                }

                public function warning($msg, $ctx): void
                {
                    $this->captured[] = ['warning', $msg, $ctx];
                }

                public function error($msg, $ctx): void
                {
                    $this->captured[] = ['error', $msg, $ctx];
                }
            };
        });

        $user = User::factory()->create(['storage_quota_bytes' => 10]);
        try {
            app(SyncStorageService::class)->batchUpsert($user->id, 'bookmarks', [
                ['id' => 'a', 'payload' => str_repeat('x', 100)],
            ]);
            $this->fail('The quota must reject the write.');
        } catch (SyncQuotaException $error) {
            $error->render(request());
        }

        $events = array_column($captured, 1);
        $this->assertContains(SecurityLog::EVENT_QUOTA_EXCEEDED, $events);
    }
}
