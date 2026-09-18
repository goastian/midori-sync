<?php

namespace App\Console\Commands;

use App\Jobs\Library\FetchLinkMetadata;
use App\Jobs\Library\SnapshotPage;
use App\Models\Library\LibraryLink;
use Illuminate\Console\Command;

class RetryLibraryPending extends Command
{
    protected $signature = 'library:retry-pending {--snapshots : also re-dispatch snapshots}';

    protected $description = 'Re-dispatch FetchLinkMetadata (and optionally SnapshotPage) for pending/failed links';

    public function handle(): int
    {
        $links = LibraryLink::whereIn('metadata_status', ['pending', 'failed'])->get();
        foreach ($links as $link) {
            $link->update(['metadata_status' => 'pending']);
            FetchLinkMetadata::dispatch($link->id);
        }
        $this->info("Re-dispatched metadata for {$links->count()} links.");

        if ($this->option('snapshots')) {
            $snaps = LibraryLink::whereIn('snapshot_status', ['none', 'failed'])->get();
            foreach ($snaps as $link) {
                SnapshotPage::dispatch($link->id, ['html']);
            }
            $this->info("Re-dispatched snapshots for {$snaps->count()} links.");
        }

        return self::SUCCESS;
    }
}
