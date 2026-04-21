<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SyncStorageService;
use Illuminate\Console\Command;

class RecalculateUsage extends Command
{
    protected $signature = 'sync:recalculate-usage {--user= : Recalculate for a specific user ID}';
    protected $description = 'Recalculate storage usage statistics for user collections';

    public function handle(SyncStorageService $storage): int
    {
        $userId = $this->option('user');

        if ($userId) {
            $storage->recalculateUsage((int) $userId);
            $this->info("Recalculated usage for user #{$userId}.");
        } else {
            $users = User::pluck('id');
            $bar = $this->output->createProgressBar($users->count());

            foreach ($users as $id) {
                $storage->recalculateUsage($id);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("Recalculated usage for {$users->count()} users.");
        }

        return self::SUCCESS;
    }
}
