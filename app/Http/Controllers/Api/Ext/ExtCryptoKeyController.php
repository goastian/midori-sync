<?php

namespace App\Http\Controllers\Api\Ext;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Ext\StoreExtCryptoKeyRequest;
use App\Models\CryptoKeyBundle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtCryptoKeyController extends Controller
{
    /**
     * GET /api/ext/crypto/keys
     *
     * Returns the stored encryption key (mapped from CryptoKeyBundle).
     */
    public function show(Request $request): JsonResponse
    {
        $bundle = $request->user()->cryptoKeyBundle;

        if (!$bundle) {
            return response()->json(['error' => 'No encryption key found'], 404);
        }

        return response()->json([
            'encryption_key' => $bundle->encrypted_bundle,
            'version' => $bundle->version,
        ]);
    }

    /**
     * POST /api/ext/crypto/keys
     *
     * Store or update the encryption key.
     * The extension sends {encryption_key: "base64..."}.
     */
    public function store(StoreExtCryptoKeyRequest $request): JsonResponse
    {
        $bundle = CryptoKeyBundle::where('user_id', $request->user()->id)->first();

        if ($bundle) {
            $bundle->update([
                'encrypted_bundle' => $request->input('encryption_key'),
                'version' => $bundle->version + 1,
            ]);
        } else {
            $bundle = CryptoKeyBundle::create([
                'user_id' => $request->user()->id,
                'encrypted_bundle' => $request->input('encryption_key'),
                'version' => 1,
            ]);
        }

        return response()->json([
            'encryption_key' => $bundle->encrypted_bundle,
            'version' => $bundle->version,
        ], 201);
    }
}
