<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Record;
use App\Models\User;
use App\Services\SyncIdentityService;
use Database\Seeders\CollectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WebDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function seedRecords(User $user, string $collection, int $live, int $deleted = 0): void
    {
        $collectionId = Collection::findByName($collection)->id;
        for ($i = 0; $i < $live + $deleted; $i++) {
            Record::create([
                'user_id' => $user->id,
                'collection_id' => $collectionId,
                'record_id' => "{$collection}-{$i}",
                'version' => 1,
                'payload' => 'ciphertext',
                'deleted' => $i >= $live,
                'modified_at' => microtime(true),
            ]);
        }
    }

    public function test_dashboard_shows_only_counts_and_never_credit_cards(): void
    {
        $this->seed(CollectionSeeder::class);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $this->seedRecords($user, 'bookmarks', 3, 2);
        $this->seedRecords($user, 'credit-cards', 4);
        $this->seedRecords($user, 'passwords', 5);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(function (AssertableInertia $page) {
            $page->component('Dashboard', false);
            $tiles = collect($page->toArray()['props']['tiles'])->keyBy('name');
            $this->assertSame(3, $tiles['bookmarks']['count']);
            $this->assertTrue($tiles['bookmarks']['browsable']);
            $this->assertSame(5, $tiles['passwords']['count']);
            $this->assertFalse($tiles['passwords']['browsable']);
            $this->assertNull($tiles['passwords']['href']);
            $this->assertFalse($tiles->has('credit-cards'));
            $this->assertSame('/devices', $tiles['devices']['href']);
        });
    }

    public function test_credit_cards_are_not_reachable_from_the_web(): void
    {
        $this->seed(CollectionSeeder::class);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $this->seedRecords($user, 'credit-cards', 1);

        $this->actingAs($user)->get('/collections/credit-cards')->assertNotFound();
        $this->actingAs($user)->get('/collections/credit-cards/export')->assertNotFound();
        $this->actingAs($user)->delete('/collections/credit-cards')->assertNotFound();
        $this->actingAs($user)->get('/collections')->assertOk()->assertInertia(function (AssertableInertia $page) {
            $names = collect($page->toArray()['props']['collections'])->pluck('name');
            $this->assertFalse($names->contains('credit-cards'));
        });
    }

    public function test_capabilities_announce_the_h3_data_schemas_and_features(): void
    {
        $capabilities = $this->getJson('/api/v1/capabilities')->assertOk()->json();
        foreach (['bookmark_labels', 'history_parts', 'history_visit_removals', 'tab_fragments'] as $feature) {
            $this->assertContains($feature, $capabilities['features']);
        }
        $this->assertSame(2, $capabilities['data_schemas']['bookmarks']);
        $this->assertSame(2, $capabilities['data_schemas']['history']);
        $this->assertSame(2, $capabilities['data_schemas']['tabs']);
        $this->assertArrayNotHasKey('credit-cards-web', $capabilities['data_schemas']);
        $this->assertEqualsCanonicalizing(
            array_keys($capabilities['data_schemas']),
            array_values(array_intersect(array_keys($capabilities['data_schemas']), $capabilities['collections'])),
        );
    }

    public function test_tab_fragments_and_history_parts_are_accepted_as_opaque_records(): void
    {
        $this->seed(CollectionSeeder::class);
        $user = User::factory()->create(['authentik_issuer' => SyncIdentityService::DEVELOPMENT_ISSUER]);
        $device = '0c5f6e2a-1111-4222-8333-444455556666';
        foreach (["{$device}:0", "{$device}:49", $device] as $id) {
            $this->seedRecordId($user, 'tabs', $id);
        }
        $this->assertSame(3, Record::where('user_id', $user->id)->count());
        $this->assertTrue(strlen("{$device}:49") <= 255);
    }

    private function seedRecordId(User $user, string $collection, string $id): void
    {
        Record::create([
            'user_id' => $user->id, 'collection_id' => Collection::findByName($collection)->id, 'record_id' => $id,
            'version' => 1, 'payload' => 'ciphertext', 'deleted' => false, 'modified_at' => microtime(true),
        ]);
    }
}
