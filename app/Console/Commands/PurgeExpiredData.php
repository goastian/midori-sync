<?php

namespace App\Console\Commands;

use App\Services\HawkAuthService;
use App\Services\SyncStorageService;
use Illuminate\Console\Command;

/**
 * Artisan command to purge expired Hawk tokens and BSOs.
 *
 * Recommended to run periodically via cron/scheduler to keep the database clean.
 */
class PurgeExpiredData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:purge-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge expired Hawk tokens and BSOs from the database';

    /**
     * Execute the console command.
     */
    public function handle(HawkAuthService $hawkService, SyncStorageService $syncService): int
    {
        $tokens = $hawkService->purgeExpiredTokens();
        $this->info("Purged {$tokens} expired Hawk tokens.");

        $bsos = $syncService->purgeExpiredBsos();
        $this->info("Purged {$bsos} expired BSOs.");

        return self::SUCCESS;
    }
}
