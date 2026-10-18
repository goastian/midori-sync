<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Record;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    private const TILES = [
        ['name' => 'bookmarks', 'label' => 'Bookmarks', 'browsable' => true],
        ['name' => 'history', 'label' => 'History', 'browsable' => true],
        ['name' => 'tabs', 'label' => 'Open tabs', 'browsable' => true, 'caption' => 'devices sharing tabs'],
        ['name' => 'devices', 'label' => 'Devices', 'browsable' => true, 'href' => '/devices'],
        ['name' => 'passwords', 'label' => 'Passwords', 'browsable' => false],
        ['name' => 'browser-settings', 'label' => 'Preferences', 'browsable' => false],
        ['name' => 'form-history', 'label' => 'Form data', 'browsable' => false],
        ['name' => 'addresses', 'label' => 'Addresses', 'browsable' => false],
    ];

    public function __invoke(Request $request)
    {
        $user = $request->user();
        $counts = Record::query()
            ->active()
            ->where('user_id', $user->id)
            ->join('collections', 'collections.id', '=', 'records.collection_id')
            ->groupBy('collections.name')
            ->selectRaw('collections.name as name, count(*) as total')
            ->pluck('total', 'name');
        $deviceCount = $user->devices()->count();

        $tiles = array_map(fn (array $tile) => [
            'name' => $tile['name'],
            'label' => $tile['label'],
            'caption' => $tile['caption'] ?? null,
            'browsable' => $tile['browsable'],
            'href' => $tile['href'] ?? ($tile['browsable'] ? '/collections/'.$tile['name'] : null),
            'count' => $tile['name'] === 'devices' ? $deviceCount : (int) ($counts[$tile['name']] ?? 0),
        ], self::TILES);

        return Inertia::render('Dashboard', ['tiles' => $tiles]);
    }
}
