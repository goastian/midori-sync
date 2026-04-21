<?php

namespace App\Console\Commands;

use App\Services\SyncAuthService;
use App\Services\SyncStorageService;
use Illuminate\Console\Command;

class CleanupExpired extends Command
{
    protected $signature = 'sync:cleanup-expired';
    protected $description = 'Remove expired records (TTL) and expired sync sessions';

    public function handle(SyncStorageService $storage, SyncAuthService $auth): int
    {
        $records = $storage->cleanupExpiredRecords();
        $sessions = $auth->cleanupExpired();

        $this->info("Cleaned up {$records} expired records and {$sessions} expired sessions.");

        return self::SUCCESS;
    }
}
