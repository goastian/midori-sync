<?php

namespace App\Services;

use App\Models\User;

/**
 * TokenServer service — bridges Authentik JWT authentication to Hawk credentials
 * for the Firefox Sync 1.5 Storage API.
 *
 * This replaces Mozilla's TokenServer, accepting Authentik OAuth tokens
 * instead of Firefox Account BrowserID assertions.
 */
class TokenServerService
{
    /**
     * Create a new TokenServer service instance.
     */
    public function __construct(
        private readonly HawkAuthService $hawkService,
    ) {}

    /**
     * Generate a Sync token response for an authenticated user.
     *
     * Returns the same format as Mozilla's TokenServer response:
     * https://mozilla-services.readthedocs.io/en/latest/token/apis.html
     *
     * @return array<string, mixed>
     */
    public function generateSyncToken(User $user): array
    {
        $hawk = $this->hawkService->generateToken($user);
        $duration = (int) config('services.sync.hawk_token_duration', 3600);
        $apiEndpoint = rtrim(config('app.url'), '/') . '/api/1.5/' . $user->id;

        return [
            'id' => $hawk['id'],
            'key' => $hawk['key'],
            'uid' => $user->id,
            'api_endpoint' => $apiEndpoint,
            'duration' => $duration,
            'hashalg' => 'sha256',
            'hashed_fxa_uid' => hash('sha256', $user->authentik_id),
            'node_type' => 'midori-sync',
        ];
    }
}
