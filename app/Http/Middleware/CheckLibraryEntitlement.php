<?php

namespace App\Http\Middleware;

use App\Models\Library\LibraryLink;
use App\Models\Library\LibrarySnapshot;
use App\Services\Library\EntitlementService;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckLibraryEntitlement
{
    public function __construct(private EntitlementService $entitlements) {}

    /**
     * @param  string  $ability  links|snapshots
     */
    public function handle(Request $request, Closure $next, string $ability = 'links'): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            return $next($request);
        }
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $ent = $this->entitlements->getEntitlement($user);
        $limits = $ent['limits'] ?? [];
        $upgrade = rtrim((string) config('library.upgrade_url'), '/');

        if ($ability === 'links' && $request->isMethod('POST')) {
            $max = $limits['max_links'] ?? null;
            if (! EntitlementService::unlimited($max)) {
                $used = LibraryLink::where('user_id', $user->id)->count();
                if ($used >= (int) $max) {
                    SecurityLog::warning('library.plan_limit', ['user_id' => $user->id, 'plan' => $ent['plan'] ?? 'free', 'used' => $used, 'limit' => $max], $request);

                    return response()->json([
                        'error' => 'Link limit reached for your plan',
                        'code' => 'plan_limit',
                        'limit' => (int) $max,
                        'used' => $used,
                        'upgrade_url' => $upgrade,
                    ], 402);
                }
            }
        }

        if ($ability === 'snapshots') {
            $max = $limits['max_snapshots'] ?? 0;
            if (! EntitlementService::unlimited($max)) {
                $used = LibrarySnapshot::join('library_links', 'library_links.id', '=', 'library_snapshots.library_link_id')
                    ->where('library_links.user_id', $user->id)
                    ->count();
                if ($used >= (int) $max) {
                    return response()->json([
                        'error' => 'Snapshot limit reached for your plan',
                        'code' => 'plan_limit',
                        'limit' => (int) $max,
                        'used' => $used,
                        'upgrade_url' => $upgrade,
                    ], 402);
                }
            }
            $kinds = $request->input('kinds', ['html']);
            $allowed = $limits['snapshot_kinds'] ?? ['html'];
            foreach ((array) $kinds as $k) {
                if (! in_array($k, (array) $allowed, true)) {
                    return response()->json([
                        'error' => "Snapshot kind '{$k}' not included in your plan",
                        'code' => 'plan_limit',
                        'allowed_kinds' => array_values((array) $allowed),
                        'upgrade_url' => $upgrade,
                    ], 402);
                }
            }
        }

        return $next($request);
    }
}
