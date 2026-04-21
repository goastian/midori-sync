<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCryptoKeyRequest;
use App\Models\CryptoKeyBundle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CryptoKeyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $bundle = $request->user()->cryptoKeyBundle;

        if (!$bundle) {
            return response()->json(['error' => 'No key bundle found'], 404);
        }

        return response()->json([
            'encrypted_bundle' => $bundle->encrypted_bundle,
            'version' => $bundle->version,
            'updated_at' => $bundle->updated_at->toIso8601String(),
        ]);
    }

    public function store(StoreCryptoKeyRequest $request): JsonResponse
    {
        $bundle = CryptoKeyBundle::where('user_id', $request->user()->id)->first();

        if ($bundle) {
            $bundle->update([
                'encrypted_bundle' => $request->input('encrypted_bundle'),
                'version' => $bundle->version + 1,
            ]);
        } else {
            $bundle = CryptoKeyBundle::create([
                'user_id' => $request->user()->id,
                'encrypted_bundle' => $request->input('encrypted_bundle'),
                'version' => 1,
            ]);
        }

        return response()->json([
            'encrypted_bundle' => $bundle->encrypted_bundle,
            'version' => $bundle->version,
            'updated_at' => $bundle->updated_at->toIso8601String(),
        ], 201);
    }
}
