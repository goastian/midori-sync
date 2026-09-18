<?php

namespace App\Jobs\Library;

use App\Models\Library\LibraryLink;
use App\Models\Library\LibrarySnapshot;
use App\Services\Library\UrlNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SnapshotPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $linkId, public array $kinds = ['html'])
    {
        $this->onQueue('snapshots');
    }

    public function handle(): void
    {
        if (! config('library.snapshots_enabled')) {
            return;
        }
        $link = LibraryLink::find($this->linkId);
        if (! $link) {
            return;
        }
        if (! UrlNormalizer::isPublicHttpUrl($link->url)) {
            $link->update(['snapshot_status' => 'failed']);

            return;
        }

        $disk = Storage::disk(config('library.snapshot_disk', 'local'));
        $ok = false;
        foreach ($this->kinds as $kind) {
            if ($kind !== 'html') {
                continue; // screenshot/pdf requieren Chromium dedicado (fase L3-full).
            }
            try {
                $res = Http::withHeaders(['User-Agent' => 'MidoriLibrarySnapshot/1.0'])
                    ->timeout(20)->get($link->url);
                if (! $res->successful()) {
                    continue;
                }
                $html = preg_replace('#<script[^>]*>.*?</script>#is', '', substr($res->body(), 0, 8 * 1024 * 1024));
                $path = "snapshots/{$link->user_id}/{$link->id}/page.html";
                $disk->put($path, $html);
                LibrarySnapshot::updateOrCreate(
                    ['library_link_id' => $link->id, 'kind' => 'html'],
                    ['storage_path' => $path, 'mime' => 'text/html', 'size_bytes' => strlen($html)]
                );
                $ok = true;
            } catch (\Throwable) {
                continue;
            }
        }

        $link->update(['snapshot_status' => $ok ? 'ready' : 'failed', 'last_preserved_at' => $ok ? now() : $link->last_preserved_at]);
    }
}
