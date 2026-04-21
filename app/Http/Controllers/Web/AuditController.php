<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\SyncSession;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $currentSessionId = $request->session()->getId();

        $sessions = SyncSession::where('user_id', $user->id)
            ->with('device')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (SyncSession $s) {
                $isActive = $s->expires_at?->isFuture() ?? false;
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
                    'active' => $isActive,
                ];
            });

        $recentLogins = $sessions
            ->take(10)
            ->map(fn ($s) => [
                'created_at' => $s['created_at'],
                'ip_address' => $s['ip_address'],
                'user_agent' => $s['user_agent'],
                'device' => $s['device'],
            ])
            ->values();

        $activeTokenCount = $sessions->where('active', true)->count();

        return Inertia::render('Audit/Index', [
            'sessions' => $sessions,
            'recentLogins' => $recentLogins,
            'activeTokenCount' => $activeTokenCount,
            'currentWebSessionId' => $currentSessionId,
        ]);
    }

    public function revoke(Request $request, string $id)
    {
        SyncSession::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->delete();

        return redirect()->back();
    }

    public function revokeAll(Request $request)
    {
        SyncSession::where('user_id', $request->user()->id)->delete();

        return redirect()->back();
    }
}
