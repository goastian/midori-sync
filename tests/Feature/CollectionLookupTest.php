<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\User;
use App\Services\SyncStorageService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CollectionLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_ignores_stale_serialized_cache_entries(): void
    {
        $this->seed(CollectionSeeder::class);
        Cache::put('collection:by_name:bookmarks', (object) ['id' => -1], 3600);

        $collection = Collection::findByName('bookmarks');

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame('bookmarks', $collection->name);
        $this->assertGreaterThan(0, $collection->id);
    }

    public function test_workspace_collection_is_available_without_seeded_rows(): void
    {
        Collection::where('name', 'workspaces')->delete();
        Collection::forgetByName('workspaces');
        $user = User::factory()->create();
        $this->assertSame([], app(SyncStorageService::class)->getRecords($user->id, 'workspaces'));
        $this->assertDatabaseHas('collections', [
            'name' => 'workspaces', 'description' => 'Encrypted workspace names and icons',
        ]);
    }
}
