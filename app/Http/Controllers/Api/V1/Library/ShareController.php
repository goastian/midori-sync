<?php

namespace App\Http\Controllers\Api\V1\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\LibraryLink;
use App\Models\Library\LibraryShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShareController extends Controller
{
    public function store(Request $request, string $linkId): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($linkId);
        $data = $request->validate(['expires_at' => 'nullable|date']);
        $share = LibraryShare::create([
            'library_link_id' => $link->id,
            'token' => Str::random(32),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json($share, 201);
    }

    public function destroy(Request $request, string $linkId, int $id): JsonResponse
    {
        $link = LibraryLink::where('user_id', $request->user()->id)->findOrFail($linkId);
        $link->shares()->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    public function public(string $token): JsonResponse
    {
        $share = LibraryShare::where('token', $token)->first();
        if (! $share || ($share->expires_at && $share->expires_at->isPast())) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $link = LibraryLink::with(['tags', 'highlights'])->find($share->library_link_id);
        if (! $link) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'title' => $link->title,
            'url' => $link->url,
            'description' => $link->description,
            'tags' => $link->tags->pluck('name'),
            'readability_html' => $link->readability_html,
        ]);
    }
}
