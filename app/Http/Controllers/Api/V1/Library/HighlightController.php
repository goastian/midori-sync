<?php

namespace App\Http\Controllers\Api\V1\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\LibraryHighlight;
use App\Models\Library\LibraryLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HighlightController extends Controller
{
    public function index(Request $request, string $linkId): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($linkId);

        return response()->json($link->highlights()->orderByDesc('created_at')->get());
    }

    public function store(Request $request, string $linkId): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($linkId);
        $data = $request->validate([
            'quote' => 'required|string|max:5000',
            'note' => 'nullable|string|max:5000',
            'color' => 'nullable|string|max:16',
            'anchor' => 'nullable|array',
        ]);
        $h = LibraryHighlight::create([
            'library_link_id' => $link->id,
            'user_id' => $request->user()->id,
            ...$data,
        ]);

        return response()->json($h, 201);
    }

    public function destroy(Request $request, string $linkId, int $id): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($linkId);
        $link->highlights()->where('id', $id)->delete();

        return response()->json(null, 204);
    }
}
