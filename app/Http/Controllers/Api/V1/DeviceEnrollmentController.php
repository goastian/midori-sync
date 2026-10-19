<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncProtocolException;
use App\Http\Controllers\Controller;
use App\Models\DeviceEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class DeviceEnrollmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:128', $this->publicKeyRule()],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $session = $request->input('sync_session');

        $enrollment = DB::transaction(function () use ($user, $session, $data) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            DeviceEnrollment::purgeExpired();
            DeviceEnrollment::where('session_id', $session->id)->delete();
            $live = DeviceEnrollment::where('user_id', $user->id)->where('status', 'pending')
                ->where('expires_at', '>', now())->count();
            if ($live >= DeviceEnrollment::MAX_PENDING) {
                throw new SyncProtocolException('too_many_enrollments', 429);
            }

            return DeviceEnrollment::create([
                'user_id' => $user->id,
                'session_id' => $session->id,
                'device_id' => $session->device_id,
                'public_key' => $data['public_key'],
                'device_name' => $data['device_name'] ?? null,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(DeviceEnrollment::TTL_MINUTES),
                'created_at' => now(),
            ]);
        });

        DB::select("SELECT pg_notify('midori_sync_change', ?)", [(string) $user->id]);

        return response()->json([
            'id' => $enrollment->id,
            'status' => 'pending',
            'expires_at' => $enrollment->expires_at->toISOString(),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $rows = DeviceEnrollment::where('user_id', $request->user()->id)
            ->where('status', 'pending')->where('expires_at', '>', now())
            ->orderBy('created_at')->get()
            ->map(fn (DeviceEnrollment $e) => [
                'id' => $e->id,
                'public_key' => $e->public_key,
                'device_name' => $e->device_name,
                'created_at' => $e->created_at->toISOString(),
                'expires_at' => $e->expires_at->toISOString(),
            ])->values();

        return response()->json(['enrollments' => $rows])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $e = $this->own($request, $id, creator: true);
        $status = $e->status === 'pending' && $e->isExpired() ? 'expired' : $e->status;
        $body = ['id' => $e->id, 'status' => $status, 'expires_at' => $e->expires_at->toISOString()];
        if ($status === 'approved') {
            $body['approver_public_key'] = $e->approver_public_key;
            $body['payload'] = $e->payload;
        }

        return response()->json($body)->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $id): Response
    {
        $data = $request->validate([
            'approver_public_key' => ['required', 'string', 'max:128', $this->publicKeyRule()],
            'payload' => ['required', 'string', 'max:16384'],
        ]);

        return $this->resolve($request, $id, [
            'status' => 'approved',
            'approver_public_key' => $data['approver_public_key'],
            'payload' => $data['payload'],
        ]);
    }

    public function deny(Request $request, string $id): Response
    {
        return $this->resolve($request, $id, ['status' => 'denied']);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->own($request, $id, creator: true)->delete();

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function resolve(Request $request, string $id, array $changes): Response
    {
        DB::transaction(function () use ($request, $id, $changes) {
            $e = $this->own($request, $id, lock: true);
            $session = $request->input('sync_session');
            if ($e->session_id === $session->id || ($e->device_id !== null && $e->device_id === $session->device_id)) {
                throw new SyncProtocolException('self_approval_forbidden', 403);
            }
            if ($e->status !== 'pending' || $e->isExpired()) {
                throw new SyncProtocolException('enrollment_not_pending', 409);
            }
            $e->forceFill($changes + ['payload' => null])->save();
        });

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    private function own(Request $request, string $id, bool $creator = false, bool $lock = false): DeviceEnrollment
    {
        $query = DeviceEnrollment::where('user_id', $request->user()->id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new SyncProtocolException('enrollment_not_found', 404);
        }
        $query->whereKey($id);
        if ($creator) {
            $query->where('session_id', $request->input('sync_session')->id);
        }
        $e = $lock ? $query->lockForUpdate()->first() : $query->first();
        if (! $e) {
            throw new SyncProtocolException('enrollment_not_found', 404);
        }

        return $e;
    }

    private function publicKeyRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $valid = is_string($value) && preg_match('/^[A-Za-z0-9+\/_-]{87}={0,1}$/D', $value) === 1;
            if ($valid) {
                $raw = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);
                $valid = $raw !== false && strlen($raw) === 65 && $raw[0] === "\x04";
            }
            if (! $valid) {
                $fail("The {$attribute} must be a base64 or base64url encoded 65-byte uncompressed P-256 point.");
            }
        };
    }
}
