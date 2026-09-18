<?php

namespace App\Http\Controllers\Api\V1\Library;

use App\Http\Controllers\Controller;
use App\Jobs\Library\FetchLinkMetadata;
use App\Jobs\Library\SnapshotPage;
use App\Models\Library\LibraryCollection;
use App\Models\Library\LibraryLink;
use App\Models\Library\LibraryTag;
use App\Services\Library\UrlNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = LibraryLink::where('user_id', $user->id)->with('tags')->orderByDesc('created_at');

        if ($s = $request->input('q')) {
            // Phase-1 simple search (title/description/url/host). Full tsvector in L4.
            $like = '%'.mb_substr($s, 0, 120).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('url', 'like', $like)
                ->orWhere('host', 'like', $like));
        }
        foreach (['is_read', 'is_archived', 'is_favorite', 'is_pinned'] as $flag) {
            if ($request->has($flag)) {
                $q->where($flag, $request->boolean($flag));
            }
        }
        if ($tag = $request->input('tag')) {
            $q->whereHas('tags', fn ($w) => $w->where('name', $tag));
        }
        if ($col = $request->input('collection_id')) {
            $q->where('library_collection_id', $col);
        }
        if ($site = $request->input('site')) {
            $q->where('host', 'like', '%'.$site.'%');
        }

        $per = min(100, max(1, $request->integer('per_page', 25)));
        $page = $q->paginate($per);

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => 'required|string|max:2000',
            'title' => 'nullable|string|max:2000',
            'library_collection_id' => 'nullable|integer|exists:library_collections,id',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:64',
        ]);

        try {
            $norm = UrlNormalizer::normalize($data['url']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $user = $request->user();
        if (! UrlNormalizer::isPublicHttpUrl($norm['url'])) {
            return response()->json(['error' => 'URL must be a public http(s) address'], 422);
        }

        // Dedupe por canonical_url.
        $existing = LibraryLink::where('user_id', $user->id)
            ->where('canonical_url', $norm['canonical_url'])->first();
        if ($existing) {
            return response()->json($existing->load('tags'), 200);
        }

        if (! empty($data['library_collection_id'])) {
            $owned = LibraryCollection::where('id', $data['library_collection_id'])
                ->where('user_id', $user->id)->exists();
            if (! $owned) {
                return response()->json(['error' => 'Collection not found'], 404);
            }
        }

        $link = LibraryLink::create([
            'user_id' => $user->id,
            'library_collection_id' => $data['library_collection_id'] ?? null,
            'url' => $norm['url'],
            'canonical_url' => $norm['canonical_url'],
            'host' => $norm['host'],
            'title' => $data['title'] ?? $norm['url'],
            'metadata_status' => 'pending',
            'snapshot_status' => 'none',
        ]);

        if (! empty($data['tags'])) {
            $ids = [];
            foreach (array_unique($data['tags']) as $name) {
                $name = trim(mb_strtolower($name));
                if ($name === '') {
                    continue;
                }
                $tag = LibraryTag::firstOrCreate(['user_id' => $user->id, 'name' => $name]);
                $ids[] = $tag->id;
            }
            if ($ids) {
                $link->tags()->sync($ids);
            }
        }

        FetchLinkMetadata::dispatch($link->id);

        return response()->json($link->load('tags'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)
            ->with(['tags', 'snapshots', 'highlights', 'shares'])->find($id);
        if (! $link) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($link);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->find($id);
        if (! $link) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $data = $request->validate([
            'title' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:5000',
            'library_collection_id' => 'nullable|integer|exists:library_collections,id',
            'is_read' => 'nullable|boolean',
            'is_archived' => 'nullable|boolean',
            'is_favorite' => 'nullable|boolean',
            'is_pinned' => 'nullable|boolean',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:64',
        ]);
        if (array_key_exists('library_collection_id', $data) && $data['library_collection_id']) {
            $owned = LibraryCollection::where('id', $data['library_collection_id'])
                ->where('user_id', $request->user()->id)->exists();
            if (! $owned) {
                return response()->json(['error' => 'Collection not found'], 404);
            }
        }
        $tags = $data['tags'] ?? null;
        unset($data['tags']);
        $link->update($data);
        if (is_array($tags)) {
            $ids = [];
            foreach (array_unique($tags) as $name) {
                $name = trim(mb_strtolower($name));
                if ($name === '') {
                    continue;
                }
                $ids[] = LibraryTag::firstOrCreate(['user_id' => $request->user()->id, 'name' => $name])->id;
            }
            $link->tags()->sync($ids);
        }

        return response()->json($link->load('tags'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->find($id);
        if (! $link) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $link->delete();

        return response()->json(null, 204);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|max:100',
            'ids.*' => 'string',
            'action' => 'required|in:archive,unarchive,read,unread,favorite,unfavorite,pin,unpin,delete,move,tag',
            'library_collection_id' => 'nullable|integer|exists:library_collections,id',
            'tags' => 'nullable|array|max:20',
        ]);
        $user = $request->user();
        $links = LibraryLink::where('user_id', $user->id)->whereIn('id', $data['ids'])->get();
        $map = ['archive' => ['is_archived', true], 'unarchive' => ['is_archived', false],
            'read' => ['is_read', true], 'unread' => ['is_read', false],
            'favorite' => ['is_favorite', true], 'unfavorite' => ['is_favorite', false],
            'pin' => ['is_pinned', true], 'unpin' => ['is_pinned', false]];
        $count = 0;
        foreach ($links as $link) {
            if (isset($map[$data['action']])) {
                $link->update([$map[$data['action']][0] => $map[$data['action']][1]]);
                $count++;
            } elseif ($data['action'] === 'delete') {
                $link->delete();
                $count++;
            } elseif ($data['action'] === 'move') {
                $link->update(['library_collection_id' => $data['library_collection_id'] ?? null]);
                $count++;
            } elseif ($data['action'] === 'tag' && ! empty($data['tags'])) {
                foreach ($data['tags'] as $n) {
                    $t = LibraryTag::firstOrCreate(['user_id' => $user->id, 'name' => trim(mb_strtolower($n))]);
                    $link->tags()->syncWithoutDetaching([$t->id]);
                }
                $count++;
            }
        }

        return response()->json(['updated' => $count]);
    }

    public function preserve(Request $request, string $id): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->find($id);
        if (! $link) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $kinds = $request->input('kinds', ['html']);
        $link->update(['snapshot_status' => 'pending']);
        SnapshotPage::dispatch($link->id, array_values((array) $kinds));

        return response()->json(['queued' => true, 'kinds' => array_values((array) $kinds)]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        $links = LibraryLink::where('user_id', $user->id)->orderBy('created_at')->get();
        $format = $request->input('format', 'netscape');
        if ($format === 'json') {
            return response()->json($links);
        }
        if ($format === 'csv') {
            $csv = "url,title,tags,created_at\n";
            foreach ($links as $l) {
                $csv .= '"'.str_replace('"', '""', $l->url).'","'.str_replace('"', '""', (string) $l->title).'","", "'.$l->created_at.'"'."\n";
            }

            return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="midori-library.csv"']);
        }
        // Netscape HTML (standard Pocket/Linkwarden bookmark format).
        $html = "<!DOCTYPE NETSCAPE-Bookmark-file-1>\n<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=UTF-8\">\n<TITLE>Bookmarks</TITLE>\n<H1>Bookmarks</H1>\n<DL><p>\n";
        foreach ($links as $l) {
            $html .= '<DT><A HREF="'.e($l->url).'" ADD_DATE="'.$l->created_at->timestamp.'">'.e((string) $l->title)."</A>\n";
        }
        $html .= "</DL><p>\n";

        return response($html, 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="midori-library.html"']);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => 'required|in:netscape,pocket,csv',
            'bookmarks' => 'required|array|max:2000',
            'bookmarks.*.url' => 'required|string|max:2000',
            'bookmarks.*.title' => 'nullable|string|max:2000',
        ]);
        $user = $request->user();
        $created = 0;
        $skipped = 0;
        foreach ($data['bookmarks'] as $b) {
            try {
                $norm = UrlNormalizer::normalize($b['url']);
            } catch (\InvalidArgumentException) {
                $skipped++;

                continue;
            }
            if (! UrlNormalizer::isPublicHttpUrl($norm['url'])) {
                $skipped++;

                continue;
            }
            $exists = LibraryLink::where('user_id', $user->id)->where('canonical_url', $norm['canonical_url'])->exists();
            if ($exists) {
                $skipped++;

                continue;
            }
            $link = LibraryLink::create([
                'user_id' => $user->id,
                'url' => $norm['url'],
                'canonical_url' => $norm['canonical_url'],
                'host' => $norm['host'],
                'title' => $b['title'] ?? $norm['url'],
            ]);
            FetchLinkMetadata::dispatch($link->id);
            $created++;
        }

        return response()->json(['created' => $created, 'skipped' => $skipped], 201);
    }
}
