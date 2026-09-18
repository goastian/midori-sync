<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\Library\FetchLinkMetadata;
use App\Jobs\Library\SnapshotPage;
use App\Models\Library\LibraryCollection;
use App\Models\Library\LibraryHighlight;
use App\Models\Library\LibraryLink;
use App\Models\Library\LibraryShare;
use App\Models\Library\LibraryTag;
use App\Services\Library\EntitlementService;
use App\Services\Library\UrlNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class LibraryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $ent = app(EntitlementService::class)->getEntitlement($user);

        $q = LibraryLink::where('user_id', $user->id)->with('tags')->orderByDesc('created_at');
        if ($s = $request->input('q')) {
            $like = '%'.mb_substr($s, 0, 120).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('url', 'like', $like)->orWhere('host', 'like', $like)->orWhere('description', 'like', $like));
        }
        foreach (['is_read', 'is_archived', 'is_favorite', 'is_pinned'] as $f) {
            if ($request->has($f)) {
                $q->where($f, $request->boolean($f));
            }
        }
        if ($request->filled('collection_id')) {
            $q->where('library_collection_id', $request->integer('collection_id'));
        }
        if ($request->filled('tag')) {
            $q->whereHas('tags', fn ($w) => $w->where('name', $request->input('tag')));
        }
        $links = $q->paginate(25)->withQueryString();

        return Inertia::render('Library/Index', [
            'links' => $links,
            'collections' => LibraryCollection::where('user_id', $user->id)->orderBy('name')->get(),
            'tags' => LibraryTag::where('user_id', $user->id)->orderBy('name')->get(),
            'entitlement' => [...$ent, 'upgrade_url' => config('library.upgrade_url'), 'used' => ['links' => LibraryLink::where('user_id', $user->id)->count()]],
            'filters' => $request->only('q', 'is_read', 'is_archived', 'is_favorite', 'is_pinned', 'collection_id', 'tag'),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)
            ->with(['tags', 'snapshots', 'highlights', 'shares', 'collection'])->findOrFail($id);

        return Inertia::render('Library/Show', [
            'link' => $link,
            'collections' => LibraryCollection::where('user_id', $request->user()->id)->orderBy('name')->get(),
        ]);
    }

    public function billing(Request $request)
    {
        $ent = app(EntitlementService::class)->getEntitlement($request->user());

        return Inertia::render('Library/Billing', [
            'entitlement' => [...$ent, 'upgrade_url' => config('library.upgrade_url'), 'billing_enabled' => config('library.billing_enabled')],
            'used' => ['links' => LibraryLink::where('user_id', $request->user()->id)->count()],
        ]);
    }

    /** Guardado desde el dashboard (sesión web, respuesta Inertia/redirect). */
    public function store(Request $request)
    {
        $data = $request->validate(['url' => 'required|string|max:2000', 'title' => 'nullable|string|max:2000', 'tags' => 'nullable|string|max:500']);

        try {
            $norm = UrlNormalizer::normalize($data['url']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['url' => $e->getMessage()]);
        }

        if (! UrlNormalizer::isPublicHttpUrl($norm['url'])) {
            return redirect()->back()->withErrors(['url' => 'URL must be a public http(s) address']);
        }

        $user = $request->user();
        $ent = app(EntitlementService::class)->getEntitlement($user);
        $max = $ent['limits']['max_links'] ?? null;
        if (! EntitlementService::unlimited($max) && LibraryLink::where('user_id', $user->id)->count() >= (int) $max) {
            return redirect()->back()->withErrors([
                'url' => 'Link limit reached for your plan. Upgrade en '.config('library.upgrade_url'),
            ]);
        }

        $existing = LibraryLink::where('user_id', $user->id)->where('canonical_url', $norm['canonical_url'])->first();
        if (! $existing) {
            $link = LibraryLink::create([
                'user_id' => $user->id,
                'url' => $norm['url'],
                'canonical_url' => $norm['canonical_url'],
                'host' => $norm['host'],
                'title' => $data['title'] ?? $norm['url'],
            ]);
            if (! empty($data['tags'])) {
                $link->tags()->sync($this->syncTags($user, $data['tags']));
            }
            FetchLinkMetadata::dispatch($link->id);
        }

        return redirect()->back();
    }

    /** Edición completa: título, colección, tags, flags. */
    public function update(Request $request, string $id)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate([
            'title' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:5000',
            'library_collection_id' => 'nullable|integer|exists:library_collections,id',
            'is_read' => 'nullable|boolean',
            'is_archived' => 'nullable|boolean',
            'is_favorite' => 'nullable|boolean',
            'is_pinned' => 'nullable|boolean',
            'tags' => 'nullable|string|max:500',
        ]);
        if (! empty($data['library_collection_id'])) {
            $owned = LibraryCollection::where('id', $data['library_collection_id'])->where('user_id', $request->user()->id)->exists();
            if (! $owned) {
                return redirect()->back()->withErrors(['library_collection_id' => 'Collection not found']);
            }
        }
        $tags = $data['tags'] ?? null;
        unset($data['tags']);
        $link->update($data);
        if ($tags !== null) {
            $link->tags()->sync($this->syncTags($request->user(), $tags));
        }

        return redirect()->back();
    }

    public function destroy(Request $request, string $id)
    {
        LibraryLink::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return redirect('/library');
    }

    public function refresh(Request $request, string $id)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($id);
        $link->update(['metadata_status' => 'pending']);
        FetchLinkMetadata::dispatch($link->id);

        return redirect()->back();
    }

    public function preserve(Request $request, string $id)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($id);
        $ent = app(EntitlementService::class)->getEntitlement($request->user());
        $allowed = (array) ($ent['limits']['snapshot_kinds'] ?? ['html']);
        if (! in_array('html', $allowed, true)) {
            return redirect()->back()->withErrors(['preserve' => 'Snapshots not included in your plan. Upgrade en '.config('library.upgrade_url')]);
        }
        $link->update(['snapshot_status' => 'pending']);
        SnapshotPage::dispatch($link->id, ['html']);

        return redirect()->back();
    }

    public function storeHighlight(Request $request, string $id)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate(['quote' => 'required|string|max:5000', 'note' => 'nullable|string|max:5000']);
        LibraryHighlight::create(['library_link_id' => $link->id, 'user_id' => $request->user()->id, 'quote' => $data['quote'], 'note' => $data['note'] ?? null]);

        return redirect()->back();
    }

    public function destroyHighlight(Request $request, string $id, int $hid)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($id);
        $link->highlights()->where('id', $hid)->delete();

        return redirect()->back();
    }

    public function storeShare(Request $request, string $id)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($id);
        LibraryShare::create(['library_link_id' => $link->id, 'token' => Str::random(32)]);

        return redirect()->back();
    }

    public function destroyShare(Request $request, string $id, int $sid)
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($id);
        $link->shares()->where('id', $sid)->delete();

        return redirect()->back();
    }

    /** @return array<int> */
    private function syncTags($user, string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $name) {
            $name = trim(mb_strtolower($name));
            if ($name === '') {
                continue;
            }
            $ids[] = LibraryTag::firstOrCreate(['user_id' => $user->id, 'name' => mb_substr($name, 0, 64)])->id;
        }

        return array_unique($ids);
    }
}
