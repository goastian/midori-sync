<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SyncSession;
use App\Services\SyncAuthService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function __construct(private SyncAuthService $auth) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        $status = $request->query('status', 'all'); // all|active|expired
        if (! in_array($status, ['all', 'active', 'expired'], true)) {
            $status = 'all';
        }

        $query = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $base = SyncSession::where('user_id', $user->id)
            ->with('device')
            ->orderByDesc('created_at');

        if ($status === 'active') {
            $base->authorized();
        } elseif ($status === 'expired') {
            $base->inactive();
        }

        if ($query !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
            $base->where(function ($q) use ($like) {
                $q->where('ip_address', 'like', $like)
                    ->orWhere('user_agent', 'like', $like)
                    ->orWhereHas('device', function ($d) use ($like) {
                        $d->where('name', 'like', $like)
                            ->orWhere('device_id', 'like', $like);
                    });
            });
        }

        $paginator = $base->paginate($perPage)->withQueryString();

        $sessions = collect($paginator->items())->map(function (SyncSession $s) {
            $isActive = $s->authorizationIsActive();

            return [
                'id' => $s->id,
                'device' => $s->device ? [
                    'device_id' => $s->device->device_id,
                    'name' => $s->device->name,
                    'type' => $s->device->type,
                ] : null,
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'created_at' => $s->created_at?->toIso8601String(),
                'last_used_at' => $s->last_used_at?->toIso8601String(),
                'expires_at' => $s->expires_at?->toIso8601String(),
                'refresh_expires_at' => $s->refresh_expires_at?->toIso8601String(),
                'active' => $isActive,
            ];
        })->values();

        // Aggregate stats over the user's full history (not the current page).
        $activeTokenCount = SyncSession::where('user_id', $user->id)
            ->authorized()
            ->count();
        $expiredCount = SyncSession::where('user_id', $user->id)
            ->inactive()
            ->count();

        $recentLogins = SyncSession::where('user_id', $user->id)
            ->with('device')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (SyncSession $s) => [
                'created_at' => $s->created_at?->toIso8601String(),
                'ip_address' => $s->ip_address,
                'user_agent' => $s->user_agent,
                'device' => $s->device ? [
                    'device_id' => $s->device->device_id,
                    'name' => $s->device->name,
                    'type' => $s->device->type,
                ] : null,
            ]);

        return Inertia::render('Audit/Index', [
            'sessions' => $sessions,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'links' => $paginator->linkCollection()->toArray(),
            ],
            'filters' => [
                'status' => $status,
                'q' => $query,
                'per_page' => $perPage,
            ],
            'recentLogins' => $recentLogins,
            'activeTokenCount' => $activeTokenCount,
            'expiredCount' => $expiredCount,
            'currentWebSessionId' => $currentSessionId,
        ]);
    }

    public function revoke(Request $request, string $id)
    {
        $deleted = $this->auth->revokeSession($request->user()->id, $id);

        if ($deleted) {
            SecurityLog::info(SecurityLog::EVENT_TOKEN_REVOKED, [
                'session_id' => $id,
                'flow' => 'audit_ui',
            ], $request);
        }

        return redirect()->back();
    }

    public function revokeAll(Request $request)
    {
        $count = $this->auth->revokeAllForUser($request->user()->id);

        SecurityLog::warning(SecurityLog::EVENT_TOKEN_REVOKED_BULK, [
            'count' => (int) $count,
            'flow' => 'audit_ui',
        ], $request);

        return redirect()->back();
    }
}
