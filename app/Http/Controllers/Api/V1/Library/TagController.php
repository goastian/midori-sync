<?php

namespace App\Http\Controllers\Api\V1\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\LibraryTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            LibraryTag::where('user_id', $request->user()->id)->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:64', 'color' => 'nullable|string|max:16']);
        $tag = LibraryTag::firstOrCreate(
            ['user_id' => $request->user()->id, 'name' => trim(mb_strtolower($data['name']))],
            ['color' => $data['color'] ?? null]
        );

        return response()->json($tag, 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tag = LibraryTag::where('user_id', $request->user()->id)->findOrFail($id);
        $tag->delete();

        return response()->json(null, 204);
    }
}
