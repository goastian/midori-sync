<?php

namespace App\Console\Commands;

use App\Services\SyncAuthService;
use App\Services\SyncNotificationTickets;
use App\Services\SyncStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupExpired extends Command
{
    protected $signature = 'sync:cleanup-expired';

    protected $description = 'Remove expired records (TTL) and expired sync sessions';

    public function handle(SyncStorageService $storage, SyncAuthService $auth, SyncNotificationTickets $tickets): int
    {
        $records = $storage->cleanupExpiredRecords();
        $sessions = $auth->cleanupExpired();
        $exchanges = DB::table('sync_native_oidc_exchanges')->where('expires_at', '<=', now())->delete();
        $expiredTickets = $tickets->cleanupExpired();

        $this->info("Cleaned up {$records} expired records, {$sessions} expired sessions and {$exchanges} OIDC exchanges; {$expiredTickets} notification tickets.");

        return self::SUCCESS;
    }
}
