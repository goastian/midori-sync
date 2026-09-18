<?php

namespace App\Http\Controllers\Api\V1\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\LibraryCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LibraryCollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            LibraryCollection::where('user_id', $request->user()->id)->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'parent_id' => 'nullable|integer|exists:library_collections,id',
            'is_public' => 'nullable|boolean',
        ]);
        if (! empty($data['parent_id'])) {
            $owned = LibraryCollection::where('id', $data['parent_id'])->where('user_id', $request->user()->id)->exists();
            if (! $owned) {
                return response()->json(['error' => 'Parent not found'], 404);
            }
        }
        $col = LibraryCollection::create([
            'user_id' => $request->user()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_public' => $data['is_public'] ?? false,
            'public_slug' => ($data['is_public'] ?? false) ? Str::random(12) : null,
        ]);

        return response()->json($col, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $col = LibraryCollection::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'parent_id' => 'nullable|integer|exists:library_collections,id',
            'is_public' => 'sometimes|boolean',
        ]);
        if (! empty($data['is_public']) && ! $col->public_slug) {
            $data['public_slug'] = Str::random(12);
        }
        if (array_key_exists('is_public', $data) && $data['is_public'] === false) {
            $data['public_slug'] = null;
        }
        $col->update($data);

        return response()->json($col);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        LibraryCollection::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
