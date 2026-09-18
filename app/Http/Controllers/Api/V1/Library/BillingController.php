<?php

namespace App\Http\Controllers\Api\V1\Library;

use App\Http\Controllers\Controller;
use App\Jobs\Library\RefreshEntitlement;
use App\Models\Library\BillingCache;
use App\Models\Library\BillingEvent;
use App\Models\Library\LibraryLink;
use App\Models\User;
use App\Services\Library\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BillingController extends Controller
{
    public function entitlement(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $ent = $entitlements->getEntitlement($request->user());
        $usedLinks = LibraryLink::where('user_id', $request->user()->id)->count();

        return response()->json([
            ...$ent,
            'used' => ['links' => $usedLinks],
            'upgrade_url' => config('library.upgrade_url'),
            'billing_enabled' => config('library.billing_enabled'),
        ]);
    }

    public function upgrade(Request $request)
    {
        $user = $request->user();
        $base = rtrim((string) config('library.upgrade_url'), '/');

        return redirect()->away($base.'&uid='.urlencode((string) $user->authentik_id).'&back='.urlencode(config('app.url').'/library/billing/return'));
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('library.payments_webhook_secret');
        $sig = $request->header('X-Payments-Signature', '');
        $eventId = $request->header('X-Payments-Event-Id', (string) $request->input('event_id', ''));
        $calc = hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($calc, (string) $sig)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }
        if ($eventId !== '' && BillingEvent::where('event_id', $eventId)->exists()) {
            return response()->json(['deduped' => true]);
        }
        $type = (string) $request->header('X-Payments-Event', $request->input('type', 'unknown'));
        if ($eventId !== '') {
            BillingEvent::create(['event_id' => $eventId, 'type' => $type, 'payload' => $request->all()]);
        }
        $authentikId = $request->input('authentik_id');
        $user = $authentikId ? User::where('authentik_id', $authentikId)->first() : null;
        if ($user) {
            $plan = $request->input('plan', 'free');
            $status = $request->input('status', 'active');
            $limits = $request->input('limits', config('library.free_limits'));
            BillingCache::updateOrCreate(['user_id' => $user->id],
                ['plan' => $plan, 'status' => $status, 'limits' => $limits, 'synced_at' => now()]);
            Cache::forget("ent:{$user->id}");
            RefreshEntitlement::dispatch($user->id);
        }

        return response()->json(['ok' => true]);
    }
}
