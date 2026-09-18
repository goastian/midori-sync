<?php

namespace App\Jobs\Library;

use App\Models\Library\LibraryLink;
use App\Services\Library\MetadataExtractor;
use App\Services\Library\UrlNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class FetchLinkMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $linkId)
    {
        $this->onQueue('fetch');
    }

    public function handle(): void
    {
        if (! config('library.fetch_enabled')) {
            return;
        }
        $link = LibraryLink::find($this->linkId);
        if (! $link || $link->metadata_status === 'ready') {
            return;
        }
        if (! UrlNormalizer::isPublicHttpUrl($link->url)) {
            $link->update(['metadata_status' => 'failed']);

            return;
        }

        try {
            $res = Http::withHeaders(['User-Agent' => 'MidoriLibrary/1.0 (+https://astian.org)'])
                ->timeout(10)->get($link->url);
            if (! $res->successful()) {
                $link->update(['metadata_status' => 'failed']);

                return;
            }
            $html = substr($res->body(), 0, 5 * 1024 * 1024);
            $contentType = $res->header('Content-Type', '');
            if ($contentType !== '' && ! str_contains(strtolower($contentType), 'html') && ! str_contains(strtolower($contentType), 'text')) {
                $link->update(['metadata_status' => 'failed']);

                return;
            }
            $meta = MetadataExtractor::extract($html, $link->url);

            $link->update([
                'title' => $meta['title'] ?? $link->title ?? $link->url,
                'description' => $meta['description'] ?? $link->description,
                'favicon_url' => $meta['favicon'] ?? $link->favicon_url,
                'og_image_url' => $meta['og_image'] ?? $link->og_image_url,
                'readability_html' => $meta['readable'] ?? $link->readability_html,
                'reading_time_min' => $meta['reading_time'] ?? $link->reading_time_min,
                'metadata_status' => 'ready',
            ]);
        } catch (\Throwable) {
            $link->update(['metadata_status' => 'failed']);
            $this->release(60);
        }
    }
}
